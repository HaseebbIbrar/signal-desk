<?php
// Guard + stage 1 (verify/untick/reject) + stage 2 (column M) write rules.
declare(strict_types=1);

use SignalDesk\ConflictError;
use SignalDesk\ExcelDate;
use SignalDesk\ForbiddenWriteError;
use SignalDesk\GraphBackend;
use SignalDesk\Guard;
use SignalDesk\NotFoundError;
use SignalDesk\SheetBusyError;
use SignalDesk\Sheet;
use SignalDesk\ValidationError;

test('Guard: Signals and other tabs are never written', function () {
    throws(fn () => Guard::assert('values', Sheet::SIGNALS, 'A2:R2'), ForbiddenWriteError::class, 'never writes to the "Signals" tab');
    throws(fn () => Guard::assert('values', 'Companies', 'A2'), ForbiddenWriteError::class);
    throws(fn () => Guard::assert('values', 'signals to verify decision make', 'M2'), ForbiddenWriteError::class); // exact name only
});

test('Guard: verify tab allows A:Q/A:R appends and column M only, never S, T or U', function () {
    eq(5, Guard::assert('values', Sheet::VERIFY, 'A5:Q5'));
    eq(5, Guard::assert('values', Sheet::VERIFY, 'A5:R5'));
    eq(5, Guard::assert('values', Sheet::VERIFY, 'M5'));
    foreach (['S5', 'T5', 'U5', 'A5:U5', 'A5:S5', 'R5:U5', 'M5:N5', 'L5'] as $a) {
        throws(fn () => Guard::assert('values', Sheet::VERIFY, $a), ForbiddenWriteError::class);
    }
    throws(fn () => Guard::assert('values', Sheet::VERIFY, 'M1'), ForbiddenWriteError::class, 'header row');
    throws(fn () => Guard::assert('values', Sheet::VERIFY, 'A5:Q6'), ForbiddenWriteError::class, 'single row');
    eq(5, Guard::assert('delete', Sheet::VERIFY, 'A5:U5'));
    throws(fn () => Guard::assert('clear', Sheet::VERIFY, 'A5:U5'), ForbiddenWriteError::class);
});

test('Guard: Decision Makers tab allows A:Q appends, A/C date formats and A:Q clear only', function () {
    eq(9, Guard::assert('values', Sheet::DECISION_MAKERS, 'A9:Q9'));
    eq(9, Guard::assert('format', Sheet::DECISION_MAKERS, 'C9'));
    eq(9, Guard::assert('clear', Sheet::DECISION_MAKERS, 'A9:Q9'));
    throws(fn () => Guard::assert('delete', Sheet::DECISION_MAKERS, 'A9:Q9'), ForbiddenWriteError::class);
    throws(fn () => Guard::assert('values', Sheet::DECISION_MAKERS, 'A9:R9'), ForbiddenWriteError::class);
    throws(fn () => Guard::assert('values', Sheet::DECISION_MAKERS, 'O9'), ForbiddenWriteError::class);
});

test('Graph backend refuses forbidden writes before any network call', function () {
    $calls = 0;
    $g = new GraphBackend('t', 'c', 's', 'd', 'i', '', tmpdir() . '/tok.json');
    $g->transport = function () use (&$calls) { $calls++; return ['status' => 200, 'headers' => [], 'body' => '{}']; };
    throws(fn () => $g->patchValues(Sheet::SIGNALS, 'A2:R2', [[]], 'sid'), ForbiddenWriteError::class);
    throws(fn () => $g->patchValues(Sheet::VERIFY, 'S2', [['x']], 'sid'), ForbiddenWriteError::class);
    throws(fn () => $g->deleteShiftUp(Sheet::SIGNALS, 'A2:Q2', 'sid'), ForbiddenWriteError::class);
    eq(0, $calls);
});

