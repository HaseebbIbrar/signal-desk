<?php
// 6 Atlas pools (no Permanent/Freelance split), migration of stored maps, the title index (build, resume, pacing,
// no personal data on disk), index-based badges and search, index health.
declare(strict_types=1);

use SignalDesk\AppError;
use SignalDesk\Atlas;
use SignalDesk\AtlasClient;
use SignalDesk\AtlasListsSource;
use SignalDesk\IndexBuilder;
use SignalDesk\JsonStore;
use SignalDesk\ListMap;
use SignalDesk\MockAtlas;
use SignalDesk\Skills;
use SignalDesk\TitleIndex;
use SignalDesk\TtlFileCache;

const T_POOLS = [
    'Cyber Security' => '2cafad06-f51c-4d3c-99ca-277c9d88974b', 'Infrastructure & Cloud' => '126e8a0b-ff78-493e-82bb-a3de5fe5e58a',
    'Defence & Public Sector' => '81661624-57a2-42ec-8252-f9fa509d9cab', 'Software & Architecture' => 'a2c94ee2-6cf0-4b32-a0b4-b994f8183d1a',
    'Leadership & Management' => 'f497ad92-2f02-408d-99f0-f3c51b6ddcf3', 'Data & AI' => '2d5784d0-872e-4bf4-8293-6dba34f179d1',
];

/** A source with storage (map, state, index) and a fake clock shared by the builder and its read pacer. */
function t_pool_source(?MockAtlas $mock = null, ?string $dir = null): array
{
    $dir ??= tmpdir();
    $loaded = ListMap::load($dir, T_DEFAULT_MAP);
    $mock ??= new MockAtlas($loaded['map']);
    $client = new AtlasClient('https://mock.atlas.test', 'k');
    $client->transport = $mock;
    $client->sleep = fn () => null;
    $src = new AtlasListsSource($client, $loaded['map'], new TtlFileCache("$dir/atlas-cache", Atlas::CACHE_TTL), Atlas::DEFAULT_PROFILE_URL,
        "$dir/atlas-list-map.json", "$dir/atlas-state.json", $dir);
    return [$src, $mock, $dir, $client];
}

/** Builder on a fake clock: $clock['t'] (float seconds) advances only when the pacer sleeps. */
function t_builder(AtlasListsSource $src, array &$clock): IndexBuilder
{
    $b = $src->indexBuilder();
    $b->now = function () use (&$clock) { return (int) floor($clock['t']); };
    $b->pacer->now = function () use (&$clock) { return $clock['t']; };
    $b->pacer->sleep = function (int $ms) use (&$clock) { $clock['t'] += $ms / 1000; $clock['sleeps'][] = $ms; };
    return $b;
}

function t_build_index(AtlasListsSource $src): array
{
    $clock = ['t' => 1_900_000_000.0, 'sleeps' => []];
    $r = t_builder($src, $clock)->run(3000);
    TitleIndex::forget();
    return $r;
}

test('Pools: new default map = 6 families, one pool list each (real ids), 46 old lists retired, every old sub-group in one pool', function () {
    $m = json_decode((string) file_get_contents(T_DEFAULT_MAP), true);
    eq(2, $m['version']);
    eq(array_keys(T_POOLS), array_keys($m['families']));
    foreach (T_POOLS as $fam => $id) eq([['id' => $id, 'name' => "Candidates – $fam", 'type' => 'pool']], $m['families'][$fam]['lists']);
    eq(46, count($m['retiredLists']));
    eq(23, count($m['retiredFamilies']));
    foreach (array_unique(array_values($m['retiredFamilies'])) as $pool) ok(isset($m['families'][$pool]), "retired family mapped to unknown pool $pool");
    $seen = [];
    foreach ($m['families'] as $fam => $f) {
        foreach ($f['roleSubGroups'] as $sg) { ok(!isset($seen["sg:$sg"]), "sub-group $sg in two pools"); $seen["sg:$sg"] = $fam; }
        foreach ($f['keywords'] as $k) { ok(!isset($seen["kw:$k"]), "keyword $k in two pools"); $seen["kw:$k"] = $fam; }
    }
    foreach (['A1', 'A2', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'G6'] as $sg) eq('Cyber Security', $seen["sg:$sg"]);
    foreach (['D1', 'D2', 'E1', 'E2', 'G3', 'K3'] as $sg) eq('Infrastructure & Cloud', $seen["sg:$sg"]);
    foreach (['B1', 'G4', 'I2', 'I3', 'I4'] as $sg) eq('Data & AI', $seen["sg:$sg"]);
    foreach (['G1', 'G2'] as $sg) eq('Software & Architecture', $seen["sg:$sg"]);
    foreach (['K6', 'C6', 'F3'] as $sg) eq('Leadership & Management', $seen["sg:$sg"]);
    foreach (['pentester', 'sailpoint', 'ciso', 'nis2', 'dfir'] as $k) eq('Cyber Security', $seen["kw:$k"]);
    foreach (['cyber range', 'research scientist'] as $k) eq('Defence & Public Sector', $seen["kw:$k"]);
    foreach (['.net', 'blazor', 'enterprise architect'] as $k) eq('Software & Architecture', $seen["kw:$k"]);
});

