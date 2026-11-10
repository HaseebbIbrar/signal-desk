<?php
// HR track: "Hr signals" -> "HR Decision Makers", same rules as Verified -> Decision Makers, on their own tabs only.
declare(strict_types=1);

use SignalDesk\ConflictError;
use SignalDesk\ForbiddenWriteError;
use SignalDesk\GraphBackend;
use SignalDesk\Guard;
use SignalDesk\MemoryBackend;
use SignalDesk\Service;
use SignalDesk\Sheet;
use SignalDesk\UpstreamError;

/** Workbook with both tracks; $hrDm = null leaves out the "HR Decision Makers" tab (not created yet). */
function t_hr_setup(?array $hrDm = [Sheet::DM_TAB_HEADERS]): array
{
    $tabs = [
        Sheet::SIGNALS => t_signals_tab(), Sheet::VERIFY => t_verify_tab(), Sheet::DECISION_MAKERS => t_dm_tab(),
        Sheet::HR => [
            t_verify_header_au(),
            t_verify_row('2026-10-23', 'Kpmg', 'https://www.linkedin.com/jobs/view/4148600001'),
            t_verify_row('2026-10-19', 'Sofico', 'https://www.linkedin.com/jobs/view/4477700002', 'https://www.linkedin.com/in/hr-partner', 'HR partner for tech hires', 'Hanne Example', 'HR Business Partner'),
        ],
    ];
    if ($hrDm !== null) $tabs[Sheet::HR_DECISION_MAKERS] = $hrDm;
    $b = new MemoryBackend($tabs, 'TEST');
    return [$b, new Service($b, tmpdir(), 'Europe/Brussels', 0)];
}

test('HR signals page: cards from the "Hr signals" tab, status from "HR Decision Makers"; a missing HR Decision Makers tab is a warning, not an error', function () {
    [$b, $s] = t_hr_setup();
    $v = $s->verifiedView('hr');
    eq(['Kpmg', 'Sofico'], array_column($v['jobs'], 'company'));
    eq(['waiting' => 1, 'ready' => 1, 'sent' => 0], $v['counts']);
    eq([], $v['warnings']);
    eq(3, count($s->verifiedView()['jobs']), 'the Verified page is unchanged (its own tab)');
    [, $missing] = t_hr_setup(null);
    $m = $missing->verifiedView('hr');
    eq(2, count($m['jobs']));
    ok(str_contains($m['warnings'][0], '"HR Decision Makers" tab is empty'), $m['warnings'][0] ?? '');
});

test('HR track writes: LinkedIn in column M of the HR tab, "+ Add another" appends to the HR tab, approve appends to "HR Decision Makers" only', function () {
    [$b, $s] = t_hr_setup();
    $s->saveLinkedIn(2, 'https://www.linkedin.com/jobs/view/4148600001', ['linkedin.com/in/kpmg-talent-lead'], 'jp@swooscale.test', 'hr');
    eq('https://www.linkedin.com/in/kpmg-talent-lead', t_row($b, Sheet::HR, 2)[Sheet::DM_LINKEDIN]);
    $add = $s->addDecisionMaker(2, 'https://www.linkedin.com/jobs/view/4148600001', 'linkedin.com/in/kpmg-hr-director', 'jp@swooscale.test', 'hr');
    eq(4, $add['row']);
    eq(['Kpmg', 'https://www.linkedin.com/in/kpmg-hr-director', ''], [t_row($b, Sheet::HR, 4)[Sheet::COMPANY], t_row($b, Sheet::HR, 4)[Sheet::DM_LINKEDIN], t_row($b, Sheet::HR, 4)[Sheet::NOTES]]);
    $r = $s->approve(3, 'https://www.linkedin.com/jobs/view/4477700002', t_user(), 'hr');
    eq(['appended', 2], [$r['status'], $r['row']]);
    $dm = t_row($b, Sheet::HR_DECISION_MAKERS, 2, 17);
    eq(['Jonathan Perkins', 'Sofico', 'https://www.linkedin.com/in/hr-partner', 'Hanne Example', 'HR Business Partner', 'Ready to push', 3, 'HR partner for tech hires'],
        [$dm[1], $dm[3], $dm[11], $dm[12], $dm[13], $dm[14], $dm[15], $dm[16]], 'same 17 columns; Source Row = the HR tab row');
    eq(1, count($b->grid[Sheet::DECISION_MAKERS]), 'the normal Decision Makers tab is untouched');
    eq('already', $s->approve(3, 'https://www.linkedin.com/jobs/view/4477700002', t_user(), 'hr')['status'], 'same person + job is never sent twice');
    $touched = array_values(array_unique(array_column($b->writes, 'sheet')));
    sort($touched);
    eq([Sheet::HR_DECISION_MAKERS, Sheet::HR], $touched, 'only the two HR tabs were written');
    eq('sent', $s->verifiedView('hr')['jobs'][1]['cards'][0]['status']);
});

