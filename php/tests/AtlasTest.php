<?php
// Candidate Desk (Atlas lists): title normalisation, matching, read-only guard, 429 retry, 6-hour count cache
// (no personal data on disk), mock search, CV link, health.
declare(strict_types=1);

use SignalDesk\Atlas;
use SignalDesk\AtlasClient;
use SignalDesk\AtlasGuard;
use SignalDesk\AtlasListsSource;
use SignalDesk\AtlasRateLimited;
use SignalDesk\ForbiddenWriteError;
use SignalDesk\Health;
use SignalDesk\ListMap;
use SignalDesk\MockAtlas;
use SignalDesk\TtlFileCache;

function t_map(): array
{
    return ListMap::upgrade(json_decode((string) file_get_contents(__DIR__ . '/../private/atlas-list-map.default.json'), true));
}

function t_atlas(?array $map = null, ?callable $transport = null): array
{
    $map ??= t_map();
    $client = new AtlasClient('https://mock.atlas.test', 'test-key');
    $mock = new MockAtlas($map);
    $client->transport = $transport ?? $mock;
    $client->sleep = function (int $ms) { $GLOBALS['__atlasSleeps'][] = $ms; };
    $GLOBALS['__atlasSleeps'] = [];
    $dir = tmpdir();
    $cache = new TtlFileCache("$dir/atlas-cache", Atlas::CACHE_TTL);
    return [new AtlasListsSource($client, $map, $cache), $mock, $cache, $dir, $client];
}

test('Atlas: title normalisation strips gender markers, punctuation and (optionally) seniority words', function () {
    $sen = t_map()['seniorityWords'];
    eq('security architect', ListMap::normaliseTitle('Senior Security Architect (m/f/x)', $sen));
    eq('cloud engineer', ListMap::normaliseTitle('Cloud Engineer (M/V/X)', $sen));
    eq('soc analyst l2', ListMap::normaliseTitle('Junior SOC Analyst L2 – (h/f)', $sen));
    eq('.net developer', ListMap::normaliseTitle('.NET Developer m/f', $sen));
    eq('c# developer', ListMap::normaliseTitle('Medior C# Developer (all genders)', $sen));
    eq('senior security architect', ListMap::normaliseTitle('Senior Security Architect (m/w/d)', $sen, false), 'seniority words are optional');
    eq('', ListMap::normaliseTitle('  (m/f)  ', $sen));
});

test('Atlas: matching uses Role Sub-Group first, then title keywords, else "No matching list"', function () {
    $map = t_map();
    $m = ListMap::match($map, 'Some Unrelated Title', 'A2 - Pentest / Offensive Security', 'A - Security');
    eq(['families' => ['Offensive Security & Pentest'], 'reason' => 'Role Sub-Group'], array_intersect_key($m, ['families' => 1, 'reason' => 1]));
    $m = ListMap::match($map, 'Senior Security Architect (m/f/x)', '', '');
    eq('title keywords', $m['reason']);
    eq(['Microsoft – Security Architects', 'Security Architects'], $m['families']);
    $m = ListMap::match($map, 'CISO', 'Z9 - Something new', '');
    eq(['CISO & Security Leadership'], $m['families'], 'unknown sub-group falls back to keywords');
    eq('none', ListMap::match($map, 'Bakery Manager', '', 'X - Food')['reason']);
    eq([], ListMap::match($map, 'Bakery Manager')['families']);
    // keywords are whole words: "soc" must not match "associate"
    eq([], ListMap::match($map, 'Associate Consultant')['families']);
    // Role Group is the last resort, only when the map lists it
    $map['families']['GRC, Risk & Compliance']['roleGroups'] = ['A - Security'];
    eq(['families' => ['GRC, Risk & Compliance'], 'reason' => 'Role Group'], array_intersect_key(ListMap::match($map, 'Bakery Manager', '', 'A - Security'), ['families' => 1, 'reason' => 1]));
});

test('Atlas: the list map is editable in storage without code changes; a broken file falls back to the default', function () {
    $dir = tmpdir();
    $default = __DIR__ . '/../private/atlas-list-map.default.json';
    $l = ListMap::load($dir, $default);
    ok(is_file("$dir/atlas-list-map.json"), 'default copied to storage on first use');
    eq(null, $l['error']);
    eq(23, count($l['map']['families']));
    $edited = $l['map'];
    $edited['families']['Research & Academia']['keywords'][] = 'bakery';
    file_put_contents("$dir/atlas-list-map.json", json_encode($edited));
    eq(['Research & Academia'], ListMap::match(ListMap::load($dir, $default)['map'], 'Bakery Manager')['families']);
    file_put_contents("$dir/atlas-list-map.json", '{ not json');
    $bad = ListMap::load($dir, $default);
    ok(str_contains((string) $bad['error'], 'not valid JSON'));
    eq(23, count($bad['map']['families']));
});