test('Pools: a stored map from before is migrated: your added keywords move to the pool, old lists retired, new families kept, backup written', function () {
    $dir = tmpdir();
    $old = ['version' => 2, 'seniorityWords' => ['senior'], 'ignoredLists' => [['id' => '3531a250-5986-4446-828b-ebcef7545aa5', 'name' => 'To verify – Belgian eligibility']], 'families' => [
        'IAM Specialists' => ['lists' => [['id' => '5259d023-9d9b-48f9-bc4c-c8483edb6ca5', 'name' => 'IAM Specialists – Permanent', 'type' => 'permanent']],
            'keywords' => ['iam', 'saviynt'], 'roleSubGroups' => ['A4'], 'roleGroups' => []],
        'Research & Academia' => ['lists' => [['id' => '27448d03-2a44-4d08-9077-672125d9b687', 'name' => 'Research & Academia – Permanent', 'type' => 'permanent']],
            'keywords' => ['professor', 'post-doc researcher'], 'roleSubGroups' => [], 'roleGroups' => []],
        'SAP Consultants' => ['lists' => [['id' => MockAtlas::uuid('list-SAP Consultants – Permanent'), 'name' => 'SAP Consultants – Permanent', 'type' => 'permanent', 'auto' => true]],
            'keywords' => ['sap consultant'], 'roleSubGroups' => [], 'roleGroups' => [], 'auto' => true, 'reviewed' => true],
    ]];
    file_put_contents("$dir/atlas-list-map.json", json_encode($old));
    $l = ListMap::load($dir, T_DEFAULT_MAP);
    eq(null, $l['error']);
    $m = $l['map'];
    eq('pools-2026-10', $m['mapRevision']);
    eq(array_merge(array_keys(T_POOLS), ['SAP Consultants']), array_keys($m['families']));
    ok(in_array('saviynt', $m['families']['Cyber Security']['keywords'], true), 'user keyword moved to its pool');
    ok(in_array('post-doc researcher', $m['families']['Defence & Public Sector']['keywords'], true));
    eq(1, count(array_keys($m['families']['Cyber Security']['keywords'], 'iam')), 'known keywords not duplicated');
    eq(['Cyber Security'], ListMap::match($m, 'Saviynt Engineer')['families']);
    eq(46, count($m['retiredLists']));
    ok(is_file("$dir/atlas-list-map.before-pools-2026-10.json"), 'backup of the old map');
    eq('pools-2026-10', json_decode((string) file_get_contents("$dir/atlas-list-map.json"), true)['mapRevision'], 'saved, so it migrates once');
    ok(in_array('your keyword "saviynt" moved from "IAM Specialists" to "Cyber Security"', $l['migrated'], true));
    eq([], ListMap::load($dir, T_DEFAULT_MAP)['migrated'], 'second load: nothing to migrate');
});

test('Pools: retired lists that still exist in Atlas never become new families or "missing"', function () {
    [$src, $mock, $dir] = t_pool_source();
    ok(count(array_filter($mock->lists, fn ($l) => str_ends_with($l['name'], '– Permanent'))) > 5, 'mock keeps some old lists');
    eq([], $src->discover(true));
    $view = $src->mapView();
    eq(array_keys(T_POOLS), array_column($view['families'], 'name'));
    eq([false], array_unique(array_merge(...array_map(fn ($f) => array_column($f['lists'], 'missing'), $view['families']))));
    eq(46, count($view['retiredLists']));
});