test('HR approve: refused until "HR Decision Makers" has the exact headers; undo only the app\'s own last HR row (its own receipt)', function () {
    [, $noTab] = t_hr_setup(null);
    throws(fn () => $noTab->approve(3, 'https://www.linkedin.com/jobs/view/4477700002', t_user(), 'hr'), UpstreamError::class, 'No such worksheet');
    $bad = Sheet::DM_TAB_HEADERS;
    $bad[14] = 'State';
    [$b2, $wrong] = t_hr_setup([$bad]);
    $e = throws(fn () => $wrong->approve(3, 'https://www.linkedin.com/jobs/view/4477700002', t_user(), 'hr'), ConflictError::class, '"HR Decision Makers" tab header does not match: O1');
    eq('DM_HEADER_MISMATCH', $e->errCode);
    eq([], $b2->writes);

    [$b, $s] = t_hr_setup();
    $s->approve(3, 'https://www.linkedin.com/jobs/view/4477700002', t_user(), 'hr');
    ok($s->verifiedView('hr')['jobs'][1]['cards'][0]['canUndo']);
    throws(fn () => $s->undoApprove(4, 'https://www.linkedin.com/jobs/view/k-ready', 'jp', 'verified'), ConflictError::class);
    eq('undone', $s->undoApprove(3, 'https://www.linkedin.com/jobs/view/4477700002', 'jp', 'hr')['status']);
    eq('ready', $s->verifiedView('hr')['jobs'][1]['cards'][0]['status']);
});

test('Guard: the HR tab allows only M and row appends (never clear/delete); HR Decision Makers like Decision Makers; HR tab name matched despite the trailing space', function () {
    eq(5, Guard::assert('values', Sheet::HR, 'M5'));
    Guard::assert('values', Sheet::HR, 'A9:R9');
    Guard::assert('format', Sheet::HR, 'A9');
    foreach (['S5', 'T5', 'U5', 'N5:Q5', 'A1:R1'] as $a) throws(fn () => Guard::assert('values', Sheet::HR, $a), ForbiddenWriteError::class);
    throws(fn () => Guard::assert('clear', Sheet::HR, 'A5:R5'), ForbiddenWriteError::class);
    throws(fn () => Guard::assert('delete', Sheet::HR, 'A5:U5'), ForbiddenWriteError::class);
    Guard::assert('values', Sheet::HR_DECISION_MAKERS, 'A3:Q3');
    Guard::assert('clear', Sheet::HR_DECISION_MAKERS, 'A3:Q3');
    throws(fn () => Guard::assert('values', Sheet::HR_DECISION_MAKERS, 'R3'), ForbiddenWriteError::class);

    $urls = [];
    $g = new GraphBackend('t', 'c', 's', 'd', 'i', '', tmpdir() . '/tok.json');
    $g->transport = function ($method, $url) use (&$urls) {
        if (str_contains($url, 'oauth2')) return ['status' => 200, 'headers' => [], 'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600])];
        $urls[] = urldecode($url);
        if (str_contains($url, '/workbook/worksheets?')) return ['status' => 200, 'headers' => [], 'body' => json_encode(['value' => [['name' => 'Signals'], ['name' => 'Hr signals '], ['name' => 'Decision Makers']]])];
        return ['status' => 200, 'headers' => [], 'body' => json_encode(['values' => [['x']]])];
    };
    eq('Hr signals ', $g->sheetName(Sheet::HR));
    $g->range(Sheet::HR, 'L2');
    ok(str_contains(end($urls), "worksheets('Hr signals ')/range(address='L2')"), end($urls));
    $g->range(Sheet::VERIFY, 'L2');
    ok(str_contains(end($urls), "worksheets('Signals to verify decision make')"), 'fixed tabs keep their exact names');
    eq(1, count(array_filter($urls, fn ($u) => str_contains($u, '/workbook/worksheets?'))), 'tab list read once');
    throws(fn () => $g->sheetName(Sheet::HR_DECISION_MAKERS), UpstreamError::class, 'tab was not found');
});
