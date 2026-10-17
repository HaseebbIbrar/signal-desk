<?php
// Stages 2 and 3 with several decision makers per job: one verify row per person, identified by
// row number + Job URL; Approve -> "Decision Makers" (header check, per-person duplicates, undo).
declare(strict_types=1);

use SignalDesk\ConflictError;
use SignalDesk\ExcelDate;
use SignalDesk\Fixture;
use SignalDesk\Guard;
use SignalDesk\MemoryBackend;
use SignalDesk\Service;
use SignalDesk\Sheet;
use SignalDesk\ValidationError;

const MULTI = 'https://jobs.test/multi';

/** Verify tab with the base rows (2-4) plus one job with two decision makers (rows 5 and 6). */
function t_verify_multi(): array
{
    $v = t_verify_tab();
    $v[] = t_verify_row('2026-10-02', 'CHILI publish', MULTI . '?utm_source=feed', 'https://www.linkedin.com/in/person-a', 'first contact', 'Ann A', 'CTO');
    $v[] = t_verify_row('2026-10-02', 'CHILI publish', MULTI, 'https://www.linkedin.com/in/person-b', '', 'Bob B', 'VP Engineering');
    $v[5][13] = 'prior outreach note'; // N on row 6, to prove "add another" leaves N:Q empty
    return $v;
}

function t_cards(Service $s): array
{
    $out = [];
    foreach ($s->verifiedView()['jobs'] as $j) foreach ($j['cards'] as $c) $out[$c['row']] = $c + ['job' => $j['company'], 'jobSent' => $j['sent'], 'jobTotal' => $j['total']];
    return $out;
}

test('Approve: appends ONE row with the exact Decision Makers mapping (Source Row = the card\'s row)', function () {
    [$b, $s] = t_setup();
    $res = $s->approve(4, 'https://jobs.test/k-ready', t_user());
    eq('appended', $res['status']);
    eq(2, $res['row']);
    eq([['patchValues', Sheet::DECISION_MAKERS, 'A2:Q2'], ['patchNumberFormat', Sheet::DECISION_MAKERS, 'A2'], ['patchNumberFormat', Sheet::DECISION_MAKERS, 'C2']], t_writes($b));
    eq([
        ExcelDate::todaySerial('Europe/Brussels'), 'Jonathan Perkins', ExcelDate::isoToSerial('2026-10-01'), 'Kept Ready', 'kept ready.test',
        'Security Architect', 'A - Security', 'A2 - Security Architecture', 'End user', 'Brussels', 'https://jobs.test/k-ready',
        'https://www.linkedin.com/in/jane-doe', 'Jane Doe', 'CTO', 'Ready to push', 4, 'met at event',
    ], t_row($b, Sheet::DECISION_MAKERS, 2, 17));
    foreach ($b->writes as $w) ok($w['sheet'] !== Sheet::VERIFY, 'approve never writes the verify tab');
});

test('Approve: refuses when the Decision Makers header row does not match, writing nothing', function () {
    $dm = t_dm_tab();
    $dm[0][11] = 'LinkedIn';
    [$b, $s] = t_setup(null, null, $dm);
    $e = throws(fn () => $s->approve(4, 'https://jobs.test/k-ready', t_user()), ConflictError::class, 'L1 is "LinkedIn", expected "Decision Maker LinkedIn"');
    eq('DM_HEADER_MISMATCH', $e->errCode);
    eq([], $b->writes);
});

test('Approve: requires column M', function () {
    [$b, $s] = t_setup();
    throws(fn () => $s->approve(3, 'https://jobs.test/k-note', t_user()), ValidationError::class, 'column M');
    eq([], $b->writes);
});

