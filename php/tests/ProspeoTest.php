<?php
// "In the market" (Prospeo): filter mapping, the daily credit cap, "Already in Atlas" dedupe, reveal only after a
// confirm POST (email by default, mobile separately), the allowlist, mock mode, health, no personal data on disk.
declare(strict_types=1);

use SignalDesk\AppError;
use SignalDesk\Config;
use SignalDesk\ConfigError;
use SignalDesk\CreditLedger;
use SignalDesk\ForbiddenWriteError;
use SignalDesk\Health;
use SignalDesk\JdMatcher;
use SignalDesk\MarketSearch;
use SignalDesk\MockProspeo;
use SignalDesk\ProspeoClient;
use SignalDesk\ProspeoGuard;
use SignalDesk\ValidationError;

/** MarketSearch on MockProspeo + the mock Atlas (for "Already in Atlas"), with its own storage. */
function t_market(int $cap = 200): array
{
    [$src, $atlasMock, $dir] = t_pool_source();
    $mock = new MockProspeo();
    $client = new ProspeoClient('test-key');
    $client->transport = $mock;
    $client->sleep = fn () => null;
    $ledger = new CreditLedger("$dir/prospeo-credits.json", $cap);
    $_SESSION['prospeo_ids'] = [];
    return [new MarketSearch($client, $ledger, $dir, $src, $src->dictionary()), $mock, $ledger, $dir, $atlasMock];
}

function t_req(): array
{
    return JdMatcher::requirements(['role' => 'Security Architect', 'seniority' => 'senior', 'minYears' => 7, 'mustHave' => ['Azure', 'Sentinel', 'ISO 27001', 'NIS2', 'Splunk', 'CISSP'], 'location' => 'Brussels, Belgium']);
}

test('Prospeo filters from the JD: titles + synonyms (SIMILAR), location via suggestions (default Belgium), seniority, years, top 5 must-haves', function () {
    [$m, $mock] = t_market();
    $f = $m->filtersFor(t_req());
    eq('Security Architect', $f['titles'][0]);
    ok(in_array('Cybersecurity Architect', $f['titles'], true) && count($f['titles']) <= 10, 'synonyms from the dictionary, at most 10');
    ok(in_array('Beveiligingsarchitect', $f['titles'], true) && in_array('Architecte Sécurité', $f['titles'], true), 'Dutch and French titles too (Belgian profiles)');
    eq(['Brussels, Belgium'], $f['locations'], 'exact name from search-suggestions');
    eq([['Senior'], 7, 60], [$f['seniority'], $f['yearsMin'], $f['yearsMax']]);
    eq(['Azure', 'Sentinel', 'ISO 27001', 'NIS2', 'Splunk'], $f['keywords']);
    eq(['POST', '/search-suggestions', ['location_search' => 'Brussels, Belgium']], $mock->calls[0]);
    eq(1, count($mock->calls), 'nothing searched (or paid) yet');
    $m->filtersFor(t_req());
    eq(1, count($mock->calls), 'location names cached');
    eq(['Belgium'], $m->filtersFor(JdMatcher::requirements(['role' => 'Data Engineer']))['locations'], 'no location: Belgium');
    eq([['Head', 'Director', 'Manager'], 10], array_values(array_intersect_key($m->filtersFor(JdMatcher::requirements(['role' => 'CISO', 'seniority' => 'head'])), ['seniority' => 1, 'yearsMin' => 1])));

    $api = MarketSearch::toProspeo($f + ['evil' => 'x']);
    eq(['person_job_title', 'person_location_search', 'person_seniority', 'person_year_of_experience', 'person_search'], array_keys($api));
    eq('SIMILAR', $api['person_job_title']['match_mode']);
    eq(['min' => 7, 'max' => 60], $api['person_year_of_experience']);
    eq(['include' => ['Senior']], MarketSearch::toProspeo(['titles' => ['X Y'], 'seniority' => ['Senior', 'Wizard']])['person_seniority'], 'unknown seniority values dropped');
    throws(fn () => MarketSearch::toProspeo(['locations' => ['Belgium']]), ValidationError::class, 'at least one job title or keyword');
});

