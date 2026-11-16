<?php
// Title index cron: one failing person never blocks the build (3 runs, then skipped), transient Atlas errors back off
// and retry in the same run, Atlas being down costs nobody a strike, and the reason is shown (cron line, /health).
declare(strict_types=1);

use SignalDesk\IndexBuilder;
use SignalDesk\JsonStore;
use SignalDesk\TitleIndex;

/** Transport that wraps the mock and lets $fail decide per person request: return a status to fail with, or null. */
function t_idx_failing($mock, callable $fail): callable
{
    return function (string $url, array $h) use ($mock, $fail) {
        if (preg_match('~/api/v1/people/([0-9a-f-]{36})~', $url, $m)) {
            $s = $fail($m[1]);
            if ($s !== null) return ['status' => $s, 'headers' => [], 'body' => '{"error":"boom"}'];
        }
        return $mock($url, $h);
    };
}

/** Person ids of the mock pools, in build order. */
function t_idx_people(): array
{
    [$src] = t_pool_source();
    t_build_index($src);
    return array_column($src->index()->entries, 'personId');
}

test('Title index: a person that always errors is retried in 3 runs, then skipped; the build completes with everyone else', function () {
    $people = t_idx_people();
    $bad = $people[2];
    [$src, $mock, $dir] = t_pool_source();
    $src->client()->transport = t_idx_failing($mock, fn ($pid) => $pid === $bad ? 400 : null);
    $clock = ['t' => 1_900_000_000.0, 'sleeps' => []];
    $b = t_builder($src, $clock);
    $r1 = $b->run(3000);
    eq('running', $r1['result'], 'first run: everyone else done, the failing person waits for the next run');
    eq(count($people), $r1['done']);
    eq(1, $r1['retrying']);
    eq(0, $r1['skipped']);
    eq('Atlas error 400', $r1['lastError']);
    ok(str_contains(IndexBuilder::cronLine($r1, 1_900_000_000), 'error="Atlas error 400"'), 'the reason is in the cron line');
    ok(str_contains(IndexBuilder::cronLine($r1, 1_900_000_000), 'retrying=1'));
    $clock['t'] += 900;
    eq('running', $b->run(3000)['result'], 'second run: one more try');
    $clock['t'] += 900;
    $r3 = $b->run(3000);
    eq('done', $r3['result'], 'third failing run: skipped and the index is finished');
    eq(1, $r3['skipped']);
    TitleIndex::forget();
    $ids = array_column($src->index()->entries, 'personId');
    eq(array_values(array_diff($people, [$bad])), $ids, 'everyone except the failing person is in the index');
    ok(str_contains(IndexBuilder::cronLine($r3, 1_900_000_000), 'skipped=1'));
    ok(count(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'atlas_index' && ($e['event'] ?? '') === 'skipped' && $e['personId'] === $bad)) === 1, 'skip is audited (id only)');
    $health = array_column($src->health(), null, 0);
    eq('WARN', $health['Atlas title index skipped'][1]);
    ok(str_contains($health['Atlas title index skipped'][2], '1 people skipped'));
    ok(str_contains($health['Atlas title index: last error'][2], 'Atlas error 400'), 'health shows the last error');
});

test('Title index: transient Atlas errors (5xx, no answer) back off and retry in the same run', function () {
    $people = t_idx_people();
    $flaky = $people[1];
    [$src, $mock] = t_pool_source();
    $n = 0;
    $src->client()->transport = t_idx_failing($mock, function ($pid) use ($flaky, &$n) { return $pid === $flaky && $n++ < 4 ? 503 : null; });
    $clock = ['t' => 1_900_000_000.0, 'sleeps' => []];
    $r = t_builder($src, $clock)->run(3000);
    eq('done', $r['result'], 'one run, no skip');
    eq(0, $r['skipped']);
    eq(0, $r['retrying']);
    ok(in_array(2000, $clock['sleeps'], true), 'waited 2 s before retrying');
    ok(count(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'atlas_index' && ($e['event'] ?? '') === 'retry')) >= 1);
    TitleIndex::forget();
    ok(in_array($flaky, array_column($src->index()->entries, 'personId'), true), 'the person is indexed after the retry');
});

test('Title index: when Atlas fails for everyone, nobody gets a strike; the run ends with the reason and the build resumes', function () {
    $people = t_idx_people();
    [$src, $mock] = t_pool_source();
    $down = true;
    $src->client()->transport = t_idx_failing($mock, function () use (&$down) { return $down ? 500 : null; });
    $clock = ['t' => 1_900_000_000.0, 'sleeps' => []];
    $b = t_builder($src, $clock);
    $r = $b->run(3000);
    eq('error', $r['result']);
    eq(0, $r['skipped']);
    ok(str_contains($r['lastError'], 'Atlas is failing for every person (Atlas error 500)'), $r['lastError']);
    ok(str_contains(IndexBuilder::cronLine($r, 1_900_000_000), 'error="Atlas is failing for every person (Atlas error 500)"'));
    $down = false;
    for ($i = 0; $i < 3 && ($r = $b->run(3000))['result'] !== 'done'; $i++) $clock['t'] += 900;
    eq('done', $r['result']);
    eq(0, $r['skipped'], 'no one was skipped because of the outage');
    TitleIndex::forget();
    eq(count($people), count($src->index()->entries));
});

test('Title index health: WARN when a build has been running for over 6 h without progress; old stuck builds carry on', function () {
    [$src, $mock, $dir] = t_pool_source();
    $clock = ['t' => 1_900_000_000.0, 'sleeps' => []];
    $b = t_builder($src, $clock);
    eq('running', $b->run(2)['result']);
    $state = JsonStore::read("$dir/atlas-index-build.json", []);
    // like the live state since 23 Oct: written by the old version (no retry keys), with an error and old progress
    unset($state['run'], $state['fails'], $state['retry'], $state['tried'], $state['skipped'], $state['progressAt']);
    $state['lastError'] = 'Atlas error 502';
    $state['startedAt'] = time() - 7 * 3600;
    JsonStore::write("$dir/atlas-index-build.json", $state);
    $h = array_column($src->health(), null, 0);
    eq('WARN', $h['Atlas title index build'][1]);
    ok(str_contains($h['Atlas title index build'][2], 'without progress for 7'), $h['Atlas title index build'][2]);
    ok(str_contains($h['Atlas title index build'][2], 'Atlas error 502'));
    eq('done', $b->run(3000)['result'], 'the old state is picked up and finished');
    $h = array_column($src->health(), null, 0);
    ok(!isset($h['Atlas title index build']), 'no stall warning once done');
});