test('Several decision makers for one job: each card is approved separately; the same person can\'t be sent twice', function () {
    [$b, $s] = t_setup(null, t_verify_multi());
    $cards = t_cards($s);
    eq(['ready', 'ready'], [$cards[5]['status'], $cards[6]['status']]);
    eq(2, $cards[5]['jobTotal']);

    eq(['appended', 2], array_values(array_intersect_key($s->approve(5, MULTI, t_user()), ['status' => 1, 'row' => 1])));
    $cards = t_cards($s);
    eq(['sent', 'ready'], [$cards[5]['status'], $cards[6]['status']]);
    eq(1, $cards[5]['jobSent'], 'heading shows 1 of 2 sent');

    eq('appended', $s->approve(6, MULTI, t_user())['status']);
    eq(3, Sheet::lastValueRow(Sheet::toRows($b->usedRange(Sheet::DECISION_MAKERS), 17), 17));
    eq(['https://www.linkedin.com/in/person-a', 'https://www.linkedin.com/in/person-b'], [t_row($b, Sheet::DECISION_MAKERS, 2, 17)[11], t_row($b, Sheet::DECISION_MAKERS, 3, 17)[11]]);
    eq([5, 6], [t_row($b, Sheet::DECISION_MAKERS, 2, 17)[15], t_row($b, Sheet::DECISION_MAKERS, 3, 17)[15]], 'Source Row per person');

    $n = count($b->writes);
    eq('already', $s->approve(5, MULTI, t_user())['status'], 'same person + job is never sent twice');
    eq($n, count($b->writes));
    $cards = t_cards($s);
    eq(2, $cards[6]['jobSent']);
});

test('Duplicate person + job: refused for "add another" and for editing M to an existing person', function () {
    [$b, $s] = t_setup(null, t_verify_multi());
    $e = throws(fn () => $s->addDecisionMaker(5, MULTI, 'be.linkedin.com/in/person-b/?trk=x', 'jp'), ConflictError::class, 'already in the verify tab for this job (row 6)');
    eq('DUPLICATE_PERSON', $e->errCode);
    throws(fn () => $s->saveLinkedIn(6, MULTI, ['https://www.linkedin.com/in/person-a'], 'jp'), ConflictError::class, 'already on verify row 5');
    eq([], $b->writes);
    // the same person IS allowed on a different job
    eq('appended', $s->addDecisionMaker(4, 'https://jobs.test/k-ready', 'https://www.linkedin.com/in/person-a', 'jp')['status']);
});

test('"Add another decision maker": appends A:R from the job\'s first row, new link in M, N:Q and S:U empty', function () {
    [$b, $s] = t_setup(null, t_verify_multi());
    $res = $s->addDecisionMaker(5, MULTI . '?utm_source=other', 'linkedin.com/in/person-c', 'jp');
    eq(['status' => 'appended', 'row' => 7, 'link' => 'https://www.linkedin.com/in/person-c'], $res);
    eq([['patchValues', Sheet::VERIFY, 'A7:R7'], ['patchNumberFormat', Sheet::VERIFY, 'A7']], t_writes($b));
    $first = t_row($b, Sheet::VERIFY, 5);
    $new = t_row($b, Sheet::VERIFY, 7);
    eq(array_slice($first, 0, 12), array_slice($new, 0, 12), 'A:L copied from the first verify row');
    eq('https://www.linkedin.com/in/person-c', $new[12]);
    eq(['', '', '', ''], array_slice($new, 13, 4), 'N:Q empty');
    eq($first[17], $new[17], 'R (Role Sub-Group) copied');
    eq([null, null, null], array_slice($new, 18, 3), 'S, T, U never written');
    $cards = t_cards($s);
    eq('ready', $cards[7]['status']);
    eq(3, $cards[7]['jobTotal']);
    // without R1 the append is A:Q
    $v = t_verify_multi();
    $v[0][17] = '';
    [$b2, $s2] = t_setup(null, $v);
    $s2->addDecisionMaker(5, MULTI, 'linkedin.com/in/person-c', 'jp');
    eq('A7:Q7', $b2->writes[0]['address']);
    // must be the job's first row, and the link must be /in/ or /sales/
    throws(fn () => $s->addDecisionMaker(6, MULTI, 'linkedin.com/in/person-d', 'jp'), ConflictError::class, 'The sheet changed');
    throws(fn () => $s->addDecisionMaker(5, MULTI, 'https://www.linkedin.com/company/acme', 'jp'), ValidationError::class);
});