test('Atlas guard: GET-only allowlist; create/update/delete and off-list endpoints are refused before any request', function () {
    $id = MockAtlas::uuid('x');
    eq('/api/v1/lists', AtlasGuard::assert('GET', '/api/v1/lists'));
    eq('/api/v1/lists/{id}/members', AtlasGuard::assert('GET', "/api/v1/lists/$id/members"));
    eq('/api/v1/people/{id}', AtlasGuard::assert('GET', "/api/v1/people/$id"));
    eq('/api/v1/files/{id}/download', AtlasGuard::assert('GET', "/api/v1/files/$id/download"));
    foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $m) throws(fn () => AtlasGuard::assert($m, '/api/v1/lists'), ForbiddenWriteError::class, 'read-only');
    foreach (['/api/v1/people', '/api/v1/people/search', '/api/v1/projects', "/api/v1/lists/$id/members/remove", '/api/v1/lists/../people', "/api/v1/people/$id/enrich", '/api/v1/lists?x=1'] as $p) {
        throws(fn () => AtlasGuard::assert('GET', $p), ForbiddenWriteError::class, 'allowlist');
    }
    $calls = 0;
    $c = new AtlasClient('https://mock.atlas.test', 'k');
    $c->transport = function () use (&$calls) { $calls++; return ['status' => 200, 'headers' => [], 'body' => '{}']; };
    throws(fn () => $c->get('/api/v1/people'), ForbiddenWriteError::class);
    eq(0, $calls, 'no network call for a refused endpoint');
});

test('Atlas client: 429 waits for retryAfterSec (or RateLimit-Reset) and retries; long waits are not slept through', function () {
    $responses = [
        ['status' => 429, 'headers' => [], 'body' => '{"status":"error","error":"Rate limit exceeded","tier":"read","retryAfterSec":2}'],
        ['status' => 429, 'headers' => ['RateLimit-Reset' => '3'], 'body' => '{}'],
        ['status' => 200, 'headers' => ['RateLimit-Remaining' => '900'], 'body' => '{"status":"success","data":[]}'],
    ];
    $c = new AtlasClient('https://mock.atlas.test', 'k');
    $c->transport = function () use (&$responses) { return array_shift($responses); };
    $sleeps = [];
    $c->sleep = function ($ms) use (&$sleeps) { $sleeps[] = $ms; };
    eq(['status' => 'success', 'data' => []], $c->get('/api/v1/lists'));
    eq([2000, 3000], $sleeps);

    $c2 = new AtlasClient('https://mock.atlas.test', 'k');
    $c2->transport = fn () => ['status' => 429, 'headers' => [], 'body' => '{"retryAfterSec":60}'];
    $slept = [];
    $c2->sleep = function ($ms) use (&$slept) { $slept[] = $ms; };
    $e = throws(fn () => $c2->get('/api/v1/lists'), AtlasRateLimited::class, 'about 60 seconds');
    eq(60, $e->retryAfter);
    eq([], $slept, 'a 60 s wait is reported, not slept through in a web request');
});

test('Atlas: audit log records method, endpoint pattern and status only (no ids, no names)', function () {
    [$src] = t_atlas();
    $src->search('Security Architect', '', '', 1);
    $calls = array_values(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'atlas_call'));
    ok(count($calls) > 3);
    foreach ($calls as $e) {
        eq(['type', 'time', 'method', 'endpoint', 'status', 'ms', 'remaining'], array_keys($e));
        eq('GET', $e['method']);
        ok(!preg_match('/[0-9a-f]{8}-[0-9a-f]{4}/', json_encode($e)), 'no ids in the audit log');
    }
});

test('Atlas mock search: families -> deduplicated N (P / F), 25 per page, profile link, names fetched live', function () {
    [$src, $mock] = t_atlas();
    $r = $src->search('Senior Security Architect (m/f/x)', '', '', 1);
    eq(['Microsoft – Security Architects', 'Security Architects'], $r['families']);
    $c = $r['counts'];
    ok($c['n'] > 0 && $c['n'] <= $c['p'] + $c['f'], 'N is deduplicated across lists');
    ok($c['n'] < $c['p'] + $c['f'], 'someone on both a Permanent and a Freelance list is counted once in N');
    eq(4, count($c['lists']), 'Permanent + Freelance for each of the 2 families');
    ok(count(array_filter($c['lists'], fn ($l) => $l['found'])) === 4, 'all 4 lists found in Atlas');
    ok(count($r['members']) <= AtlasListsSource::PAGE_SIZE);
    $m0 = $r['members'][0];
    ok(str_starts_with($m0['name'], 'Example Person'));
    eq('https://my.recruitwithatlas.com/people-v2?profile=' . $m0['id'], $m0['profileUrl']);
    ok($m0['location'] !== '' && $m0['company'] !== '');
    $ids = [];
    for ($p = 1; $p <= $r['totalPages']; $p++) foreach ($src->search('Security Architect', '', '', $p)['members'] as $m) $ids[] = $m['id'];
    eq($c['n'], count(array_unique($ids)), 'pages cover every member exactly once');
    eq(count($ids), count(array_unique($ids)));
    eq([], $src->search('Bakery Manager', '', '', 1)['families']);
});