test('Title index: built from the pools with ONLY the allowed fields; no names, emails, phones, notes or salaries on disk', function () {
    [$src, $mock, $dir] = t_pool_source();
    $r = t_build_index($src);
    eq('done', $r['result']);
    $raw = (string) file_get_contents("$dir/atlas-title-index.json");
    $idx = json_decode($raw, true);
    eq(TitleIndex::FIELDS, $idx['fields']);
    ok(count($idx['entries']) > 100);
    eq(6, count($idx['pools']));
    foreach ($idx['entries'] as $e) eq(TitleIndex::FIELDS, array_keys($e));
    $e = array_values(array_filter($idx['entries'], fn ($e) => $e['title'] === 'Senior Security Architect'))[0];
    eq('security architect', $e['normalisedTitle']);
    eq(['IT Consultant'], $e['pastTitles']);
    ok(in_array('sentinel', $e['skills'], true) && in_array('iso 27001', $e['skills'], true));
    eq([T_POOLS['Cyber Security']], $e['listIds']);
    $state = (string) @file_get_contents("$dir/atlas-index-build.json");
    foreach (['Example Person', 'example.person', '@mail.example', '+32 470', 'Secret note', 'expectedSalary', 'firstName', 'Hands-on with', 'Example Company', 'Example University'] as $pii) {
        ok(!str_contains($raw, $pii), "index contains \"$pii\"");
        ok(!str_contains($state, $pii), "build state contains \"$pii\"");
    }
    $log = json_encode($GLOBALS['__events']);
    ok(!str_contains($log, 'Example Person') && !str_contains($log, '@mail.example'), 'audit log has no personal data');
    eq(['start', 'members', 'done'], array_values(array_unique(array_column(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'atlas_index' && $e['event'] !== 'progress'), 'event'))));
});

test('Title index: resumable: a run that times out carries on next time and ends with the same index', function () {
    [$full] = t_pool_source();
    t_build_index($full);
    $expected = array_column($full->index()->entries, 'personId');
    TitleIndex::forget();

    [$src, $mock, $dir] = t_pool_source();
    $clock = ['t' => 1_900_000_000.0, 'sleeps' => []];
    $runs = 0;
    do {
        $r = t_builder($src, $clock)->run(5); // ~40 reads per run
        $runs++;
        if ($runs === 3) {
            eq('running', $r['result']);
            eq('running', $r['state']);
            $st = JsonStore::read("$dir/atlas-index-build.json", []);
            ok($st['pos'] > 0 || $st['poolPos'] > 0, 'progress saved');
            ok(!is_file("$dir/atlas-title-index.json"), 'no half-built index is published');
        }
    } while ($r['result'] !== 'done' && $runs < 500);
    ok($runs > 5, "needed several runs ($runs)");
    TitleIndex::forget();
    eq($expected, array_column($src->index()->entries, 'personId'));
});

test('Title index: stays under 600 Atlas reads per minute; a 429 pauses the build and it resumes later', function () {
    [$src, $mock] = t_pool_source();
    $clock = ['t' => 1_900_000_000.0, 'sleeps' => []];
    $times = [];
    $limited = 0;
    $src->client()->transport = function (string $url, array $h) use ($mock, &$clock, &$times, &$limited) {
        $times[] = $clock['t'];
        if (count($times) === 50 && !$limited++) return ['status' => 429, 'headers' => [], 'body' => '{"retryAfterSec":120}'];
        return $mock($url, $h);
    };
    $b = t_builder($src, $clock);
    $r = $b->run(3000);
    eq('paused', $r['result'], 'long Atlas wait: stop and keep progress');
    $r = $b->run(3000);
    eq('done', $r['result']);
    sort($times);
    $max = 0;
    for ($i = 0, $j = 0; $i < count($times); $i++) {
        while ($times[$i] - $times[$j] >= 60) $j++;
        $max = max($max, $i - $j + 1);
    }
    ok($max <= 600, "reads in one minute: $max");
    $gaps = [];
    for ($i = 1; $i < count($times); $i++) $gaps[] = $times[$i] - $times[$i - 1];
    ok(min($gaps) >= 0.119, 'at least 0.12 s between reads (500/min)');
    ok(end($times) - $times[0] <= count($times) * 0.121 + 1, 'and no slower than that');
    ok(count(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'atlas_index' && $e['event'] === 'paused')) === 1);
});

