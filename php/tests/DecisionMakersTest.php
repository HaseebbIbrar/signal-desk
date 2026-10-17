<?php
// Stage 3: Approve -> "Decision Makers" tab (header check, mapping, duplicates, undo) + verified view statuses.
declare(strict_types=1);

use SignalDesk\ConflictError;
use SignalDesk\ExcelDate;
use SignalDesk\Fixture;
use SignalDesk\MemoryBackend;
use SignalDesk\Service;
use SignalDesk\Sheet;
use SignalDesk\ValidationError;

test('Approve: appends ONE row with the exact Decision Makers mapping', function () {
    [$b, $s, $storage] = t_setup();
    $res = $s->approve('https://jobs.test/k-ready', t_user());
    eq('appended', $res['status']);
    eq(2, $res['row']);
    eq([['patchValues', Sheet::DECISION_MAKERS, 'A2:Q2'], ['patchNumberFormat', Sheet::DECISION_MAKERS, 'A2'], ['patchNumberFormat', Sheet::DECISION_MAKERS, 'C2']], t_writes($b));
    $row = t_row($b, Sheet::DECISION_MAKERS, 2, 17);
    eq([
        ExcelDate::todaySerial('Europe/Brussels'), 'Jonathan Perkins', ExcelDate::isoToSerial('2026-10-01'), 'Kept Ready', 'kept ready.test',
        'Security Architect', 'A - Security', 'A2 - Security Architecture', 'End user', 'Brussels', 'https://jobs.test/k-ready',
        'https://www.linkedin.com/in/jane-doe', 'Jane Doe', 'CTO', 'Ready to push', 4, 'met at event',
    ], $row);
    eq(ExcelDate::serialToIso(ExcelDate::todaySerial('Europe/Brussels')), $res['sentDate']);
    foreach ($b->writes as $w) ok($w['sheet'] !== Sheet::VERIFY, 'approve never writes the verify tab');
});

test('Approve: refuses when the Decision Makers header row does not match, writing nothing', function () {
    $dm = t_dm_tab();
    $dm[0][11] = 'LinkedIn';
    [$b, $s] = t_setup(null, null, $dm);
    $e = throws(fn () => $s->approve('https://jobs.test/k-ready', t_user()), ConflictError::class, 'L1 is "LinkedIn", expected "Decision Maker LinkedIn"');
    eq('DM_HEADER_MISMATCH', $e->errCode);
    eq([], $b->writes);
    [$b2, $s2] = t_setup(null, null, [array_fill(0, 17, '')]);
    throws(fn () => $s2->approve('https://jobs.test/k-ready', t_user()), ConflictError::class, 'header does not match');
    eq([], $b2->writes);
});

test('Approve: requires column M; duplicate protection by Job URL + LinkedIn', function () {
    [$b, $s] = t_setup();
    throws(fn () => $s->approve('https://jobs.test/k-note', t_user()), ValidationError::class, 'column M');
    $s->approve('https://jobs.test/k-ready', t_user());
    $n = count($b->writes);
    $again = $s->approve('https://jobs.test/k-ready/?utm=x', t_user());
    eq('already', $again['status']);
    eq(2, $again['row']);
    eq($n, count($b->writes), 'second approve writes nothing');
    // A different LinkedIn for the same job is a new decision maker -> new row.
    $s->saveLinkedIn('https://jobs.test/k-ready', ['linkedin.com/in/someone-else'], 'jp');
    eq('appended', $s->approve('https://jobs.test/k-ready', t_user())['status']);
    eq(3, Sheet::lastValueRow(Sheet::toRows($b->usedRange(Sheet::DECISION_MAKERS), 17), 17));
});