test('Row shift: a row number that now holds a different Job URL is refused for every card write', function () {
    [$b, $s] = t_setup(null, t_verify_multi());
    // the browser showed MULTI on row 5, but someone deleted a row above it in Excel: row 5 is now person B's...
    // ...or a different job entirely. Simulate: row 5 now belongs to another job.
    $b->grid[Sheet::VERIFY][4][11] = 'https://jobs.test/someone-else';
    $calls = [
        fn () => $s->saveLinkedIn(5, MULTI, ['linkedin.com/in/person-z'], 'jp'),
        fn () => $s->approve(5, MULTI, t_user()),
        fn () => $s->undoApprove(5, MULTI, 'jp'),
        fn () => $s->addDecisionMaker(5, MULTI, 'linkedin.com/in/person-z', 'jp'),
        fn () => $s->saveLinkedIn(99, MULTI, ['linkedin.com/in/person-z'], 'jp'),
    ];
    foreach ($calls as $fn) {
        $e = throws($fn, ConflictError::class, 'The sheet changed, please refresh.');
        eq('ROW_CHANGED', $e->errCode);
    }
    eq([], $b->writes, 'nothing written');
    throws(fn () => $s->saveLinkedIn(1, MULTI, [], 'jp'), ValidationError::class, 'row number');
});

test('Untick: one verify row with empty M/S/T/U only; several decision makers -> "Has decision makers, edit in Excel"', function () {
    [$b, $s] = t_setup(null, t_verify_multi());
    $e = throws(fn () => $s->untick(MULTI, 'jp'), ConflictError::class, 'Has decision makers, edit in Excel');
    eq('HAS_DECISION_MAKER', $e->errCode);
    throws(fn () => $s->untick('https://jobs.test/k-ready', 'jp'), ConflictError::class, 'Has decision makers, edit in Excel');
    throws(fn () => $s->untick('https://jobs.test/k-note', 'jp'), ConflictError::class, 'column S');
    eq([], $b->writes);
    eq('removed', $s->untick('https://jobs.test/k-empty', 'jp')['status']);
});

test('Signals page: verified if any verify row has the URL; untick locked unless exactly one empty row', function () {
    $sig = t_signals_tab();
    $sig[] = t_signal(T_DAY, 'CHILI publish', MULTI);
    $v = t_verify_multi();
    $v[] = t_verify_row(T_DAY, 'Acme', 'https://jobs.test/acme'); // one empty verify row for Acme
    [, $s] = t_setup($sig, $v);
    $rows = array_column($s->signalsView(T_DAY)['rows'], null, 'company');
    eq([true, 2, true], [$rows['CHILI publish']['ticked'], $rows['CHILI publish']['verifyRows'], $rows['CHILI publish']['untickLocked']]);
    eq([true, 1, false], [$rows['Acme']['ticked'], $rows['Acme']['verifyRows'], $rows['Acme']['untickLocked']]);
    eq(false, $rows['Beta']['ticked']);
});

test('Verified view: jobs grouped, cards per row with waiting / ready / sent; filters count cards', function () {
    [, $s] = t_setup(null, t_verify_multi());
    $v = $s->verifiedView();
    eq(['waiting' => 2, 'ready' => 3, 'sent' => 0], $v['counts']);
    $byCompany = array_column($v['jobs'], null, 'company');
    eq([5, 6], array_column($byCompany['CHILI publish']['cards'], 'row'));
    eq(['Ann A', 'Bob B'], array_column($byCompany['CHILI publish']['cards'], 'dmName'));
    eq(2, $byCompany['CHILI publish']['total']);
    eq('researching', $byCompany['Kept Note']['cards'][0]['dmNote']);
});

