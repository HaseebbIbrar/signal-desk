<?php
// Atlas list map: tightened keywords, auto-discovery (new list / new family / deleted list), list-name search,
// /lists edits saved to storage, "Refresh from Atlas" rate limit.
declare(strict_types=1);

use SignalDesk\AppError;
use SignalDesk\Atlas;
use SignalDesk\AtlasClient;
use SignalDesk\AtlasListsSource;
use SignalDesk\ListMap;
use SignalDesk\MockAtlas;
use SignalDesk\NotFoundError;
use SignalDesk\TtlFileCache;
use SignalDesk\ValidationError;

const T_DEFAULT_MAP = __DIR__ . '/../private/atlas-list-map.default.json';

/** A source wired like production: map in storage/atlas-list-map.json, state file, cache, clock. */
function t_source(?MockAtlas $mock = null): array
{
    $dir = tmpdir();
    $loaded = ListMap::load($dir, T_DEFAULT_MAP);
    $mock ??= new MockAtlas($loaded['map']);
    $client = new AtlasClient('https://mock.atlas.test', 'k');
    $client->transport = $mock;
    $client->sleep = fn () => null;
    $cache = new TtlFileCache("$dir/atlas-cache", Atlas::CACHE_TTL);
    $src = new AtlasListsSource($client, $loaded['map'], $cache, Atlas::DEFAULT_PROFILE_URL, "$dir/atlas-list-map.json", "$dir/atlas-state.json");
    return [$src, $mock, $dir, $cache];
}

function t_saved_map(string $dir): array
{
    return json_decode((string) file_get_contents("$dir/atlas-list-map.json"), true);
}

test('Atlas map: tightened keywords; Credit Risk Analyst, Financial Auditor, Simulation Engineer -> "No matching list"', function () {
    $map = ListMap::load(tmpdir(), T_DEFAULT_MAP)['map'];
    foreach (['Credit Risk Analyst', 'Financial Auditor', 'Simulation Engineer', 'Compliance Officer (Banking)', 'Identity Theft Investigator'] as $t) {
        eq([], ListMap::match($map, $t)['families'], "$t should not match");
    }
    eq(['GRC, Risk & Compliance'], ListMap::match($map, 'IT Risk Manager (m/f/x)')['families']);
    eq(['GRC, Risk & Compliance'], ListMap::match($map, 'Senior IT Auditor')['families']);
    eq(['IAM Specialists'], ListMap::match($map, 'Identity and Access Management Engineer')['families']);
    eq(['IAM Specialists'], ListMap::match($map, 'Identity Engineer')['families']);
    eq(['Cyber Range & Simulation'], ListMap::match($map, 'Attack Simulation Specialist')['families']);
    eq(['GRC, Risk & Compliance'], ListMap::match($map, 'Information Security Officer')['families'], 'only GRC now (removed from CISO)');
    $ciso = ListMap::load(tmpdir(), T_DEFAULT_MAP)['map']['families']['CISO & Security Leadership']['keywords'];
    ok(!in_array('security officer', $ciso, true));
});

test('Atlas discovery: a new list whose name matches a family is attached to it (by type)', function () {
    [$src, $mock, $dir] = t_source();
    $id = $mock->addList('Security Architects – Contract', 4);
    $changes = $src->discover(true);
    eq(['new list "Security Architects – Contract" added to family "Security Architects"'], $changes);
    $lists = t_saved_map($dir)['families']['Security Architects']['lists'];
    eq(['permanent', 'freelance', 'contract'], array_column($lists, 'type'));
    eq($id, $lists[2]['id']);
    ok($lists[2]['auto']);
    $c = $src->counts(['Security Architects']);
    eq(3, count($c['lists']));
    ok($c['n'] >= 4, 'contract members counted in N');
    $audit = array_values(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'atlas_map_auto'));
    eq(1, count($audit));
});

test('Atlas discovery: a new list with an unknown family creates an auto family (reviewed: false)', function () {
    [$src, $mock, $dir] = t_source();
    $mock->addList('SAP Consultants – Permanent', 5);
    $mock->addList('Microsoft – Power Platform Developers – Freelance', 2);
    $src->discover(true);
    $fam = t_saved_map($dir)['families'];
    eq(['sap consultant'], $fam['SAP Consultants']['keywords']);
    eq([true, false], [$fam['SAP Consultants']['auto'], $fam['SAP Consultants']['reviewed']]);
    eq('permanent', $fam['SAP Consultants']['lists'][0]['type']);
    eq(['power platform developer'], $fam['Microsoft – Power Platform Developers']['keywords']);
    eq(['SAP Consultants'], $src->match('SAP Consultant (m/f/x)')['families']);
    $view = $src->mapView();
    $sap = array_values(array_filter($view['families'], fn ($f) => $f['name'] === 'SAP Consultants'))[0];
    eq([true, false, 5], [$sap['auto'], $sap['reviewed'], $sap['lists'][0]['count']]);
    // the ignored "To verify – Belgian eligibility" list never becomes a family
    ok(!isset($fam['To verify – Belgian eligibility']));
    ok(!isset($fam['To verify']));
});