test('Prospeo search: 25 per page, 1 credit (free repeats cost nothing), fit score "title & company only", "Already in Atlas" via one exact-LinkedIn lookup', function () {
    [$m, $mock, $ledger, $dir, $atlasMock] = t_market();
    $f = $m->filtersFor(t_req());
    $r = $m->search($f, 1, t_req(), 'jp');
    eq(25, count($r['results']));
    eq([1, 3, 60], [$r['page'], $r['totalPages'], $r['total']]);
    eq(['charged' => 1, 'free' => false, 'usedToday' => 1, 'cap' => 200], $r['credits']);
    $c = $r['results'][0];
    eq(['personId', 'name', 'title', 'company', 'location', 'linkedinUrl', 'fit', 'inAtlas'], array_keys($c));
    eq('title & company only', $c['fit']['basis']);
    ok(isset($c['fit']['matched'], $c['fit']['missing']));
    $fits = array_column(array_column($r['results'], 'fit'), 'score');
    $sorted = $fits;
    rsort($sorted);
    eq($sorted, $fits, 'best fit first');
    $dupes = array_values(array_filter($r['results'], fn ($x) => $x['inAtlas']));
    eq(5, count($dupes), 'people whose LinkedIn is already in Atlas are flagged');
    ok(str_starts_with($dupes[0]['inAtlas']['profileUrl'], 'https://my.recruitwithatlas.com/people-v2?profile='));
    eq(1, count($atlasMock->searches), 'one Atlas lookup for the page');
    ok(str_contains($atlasMock->searches[0], 'linkedin.com/in/example-person-005'));
    $again = $m->search($f, 1, t_req(), 'jp');
    eq(['charged' => 0, 'free' => true], array_intersect_key($again['credits'], ['charged' => 1, 'free' => 1]), 'a page Prospeo says is free costs no credit');
    eq(1, $ledger->used());
    eq([], $m->search(['titles' => ['Nothing Matches']], 1, t_req(), 'jp')['results'], 'no results: no credit');
    eq(1, $ledger->used());
    eq(0, count(array_filter($mock->calls, fn ($c) => $c[1] === '/enrich-person')), 'searching never enriches');
});

test('Prospeo daily credit cap (one cap for the whole app): checked before calls; mobile needs 10', function () {
    [$m, $mock, $ledger] = t_market(3);
    $f = $m->filtersFor(t_req());
    $m->search($f, 1, t_req(), 'jp');
    $m->search($f, 2, t_req(), 'jp');
    $pid = array_key_first($_SESSION['prospeo_ids']);
    $m->reveal($pid, 'email', true, 'jp');
    eq(3, $ledger->used());
    $before = count($mock->calls);
    $e = throws(fn () => $m->search($f, 3, t_req(), 'other@swooscale.test'), AppError::class, "cap is reached (3 of 3 used");
    eq('PROSPEO_DAILY_CAP', $e->errCode);
    eq($before, count($mock->calls), 'refused before calling Prospeo');
    [$m2, , $l2] = t_market(200);
    $l2->add(191);
    $m2->search($m2->filtersFor(t_req()), 1, t_req(), 'jp');
    $pid = array_key_first($_SESSION['prospeo_ids']);
    throws(fn () => $m2->reveal($pid, 'mobile', true, 'jp'), AppError::class, 'needs 10');
    eq(192, $l2->used());
    $now = time() + 86400;
    $l2->now = fn () => $now;
    eq(0, $l2->used(), 'resets the next day');
});

test('Reveal contact: only after a confirm POST, only for people in this session\'s results, email by default (1 credit), mobile separately (10)', function () {
    [$m, $mock, $ledger, $dir] = t_market();
    $m->search($m->filtersFor(t_req()), 1, t_req(), 'jp');
    $pid = array_key_first($_SESSION['prospeo_ids']);
    $e = throws(fn () => $m->reveal($pid, 'email', false, 'jp'), AppError::class, 'costs up to 1 credit');
    eq('CONFIRM_REQUIRED', $e->errCode);
    $em = throws(fn () => $m->reveal($pid, 'mobile', false, 'jp'), AppError::class, 'costs up to 10 credits');
    eq('CONFIRM_REQUIRED', $em->errCode);
    eq(0, count(array_filter($mock->calls, fn ($c) => $c[1] === '/enrich-person')), 'nothing revealed without confirming');
    throws(fn () => $m->reveal('mp_notmine00000', 'email', true, 'jp'), AppError::class, 'Search for this person first');
    throws(fn () => $m->reveal('../x', 'email', true, 'jp'), ValidationError::class);
    $r = $m->reveal($pid, 'email', true, 'jp');
    ok(str_ends_with((string) $r['email'], '@example.test'));
    eq([null, 1, true], [$r['mobile'], $r['credits'], $r['found']]);
    $call = array_values(array_filter($mock->calls, fn ($c) => $c[1] === '/enrich-person'))[0];
    eq(['data' => ['person_id' => $pid], 'only_verified_email' => true, 'enrich_mobile' => false], $call[2], 'email only');
    $mob = $m->reveal($pid, 'mobile', true, 'jp');
    ok(str_starts_with((string) $mob['mobile'], '+32 400'));
    eq(10, $mob['credits']);
    eq(12, $ledger->used(), '1 search + 1 email + 10 mobile');
    // nothing about people on disk: results, names, emails and phones are never stored or logged
    foreach (glob("$dir/*") as $f) {
        if (!is_file($f)) continue;
        $raw = (string) file_get_contents($f);
        foreach (['Market Person', 'Market Company', '@example.test', '+32 400', 'linkedin.com/in/market'] as $pii) ok(!str_contains($raw, $pii), basename($f) . " contains \"$pii\"");
    }
    $cache = json_decode((string) file_get_contents("$dir/prospeo-search-cache.json"), true);
    foreach ($cache as $e) eq(['ids', 'total', 't'], array_keys($e), 'search cache: person ids only');
    $log = json_encode($GLOBALS['__events']);
    foreach (['Market Person', '@example.test', '+32 400'] as $pii) ok(!str_contains($log, $pii), "audit log contains $pii");
    $reveals = array_values(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'prospeo_reveal'));
    eq(['email', 1], [$reveals[0]['what'], $reveals[0]['credits']]);
});