test('Verified view: waiting / ready / sent with date, S/T/U shown, undo only for the row just added', function () {
    [$b, $s] = t_setup();
    $v = $s->verifiedView();
    $by = array_column($v['rows'], null, 'company');
    eq('waiting', $by['Kept Empty']['status']);
    eq('waiting', $by['Kept Note']['status']);
    eq('researching', $by['Kept Note']['dmNote']);
    eq('ready', $by['Kept Ready']['status']);
    eq(['Jane Doe', 'CTO', 'met at event'], [$by['Kept Ready']['dmName'], $by['Kept Ready']['dmTitle'], $by['Kept Ready']['dmNote']]);
    eq(['waiting' => 2, 'ready' => 1, 'sent' => 0], $v['counts']);

    $s->approve('https://jobs.test/k-ready', t_user());
    $by = array_column($s->verifiedView()['rows'], null, 'company');
    eq('sent', $by['Kept Ready']['status']);
    eq(ExcelDate::serialToIso(ExcelDate::todaySerial('Europe/Brussels')), $by['Kept Ready']['sentDate']);
    ok($by['Kept Ready']['canUndo']);
});

test('Undo approve: only the row the app just appended, while it is still last and unchanged', function () {
    // (a) happy path: clears exactly that row
    [$b, $s] = t_setup();
    $s->approve('https://jobs.test/k-ready', t_user());
    $b->writes = [];
    eq(['status' => 'undone', 'row' => 2], $s->undoApprove('https://jobs.test/k-ready', 'jp'));
    eq([['clearContents', Sheet::DECISION_MAKERS, 'A2:Q2']], t_writes($b));
    eq('ready', array_column($s->verifiedView()['rows'], null, 'company')['Kept Ready']['status']);
    throws(fn () => $s->undoApprove('https://jobs.test/k-ready', 'jp'), ConflictError::class, 'most recently');

    // (b) the row was edited by hand -> refused
    [$b, $s] = t_setup();
    $s->approve('https://jobs.test/k-ready', t_user());
    $b->grid[Sheet::DECISION_MAKERS][1][14] = 'Pushed';
    $b->writes = [];
    throws(fn () => $s->undoApprove('https://jobs.test/k-ready', 'jp'), ConflictError::class, 'has been changed');
    eq([], $b->writes);

    // (c) another row was added below it -> refused
    [$b, $s] = t_setup();
    $s->approve('https://jobs.test/k-ready', t_user());
    $b->grid[Sheet::DECISION_MAKERS][2] = array_fill(0, 17, 'manual row');
    $b->writes = [];
    throws(fn () => $s->undoApprove('https://jobs.test/k-ready', 'jp'), ConflictError::class, 'no longer the last row');
    eq([], $b->writes);
    ok(!array_column($s->verifiedView()['rows'], null, 'company')['Kept Ready']['canUndo']);
});

test('No operation ever writes Signals, verify S/T/U, or any other tab', function () {
    [$b, $s] = t_setup();
    $s->tick('https://jobs.test/acme', 'jp');
    $s->saveLinkedIn('https://jobs.test/acme', ['linkedin.com/in/acme-cto'], 'jp');
    $s->approve('https://jobs.test/acme', t_user());
    $s->undoApprove('https://jobs.test/acme', 'jp');
    $s->saveLinkedIn('https://jobs.test/acme', [], 'jp');
    $s->untick('https://jobs.test/acme', 'jp');
    $s->reject('https://jobs.test/beta', 'jp');
    ok(count($b->writes) > 5);
    foreach ($b->writes as $w) {
        ok($w['sheet'] !== Sheet::SIGNALS, 'Signals written');
        ok(in_array($w['sheet'], [Sheet::VERIFY, Sheet::DECISION_MAKERS], true), 'other tab written');
        if ($w['sheet'] === Sheet::VERIFY && $w['op'] !== 'deleteShiftUp') {
            ok(!preg_match('/^[STU]\d|:[STU]\d/', $w['address']), 'S/T/U written: ' . $w['address']);
        }
    }
});

test('Mock fixture covers all 3 stages', function () {
    $b = new MemoryBackend(Fixture::build(new DateTimeImmutable('2026-10-16')), 'MOCK DATA');
    $s = new Service($b, tmpdir());
    $sig = $s->signalsView();
    eq('2026-10-16', $sig['selectedDate']);
    eq(8, count($sig['rows']));
    $v = $s->verifiedView();
    eq(['waiting' => 2, 'ready' => 2, 'sent' => 1], $v['counts']);
    eq([], $v['warnings']);
});