test('Atlas discovery: a deleted list is marked missing and left out of the counts, without crashing', function () {
    [$src, $mock, $dir] = t_source();
    $before = $src->counts(['IAM Specialists']);
    $freelanceId = '';
    foreach ($mock->lists as $id => $l) if ($l['name'] === 'IAM Specialists – Freelance') $freelanceId = $id;
    unset($mock->lists[$freelanceId]); // deleted in Atlas
    $changes = $src->discover(true);
    eq(['list "IAM Specialists – Freelance" not found in Atlas: marked missing'], $changes);
    $lists = t_saved_map($dir)['families']['IAM Specialists']['lists'];
    eq('missing', $lists[1]['status']);
    $after = $src->counts(['IAM Specialists']); // count cache from before is still valid (6 h): clear it like "Refresh" does
    $GLOBALS['__dummy'] = $after;
    (new TtlFileCache("$dir/atlas-cache", Atlas::CACHE_TTL))->clear();
    $fresh = $src->counts(['IAM Specialists']);
    eq(0, $fresh['f'], 'freelance list dropped from the counts');
    ok($fresh['n'] <= $before['n']);
    eq([true, false], array_column($fresh['lists'], 'found'));
    // the members endpoint 404s for a list deleted after the list cache was filled: still no crash
    $r = $src->search('IAM Specialist', '', '', 1);
    ok($r['counts']['n'] > 0);
    // the list comes back: restored
    $mock->lists[$freelanceId] = ['name' => 'IAM Specialists – Freelance', 'members' => []];
    eq(['list "IAM Specialists – Freelance" is back in Atlas'], $src->discover(true));
});

test('Atlas discovery: at most once an hour (timestamp in storage); a failing Atlas never crashes it', function () {
    [$src, $mock, $dir] = t_source();
    $now = 2_000_000_000;
    // each web request builds a fresh source; the "last discovery" timestamp lives in storage
    $request = function () use ($mock, $dir, &$now) {
        $c = new AtlasClient('https://mock.atlas.test', 'k');
        $c->transport = $mock;
        $s = new AtlasListsSource($c, ListMap::load($dir, T_DEFAULT_MAP)['map'], new TtlFileCache("$dir/atlas-cache", Atlas::CACHE_TTL),
            Atlas::DEFAULT_PROFILE_URL, "$dir/atlas-list-map.json", "$dir/atlas-state.json");
        $s->now = function () use (&$now) { return $now; };
        return $s;
    };
    $request()->match('anything');       // first request: discovery runs (1 call to GET /lists)
    $calls = $mock->calls;
    eq(1, $calls);
    $now += 1800;
    $request()->match('anything');       // 30 min later: no Atlas call
    eq($calls, $mock->calls);
    $now += 1801;
    $request()->match('anything');       // over an hour later: runs again
    eq($calls + 1, $mock->calls);

    $broken = new AtlasClient('https://mock.atlas.test', 'k');
    $broken->transport = fn () => ['status' => 500, 'headers' => [], 'body' => 'boom'];
    $broken->sleep = fn () => null;
    $s = new AtlasListsSource($broken, ListMap::load(tmpdir(), T_DEFAULT_MAP)['map'], new TtlFileCache(tmpdir(), 60));
    eq([], $s->discover(true), 'no crash');
    ok(count(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'atlas_discovery_failed')) === 1);
});

test('Atlas search matches list names directly (Candidates page), badges do not', function () {
    [$src] = t_source();
    $r = $src->search('Academia', '', '', 1);
    eq(['Research & Academia'], $r['families']);
    eq('list name', $r['reason']);
    ok($r['counts']['n'] > 0);
    eq([], $src->match('Academia')['families'], 'badge matching stays on sub-group/keywords');
    $both = $src->search('Founders', '', '', 1);
    eq(['Other / Management & Founders'], $both['families']);
    eq(['Microsoft – Data Engineers'], $src->search('Data Engineers', '', '', 1)['families'], 'plural list names match');
});