test('Graph backend retries 423/429 then succeeds; never blindly retries a range delete after a 503', function () {
    $statuses = [423, 429, 200];
    $calls = [];
    $g = new GraphBackend('t', 'c', 's', 'd', 'i', '', tmpdir() . '/tok.json');
    $g->sleep = fn () => null;
    $g->transport = function ($method, $url) use (&$statuses, &$calls) {
        if (str_contains($url, 'oauth2')) return ['status' => 200, 'headers' => [], 'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600])];
        $calls[] = $method . ' ' . $url;
        if (str_ends_with($url, '/delete')) return ['status' => 503, 'headers' => [], 'body' => 'busy'];
        $s = array_shift($statuses) ?? 200;
        return ['status' => $s, 'headers' => [], 'body' => $s === 200 ? json_encode(['values' => [['x']]]) : 'locked'];
    };
    eq([['x']], $g->range(Sheet::VERIFY, 'A2:Q2'));
    eq(3, count($calls));
    $calls = [];
    throws(fn () => $g->deleteShiftUp(Sheet::VERIFY, 'A2:U2', 'sid'), SheetBusyError::class);
    eq(1, count($calls));
});

test('Verify (tick): appends A:R when R1 = "Role Sub-Group", after the last A:Q row, nothing else changes', function () {
    [$b, $s] = t_setup();
    $before = $b->grid[Sheet::VERIFY];
    $res = $s->tick('https://jobs.test/acme', 'jp');
    eq(['status' => 'appended', 'row' => 5, 'hasDecisionMaker' => false], $res);
    eq([['patchValues', Sheet::VERIFY, 'A5:R5'], ['patchNumberFormat', Sheet::VERIFY, 'A5']], t_writes($b));
    eq(array_slice($before, 0, 4), array_slice($b->grid[Sheet::VERIFY], 0, 4), 'existing rows untouched');
    $row = t_row($b, Sheet::VERIFY, 5);
    eq(ExcelDate::isoToSerial(T_DAY), $row[0]);
    eq('Acme', $row[2]);
    eq('A2 - Security Architecture', $row[17]);
    eq([null, null, null], array_slice($row, 18, 3), 'S, T, U never written');
    eq('yyyy-mm-dd', $b->formats[Sheet::VERIFY . '!0:5']);
});

test('Verify (tick): verify tab without R1 -> A:Q only plus a warning', function () {
    $v = t_verify_tab();
    $v[0][17] = ''; // R1 missing
    [$b, $s] = t_setup(null, $v);
    $s->tick('https://jobs.test/beta', 'jp');
    eq('A5:Q5', $b->writes[0]['address']);
    eq(null, t_row($b, Sheet::VERIFY, 5)[17]);
    $warn = array_values(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'warning'));
    eq(1, count($warn));
    ok(str_contains($warn[0]['message'], 'R1 is not "Role Sub-Group"'));
});

test('Verify (tick): duplicate protection, formula text kept as text, old-style row with empty R', function () {
    [$b, $s] = t_setup();
    eq('already', $s->tick('https://jobs.test/k-empty/', 'jp')['status']);
    eq([], $b->writes);
    $s->tick('https://jobs.test/gamma', 'jp');
    eq('=HYPERLINK(evil)', t_row($b, Sheet::VERIFY, 5)[14]);
    eq('already', $s->tick('https://jobs.test/gamma?utm_campaign=x', 'jp')['status']);
    $s->tick('https://jobs.test/old', 'jp');
    $old = t_row($b, Sheet::VERIFY, 6);
    eq('B - Data & AI', $old[6]);
    eq('', $old[17]);
});

test('Verify (tick): refuses when the target row has stray content in S:U', function () {
    $v = t_verify_tab();
    $v[] = array_merge(array_fill(0, 18, ''), ['stray note', '', '']); // row 5: S filled only
    [$b, $s] = t_setup(null, $v);
    throws(fn () => $s->tick('https://jobs.test/acme', 'jp'), ConflictError::class, 'Row 5 of the verify tab is not empty');
    eq([], $b->writes);
});

test('Untick: blocked while any of M, S, T or U is filled', function () {
    foreach ([[12, 'https://www.linkedin.com/in/x', 'M'], [18, 'a note', 'S'], [19, 'A Name', 'T'], [20, 'A Title', 'U']] as [$col, $val, $letter]) {
        $v = t_verify_tab();
        $v[1][$col] = $val; // "Kept Empty" row 2
        [$b, $s] = t_setup(null, $v);
        $e = throws(fn () => $s->untick('https://jobs.test/k-empty', 'jp'), ConflictError::class, "column $letter");
        eq('HAS_DECISION_MAKER', $e->errCode);
        eq([], $b->writes, "nothing written when $letter is filled");
    }
});