test('Title index: cron skips a fresh index; "Rebuild index" at most once an hour; a second runner is told it is busy', function () {
    [$src, $mock, $dir] = t_pool_source();
    $clock = ['t' => 1_900_000_000.0, 'sleeps' => []];
    $b = t_builder($src, $clock);
    eq('done', $b->run(3000)['result']);
    $calls = $mock->calls;
    eq('fresh', $b->run(60)['result']);
    eq($calls, $mock->calls, 'fresh index: no Atlas calls');
    $e = throws(fn () => $b->run(60, true, 'button'), AppError::class, 'less than an hour ago');
    eq('REBUILD_TOO_SOON', $e->errCode);
    $clock['t'] += 3601;
    eq('running', $b->run(1, true, 'button')['result']);
    $lock = fopen("$dir/atlas-index.lock", 'c');
    flock($lock, LOCK_EX);
    eq('busy', $b->run(60)['result']);
    flock($lock, LOCK_UN);
    fclose($lock);
    $clock['t'] += 21 * 3600;
    ok(in_array($b->run(3000)['result'], ['done'], true), 'cron finishes the running build');
});

test('Badges and search with the index: "N matching titles in <pool> (M in pool)", 25 per page, whole pool on request', function () {
    [$src] = t_pool_source();
    t_build_index($src);
    $s = $src->summary('Senior Security Architect (m/f/x)');
    eq(['Cyber Security'], $s['families']);
    eq('ready', $s['index']);
    eq(['security architect'], $s['terms']);
    $pool = $src->index()->inLists([T_POOLS['Cyber Security']]);
    eq(count($pool), $s['m']);
    $want = count(array_filter($pool, fn ($e) => str_contains($e['normalisedTitle'], 'security architect')));
    ok($want > 0 && $want < $s['m']);
    eq($want, $s['n']);
    $r = $src->search('Senior Security Architect', '', '', 1);
    eq('matching', $r['showing']);
    eq($want, count($r['members']));
    foreach ($r['members'] as $m) ok(str_contains(strtolower($m['role']), 'security architect'), $m['role']);
    $all = $src->search('Senior Security Architect', '', '', 1, true);
    eq('pool', $all['showing']);
    eq(AtlasListsSource::PAGE_SIZE, count($all['members']));
    eq((int) ceil($s['m'] / 25), $all['totalPages']);
    // a sub-group match with an unrelated title: the whole title is the search term
    $sub = $src->summary('Bakery Manager', 'A2 - Pentest / Offensive Security');
    eq([['bakery manager'], 0], [$sub['terms'], $sub['n']]);
});

test('Without the index the badge shows the pool count and "building" while a build runs', function () {
    [$src, $mock, $dir] = t_pool_source();
    $s = $src->summary('SOC Analyst');
    eq(['missing', null], [$s['index'], $s['n']]);
    ok($s['m'] > 0);
    $clock = ['t' => 1_900_000_000.0, 'sleeps' => []];
    t_builder($src, $clock)->run(2);
    $s = $src->summary('SOC Analyst');
    eq(['building', null], [$s['index'], $s['n']]);
});

test('Index health: age (WARN over 36 h), entries, fields', function () {
    [$src] = t_pool_source();
    t_build_index($src);
    $built = $src->index()->builtAt;
    $src->now = fn () => $built + 3600;
    $rows = array_column($src->health(), null, 0);
    eq('OK', $rows['Atlas pools found'][1]);
    ok(str_contains($rows['Atlas pools found'][2], '6 found'));
    eq('OK', $rows['Atlas title index age'][1]);
    eq('OK', $rows['Atlas title index entries'][1]);
    eq('OK', $rows['Atlas title index fields'][1]);
    $src->now = fn () => $built + 37 * 3600;
    $rows = array_column($src->health(), null, 0);
    eq('WARN', $rows['Atlas title index age'][1]);
    ok(str_contains($rows['Atlas title index age'][2], 'older than 36 h'));
});

test('Skills: vocabulary hits only, synonyms folded, whole words', function () {
    eq(['entra id', 'kubernetes', 'm365', 'c#', '.net', 'iso 27001'], Skills::extract('Azure AD, k8s, Office 365, C# and ASP.NET; ISO27001 lead'), 'in order of appearance; "Azure AD" is Entra ID, not Azure');
    eq(['azure', 'terraform'], Skills::extract('Azure landing zones with Terraform; go-live in May'), '"go-live" is not the Go language');
    eq([], Skills::extract('Gopher farming in Rustington'));
    eq(['go'], Skills::extract('Back-end in Go'));
});