test('/lists edit: keywords and Role Sub-Groups saved to storage/atlas-list-map.json, reviewed, audited', function () {
    [$src, $mock, $dir] = t_source();
    $mock->addList('SAP Consultants – Permanent', 5);
    $src->discover(true);
    $r = $src->saveFamily('SAP Consultants', ['SAP Consultant', ' s/4hana ', '', 'sap consultant'], ['H1'], 'jp@swooscale.test');
    eq(['family' => 'SAP Consultants', 'keywords' => ['sap consultant', 's/4hana'], 'roleSubGroups' => ['H1'], 'reviewed' => true], $r);
    $saved = t_saved_map($dir)['families']['SAP Consultants'];
    eq([['sap consultant', 's/4hana'], ['H1'], true], [$saved['keywords'], $saved['roleSubGroups'], $saved['reviewed']]);
    eq(['SAP Consultants'], $src->match('Any title', 'H1 - SAP')['families']);
    $edit = array_values(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'atlas_map_edit'));
    eq(['user' => 'jp@swooscale.test', 'family' => 'SAP Consultants', 'keywords' => 2, 'roleSubGroups' => 1], array_intersect_key($edit[0], array_flip(['user', 'family', 'keywords', 'roleSubGroups'])));
    throws(fn () => $src->saveFamily('Nope', [], [], 'jp'), NotFoundError::class);
    throws(fn () => $src->saveFamily('SAP Consultants', 'x', [], 'jp'), ValidationError::class);
    throws(fn () => $src->saveFamily('SAP Consultants', [str_repeat('a', 101)], [], 'jp'), ValidationError::class, 'too long');
    // the file stays editable by hand: pretty-printed JSON
    ok(str_contains(file_get_contents("$dir/atlas-list-map.json"), "\n    \"families\": {") || str_contains(file_get_contents("$dir/atlas-list-map.json"), "\n  \"families\": {"));
});

test('"Refresh from Atlas": clears count and list caches, re-runs discovery, at most once a minute', function () {
    [$src, $mock, $dir, $cache] = t_source();
    $now = 2_000_000_000;
    $src->now = function () use (&$now) { return $now; };
    $src->counts(['IAM Specialists']);
    ok(count(glob("$dir/atlas-cache/*.json")) >= 2);
    $mock->addList('IAM Specialists – Contract', 2);
    $r = $src->refresh('jp');
    ok($r['cleared'] >= 2);
    eq(['new list "IAM Specialists – Contract" added to family "IAM Specialists"'], $r['changes']);
    $now += 30;
    $e = throws(fn () => $src->refresh('jp'), AppError::class, 'less than a minute ago');
    eq('REFRESH_TOO_SOON', $e->errCode);
    $now += 31;
    eq([], $src->refresh('jp')['changes']);
});

test('Map files from before (version 1: permanent/freelance keys) are upgraded on load', function () {
    $dir = tmpdir();
    file_put_contents("$dir/atlas-list-map.json", json_encode(['families' => ['X Engineers' => [
        'permanent' => ['id' => 'a', 'name' => 'X Engineers – Permanent'], 'freelance' => ['name' => 'X Engineers – Freelance'], 'keywords' => ['x engineer']]]]));
    $l = ListMap::load($dir, T_DEFAULT_MAP);
    eq(null, $l['error']);
    eq([['id' => 'a', 'name' => 'X Engineers – Permanent', 'type' => 'permanent'], ['id' => '', 'name' => 'X Engineers – Freelance', 'type' => 'freelance']],
        $l['map']['families']['X Engineers']['lists']);
    eq(['X Engineers'], ListMap::match($l['map'], 'X Engineer')['families']);
});

test('List-name parsing and auto keywords', function () {
    eq(['Security Architects', 'permanent'], ListMap::parseListName('Security Architects – Permanent'));
    eq(['Microsoft – Data Engineers', 'freelance'], ListMap::parseListName('Microsoft – Data Engineers – Freelance'));
    eq(['SAP Consultants', 'contract'], ListMap::parseListName('SAP Consultants - Contract'));
    eq(['To verify – Belgian eligibility', 'other'], ListMap::parseListName('To verify – Belgian eligibility'));
    eq('sap consultant', ListMap::autoKeyword('SAP Consultants'));
    eq('power platform developer', ListMap::autoKeyword('Microsoft – Power Platform Developers'));
    eq('security', ListMap::singular('security'));
    eq('specialist', ListMap::singular('Specialists'));
    eq('library', ListMap::singular('libraries'));
    eq('analysis', ListMap::singular('analysis'));
});