test('Undo approve: only the row the app just appended, while it is still last and unchanged', function () {
    [$b, $s] = t_setup(null, t_verify_multi());
    $s->approve(5, MULTI, t_user());
    ok(t_cards($s)[5]['canUndo']);
    ok(!t_cards($s)[6]['canUndo']);
    throws(fn () => $s->undoApprove(6, MULTI, 'jp'), ConflictError::class, 'most recently', 'not the person just sent');
    $b->writes = [];
    eq(['status' => 'undone', 'row' => 2], $s->undoApprove(5, MULTI, 'jp'));
    eq([['clearContents', Sheet::DECISION_MAKERS, 'A2:Q2']], t_writes($b));
    eq('ready', t_cards($s)[5]['status']);

    [$b, $s] = t_setup();
    $s->approve(4, 'https://jobs.test/k-ready', t_user());
    $b->grid[Sheet::DECISION_MAKERS][1][14] = 'Pushed';
    $b->writes = [];
    throws(fn () => $s->undoApprove(4, 'https://jobs.test/k-ready', 'jp'), ConflictError::class, 'has been changed');
    eq([], $b->writes);

    [$b, $s] = t_setup();
    $s->approve(4, 'https://jobs.test/k-ready', t_user());
    $b->grid[Sheet::DECISION_MAKERS][2] = array_fill(0, 17, 'manual row');
    throws(fn () => $s->undoApprove(4, 'https://jobs.test/k-ready', 'jp'), ConflictError::class, 'no longer the last row');
});

test('Guard: "add another" uses the same A:Q/A:R append; N:Q or S:U on their own are never writable', function () {
    eq(7, Guard::assert('values', Sheet::VERIFY, 'A7:R7'));
    eq(7, Guard::assert('values', Sheet::VERIFY, 'A7:Q7'));
    foreach (['N7', 'N7:Q7', 'Q7', 'R7', 'S7:U7', 'M7:S7', 'A7:T7'] as $a) {
        throws(fn () => Guard::assert('values', Sheet::VERIFY, $a), \SignalDesk\ForbiddenWriteError::class);
    }
});

test('No operation ever writes Signals, verify S/T/U, or any other tab', function () {
    [$b, $s] = t_setup(null, t_verify_multi());
    $s->tick('https://jobs.test/acme', 'jp');
    $s->saveLinkedIn(7, 'https://jobs.test/acme', ['linkedin.com/in/acme-cto'], 'jp');
    $s->addDecisionMaker(7, 'https://jobs.test/acme', 'linkedin.com/in/acme-cio', 'jp');
    $s->approve(7, 'https://jobs.test/acme', t_user());
    $s->undoApprove(7, 'https://jobs.test/acme', 'jp');
    $s->approve(8, 'https://jobs.test/acme', t_user());
    $s->reject('https://jobs.test/beta', 'jp');
    ok(count($b->writes) > 6);
    foreach ($b->writes as $w) {
        ok($w['sheet'] !== Sheet::SIGNALS, 'Signals written');
        ok(in_array($w['sheet'], [Sheet::VERIFY, Sheet::DECISION_MAKERS], true), 'other tab written');
        if ($w['sheet'] === Sheet::VERIFY && $w['op'] !== 'deleteShiftUp') {
            ok(!preg_match('/(^|:)[STU]\d/', $w['address']), 'S/T/U written: ' . $w['address']);
        }
    }
});

test('Mock fixture covers all 3 stages, including a job with two decision makers', function () {
    $b = new MemoryBackend(Fixture::build(new DateTimeImmutable('2026-10-16')), 'MOCK DATA');
    $s = new Service($b, tmpdir());
    eq(8, count($s->signalsView()['rows']));
    $v = $s->verifiedView();
    eq(['waiting' => 2, 'ready' => 3, 'sent' => 1], $v['counts']);
    eq([], $v['warnings']);
    $multi = array_values(array_filter($v['jobs'], fn ($j) => $j['total'] === 2));
    eq(1, count($multi));
});