test('Atlas: counts cached for 6 hours with no personal data on disk', function () {
    [$src, $mock, $cache, $dir] = t_atlas();
    $now = 1_800_000_000;
    $cache->now = function () use (&$now) { return $now; };
    $families = $src->match('IAM Engineer')['families'];
    $first = $src->counts($families);
    $calls = $mock->calls;
    eq($first, $src->counts($families));
    eq($calls, $mock->calls, 'second call served from the cache');
    $now += 6 * 3600 + 1;
    $src->counts($families);
    ok($mock->calls > $calls, 'refetched after 6 hours');
    // a member page fills the cache too; nothing personal is ever written
    $src->search('IAM Engineer', '', '', 1);
    foreach (glob("$dir/atlas-cache/*.json") as $f) {
        $raw = file_get_contents($f);
        ok(!str_contains($raw, 'personId'), "member ids on disk: $f");
        ok(!str_contains($raw, 'Example Person'), "candidate names on disk: $f");
        ok(!str_contains($raw, 'Example Company'), "candidate companies on disk: $f");
        ok(!str_contains($raw, 'Belgium'), "candidate locations on disk: $f");
    }
});

test('Atlas: CV link only on request, https only; invalid ids refused', function () {
    [$src] = t_atlas();
    $withCv = MockAtlas::uuid('person-3');
    $without = MockAtlas::uuid('person-1');
    ok(str_starts_with((string) $src->cvUrl($withCv), 'https://files.example.test/cv/'));
    eq(null, $src->cvUrl($without));
    throws(fn () => $src->cvUrl('../../etc'), \SignalDesk\ValidationError::class);
});

test('Atlas health: reachable + lists visible (mock); off when no key', function () {
    [$src] = t_atlas();
    $rows = $src->health();
    eq(['Atlas API reachable', 'OK'], array_slice($rows[0], 0, 2));
    eq('OK', $rows[1][1]);
    ok(str_contains($rows[1][2], '46 found'));
    $cfg = ['ATLAS_BACKEND' => '', 'ATLAS_API_KEY' => '', 'STORAGE_DIR' => tmpdir()];
    eq('off', Atlas::mode($cfg));
    eq('WARN', Health::atlasChecks($cfg, __DIR__ . '/../private')[0][1]);
    eq('atlas', Atlas::mode(['ATLAS_API_KEY' => 'k']));
    $mockCfg = ['ATLAS_BACKEND' => 'mock', 'ATLAS_API_KEY' => '', 'STORAGE_DIR' => tmpdir()];
    $h = Health::atlasChecks($mockCfg, __DIR__ . '/../private');
    eq(['Atlas (Candidate Desk)', 'Atlas list map', 'Atlas API reachable', 'Atlas lists in the map'], array_column($h, 0));
    eq([], array_values(array_filter(array_column($h, 1), fn ($s) => $s !== 'OK')));
});

test('Config: ATLAS_BACKEND=mock is refused in production; bad values refused', function () {
    $base = ['APP_ENV' => 'production', 'WORKBOOK_BACKEND' => 'graph', 'APP_ORIGIN' => 'https://x.test', 'TIMEZONE' => '', 'AZURE_TENANT_ID' => 't', 'AZURE_CLIENT_ID' => 'c',
        'AZURE_CLIENT_SECRET' => 's', 'GRAPH_DRIVE_ID' => 'd', 'GRAPH_MASTER_ITEM_ID' => 'm', 'GRAPH_DEV_ITEM_ID' => '',
        'AUTH_USERS' => 'a@x.test|A|' . base64_encode(password_hash('long enough pass', PASSWORD_BCRYPT, ['cost' => 4])), 'STORAGE_DIR' => ''];
    throws(fn () => \SignalDesk\Config::validate(['ATLAS_BACKEND' => 'mock'] + $base, '/p'), \SignalDesk\ConfigError::class, 'ATLAS_BACKEND=mock');
    throws(fn () => \SignalDesk\Config::validate(['ATLAS_BACKEND' => 'yes'] + $base, '/p'), \SignalDesk\ConfigError::class, 'atlas, mock or off');
    throws(fn () => \SignalDesk\Config::validate(['ATLAS_BASE_URL' => 'http://evil.test'] + $base, '/p'), \SignalDesk\ConfigError::class, 'ATLAS_BASE_URL');
    eq('atlas', Atlas::mode(\SignalDesk\Config::validate(['ATLAS_API_KEY' => 'k'] + $base, '/p')));
});