test('Untick: removes the row (clear A:R, delete A:U shift up) so S:U of later rows stay aligned', function () {
    [$b, $s] = t_setup();
    eq(['status' => 'removed', 'row' => 2], $s->untick('https://jobs.test/k-empty', 'jp'));
    eq([['clearContents', Sheet::VERIFY, 'A2:R2'], ['deleteShiftUp', Sheet::VERIFY, 'A2:U2']], t_writes($b));
    $row2 = t_row($b, Sheet::VERIFY, 2);
    eq('Kept Note', $row2[2]);
    eq('researching', $row2[18], 'S moved up together with its row');
    $row3 = t_row($b, Sheet::VERIFY, 3);
    eq(['Kept Ready', 'met at event', 'Jane Doe', 'CTO'], [$row3[2], $row3[18], $row3[19], $row3[20]]);
    eq(['status' => 'not_ticked', 'row' => null], $s->untick('https://jobs.test/acme', 'jp'));
});

test('Reject: stored app-side keyed by normalised URL, hidden from the list, never written to the workbook', function () {
    [$b, $s, $storage] = t_setup();
    $r = $s->reject('https://jobs.test/beta/?utm_source=x', 'jp@swooscale.test');
    eq('rejected', $r['status']);
    $file = json_decode(file_get_contents("$storage/rejections.json"), true);
    eq(['https://jobs.test/beta'], array_keys($file));
    eq('jp@swooscale.test', $file['https://jobs.test/beta']['user']);
    ok((bool) preg_match('/^\d{4}-\d{2}-\d{2}T/', $file['https://jobs.test/beta']['date']));
    eq([], $b->writes, 'nothing written to the workbook');

    $view = $s->signalsView(T_DAY);
    eq(['Acme', 'Gamma'], array_column($view['rows'], 'company'));
    eq(['signals' => 2, 'ticked' => 0, 'rejected' => 1], $view['counts']);
    $all = $s->signalsView(T_DAY, true);
    eq(3, count($all['rows']));
    eq('jp@swooscale.test', $all['rows'][1]['rejected']['user']);

    eq('restored', $s->unreject('https://jobs.test/beta', 'jp')['status']);
    eq(3, count($s->signalsView(T_DAY)['rows']));
});

test('Reject: refused for a verified signal; verifying a rejected signal un-rejects it', function () {
    [$b, $s] = t_setup();
    $s->tick('https://jobs.test/acme', 'jp');
    throws(fn () => $s->reject('https://jobs.test/acme', 'jp'), ConflictError::class, 'Untick it first');
    $s->reject('https://jobs.test/beta', 'jp');
    $s->tick('https://jobs.test/beta', 'jp');
    eq(0, $s->signalsView(T_DAY)['counts']['rejected']);
    throws(fn () => $s->reject('https://jobs.test/nope', 'jp'), NotFoundError::class);
});

test('LinkedIn save (stage 2): writes column M only, found by Job URL, never S/T/U', function () {
    [$b, $s] = t_setup();
    $res = $s->saveLinkedIn(3, 'https://jobs.test/k-note', ['be.linkedin.com/in/new-person/?trk=1', 'linkedin.com/sales/lead/ABC,NAME'], 'jp');
    eq(3, $res['row']);
    eq("https://www.linkedin.com/in/new-person\nhttps://www.linkedin.com/sales/lead/ABC,NAME", $res['value']);
    eq([['patchValues', Sheet::VERIFY, 'M3']], t_writes($b));
    eq('researching', t_row($b, Sheet::VERIFY, 3)[18], 'S untouched');
    throws(fn () => $s->saveLinkedIn(3, 'https://jobs.test/k-note', ['https://www.linkedin.com/company/acme'], 'jp'), ValidationError::class);
    throws(fn () => $s->saveLinkedIn(3, 'https://jobs.test/k-note', 'nope', 'jp'), ValidationError::class);
    eq(1, count($b->writes));
});