test('Prospeo allowlist: only the 4 endpoints; enrich-person only inside a confirmed reveal; key in X-KEY, never logged', function () {
    foreach (['POST /search-suggestions', 'POST /search-person', 'GET /account-information'] as $op) { [$meth, $p] = explode(' ', $op); ProspeoGuard::assert($meth, $p, false); }
    ProspeoGuard::assert('POST', '/enrich-person', true);
    throws(fn () => ProspeoGuard::assert('POST', '/enrich-person', false), ForbiddenWriteError::class, 'confirmed "Reveal contact"');
    foreach (['POST /email-finder', 'POST /domain-search', 'GET /search-person', 'POST /bulk-enrich-person', 'POST /mobile-finder', 'DELETE /search-person'] as $op) {
        [$meth, $p] = explode(' ', $op);
        throws(fn () => ProspeoGuard::assert($meth, $p, true), ForbiddenWriteError::class, 'allowlist');
    }
    $mock = new MockProspeo();
    $c = new ProspeoClient('test-secret-key');
    $c->transport = $mock;
    $seen = [];
    $c->transport = function ($meth, $url, $headers, $body) use ($mock, &$seen) { $seen[] = $headers; return $mock($meth, $url, $headers, $body); };
    $c->call('GET', '/account-information');
    ok(in_array('X-KEY: test-secret-key', $seen[0], true));
    throws(fn () => $c->call('POST', '/enrich-person', ['data' => ['person_id' => 'x']]), ForbiddenWriteError::class);
    ok(!str_contains(json_encode($GLOBALS['__events']), 'test-secret-key'));
    $busy = new ProspeoClient('test-key');
    $n = 0;
    $busy->sleep = fn () => null;
    $busy->transport = function () use (&$n) { $n++; return ['status' => 429, 'headers' => ['x-minute-reset-seconds' => '40'], 'body' => '{"error":true}']; };
    eq('PROSPEO_BUSY', throws(fn () => $busy->call('GET', '/account-information'), AppError::class)->errCode);
    eq(1, $n, 'a long wait is not slept through');
});

test('Prospeo config and health: key set and reachable, credits left (WARN under 1,000), today\'s use; mock refused in production', function () {
    $base = ['APP_ENV' => 'development', 'WORKBOOK_BACKEND' => 'mock', 'APP_ORIGIN' => '', 'TIMEZONE' => '', 'AZURE_TENANT_ID' => '', 'AZURE_CLIENT_ID' => '',
        'AZURE_CLIENT_SECRET' => '', 'GRAPH_DRIVE_ID' => '', 'GRAPH_MASTER_ITEM_ID' => '', 'GRAPH_DEV_ITEM_ID' => '',
        'AUTH_USERS' => 'a@x.test|A|' . base64_encode(password_hash('long enough pass', PASSWORD_BCRYPT, ['cost' => 4])), 'STORAGE_DIR' => tmpdir()];
    eq('WARN', Health::prospeoChecks(Config::validate($base, tmpdir()))[0][1], 'off without a key');
    $mockCfg = Config::validate($base + ['PROSPEO_BACKEND' => 'mock'], tmpdir());
    eq([['Prospeo (In the market)', 'OK'], ['Prospeo API reachable', 'OK'], ['Prospeo credits left', 'OK'], ['Prospeo credits used today', 'OK']],
        array_map(fn ($r) => [$r[0], $r[1]], Health::prospeoChecks($mockCfg)));
    $live = Config::validate($base + ['PROSPEO_API_KEY' => 'test-live-key', 'PROSPEO_DAILY_CREDIT_CAP' => '150'], tmpdir());
    $low = new MockProspeo();
    $low->remaining = 420;
    $client = new ProspeoClient('test-live-key');
    $client->transport = $low;
    $rows = array_column(Health::prospeoChecks($live, $client), null, 0);
    eq(['OK', 'set'], [$rows['PROSPEO_API_KEY'][1], $rows['PROSPEO_API_KEY'][2]]);
    eq('WARN', $rows['Prospeo credits left'][1]);
    ok(str_contains($rows['Prospeo credits used today'][2], '0 of 150'));
    ok(!str_contains(json_encode($rows), 'test-live-key'));
    throws(fn () => Config::validate(['APP_ORIGIN' => 'https://x.test', 'APP_ENV' => 'production', 'WORKBOOK_BACKEND' => 'graph', 'PROSPEO_BACKEND' => 'mock'] + $base, tmpdir()), ConfigError::class, 'PROSPEO_BACKEND=mock');
    throws(fn () => Config::validate($base + ['PROSPEO_DAILY_CREDIT_CAP' => 'many'], tmpdir()), ConfigError::class, 'whole number');
});
