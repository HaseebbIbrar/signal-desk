<?php
// Excel dates, Job URL keys, LinkedIn links, A1 addresses, sheet/column logic.
declare(strict_types=1);

use SignalDesk\A1;
use SignalDesk\ExcelDate;
use SignalDesk\JobUrl;
use SignalDesk\LinkedIn;
use SignalDesk\Sheet;

test('Excel dates: known serials, the 1900 leap bug, round trips', function () {
    eq('1900-01-01', ExcelDate::serialToIso(1));
    eq('1900-02-28', ExcelDate::serialToIso(59));
    eq('1900-02-29', ExcelDate::serialToIso(60));
    eq('1900-03-01', ExcelDate::serialToIso(61));
    eq('2023-03-15', ExcelDate::serialToIso(45000));
    eq('2023-03-15', ExcelDate::serialToIso(45000.99));
    eq('2026-10-07', ExcelDate::serialToIso(46302));
    eq(null, ExcelDate::serialToIso(0));
    eq(null, ExcelDate::serialToIso('45000'));
    eq(46302, ExcelDate::isoToSerial('2026-10-07'));
    eq(1, ExcelDate::isoToSerial('1900-01-01'));
    eq(null, ExcelDate::isoToSerial('2026-02-30'));
    for ($s = 61; $s < 47000; $s += 7) eq($s, ExcelDate::isoToSerial(ExcelDate::serialToIso($s)));
});

test('Excel dates: any cell shape', function () {
    eq('2026-10-07', ExcelDate::cellToIso(46302));
    eq('2026-10-07', ExcelDate::cellToIso('2026-10-07'));
    eq('2026-10-07', ExcelDate::cellToIso('07/10/2026'));
    eq('2026-10-07', ExcelDate::cellToIso('46302'));
    eq(null, ExcelDate::cellToIso('Acme NV'));
    eq(null, ExcelDate::cellToIso(null));
    eq(ExcelDate::isoToSerial((new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')))->format('Y-m-d')), ExcelDate::todaySerial('Europe/Brussels'));
});

test('Job URL normalisation: trim, fragment, slash, host case; tracking params dropped', function () {
    eq('https://jobs.example.com/acme/123', JobUrl::normalise('  https://Jobs.Example.com/acme/123/?utm_source=x&utm_medium=y#apply '));
    eq('https://x.com/Jobs/ABC', JobUrl::normalise('https://x.com/Jobs/ABC'));
    eq('https://x.com/job?a=1&b=2', JobUrl::normalise('https://x.com/job?b=2&gclid=zz&a=1&fbclid=yy&trk=t&refId=r&trackingId=q'), 'sorted, tracking dropped');
    eq('', JobUrl::normalise(null));
    eq('', JobUrl::normalise('   '));
});

test('Job URL normalisation: talent.com ?id= values stay different jobs', function () {
    $a = JobUrl::normalise('https://be.talent.com/view?id=634735404347819824');
    $b = JobUrl::normalise('https://be.talent.com/view?id=638170805972572162');
    eq('https://be.talent.com/view?id=634735404347819824', $a);
    ok($a !== $b, 'different talent.com jobs must not collide');
    eq($a, JobUrl::normalise('https://be.talent.com/view?utm_source=feed&id=634735404347819824&utm_campaign=x'));
});

test('Job URL normalisation: Indeed keeps only the job key', function () {
    eq('https://be.indeed.com/viewjob?jk=abc', JobUrl::normalise('https://be.indeed.com/viewjob?from=serp&jk=abc&vjs=3'));
    ok(JobUrl::normalise('https://be.indeed.com/viewjob?jk=a') !== JobUrl::normalise('https://be.indeed.com/viewjob?jk=b'));
});

test('Job URL normalisation: careers page ?jobId= is kept; utm params are not', function () {
    eq('https://careers.acme.test/apply?jobId=4471', JobUrl::normalise('https://careers.acme.test/apply/?jobId=4471&utm_source=linkedin&utm_medium=jobs'));
    ok(JobUrl::normalise('https://careers.acme.test/apply?jobId=4471') !== JobUrl::normalise('https://careers.acme.test/apply?jobId=4472'));
});

test('Job URL normalisation: LinkedIn job links reduce to the numeric job id', function () {
    $k = 'https://www.linkedin.com/jobs/view/4359223279';
    eq($k, JobUrl::normalise('https://www.linkedin.com/jobs/view/4359223279/'));
    eq($k, JobUrl::normalise('https://be.linkedin.com/jobs/view/commerce-architect-at-unilin-4359223279?trk=public_jobs&refId=abc'));
    eq($k, JobUrl::normalise('https://www.linkedin.com/jobs/search/?currentJobId=4359223279&keywords=architect'));
    ok($k !== JobUrl::normalise('https://www.linkedin.com/jobs/view/4472653388/'));
});

test('LinkedIn validation: /in/ and /sales/ only, normalised', function () {
    eq(['ok' => true, 'url' => 'https://www.linkedin.com/in/jane-doe'], LinkedIn::normalise('be.linkedin.com/in/jane-doe/?trk=x'));
    eq(['ok' => true, 'url' => 'https://www.linkedin.com/sales/lead/ACwAA,NAME?_ntb=1'], LinkedIn::normalise('https://www.linkedin.com/sales/lead/ACwAA,NAME?_ntb=1'));
    foreach (['https://www.linkedin.com/company/acme', 'https://www.linkedin.com/in/', 'https://evil.com/in/x', 'https://linkedin.com.evil.com/in/x',
        'javascript:alert(1)', 'https://www.linkedin.com:8443/in/x', 'https://u:p@www.linkedin.com/in/x', 'https://www.linkedin.com/in/a b'] as $bad) {
        ok(!LinkedIn::normalise($bad)['ok'], "should reject $bad");
    }
    $v = LinkedIn::validateList(['linkedin.com/in/a', '', 'https://www.linkedin.com/in/a/', 'nope']);
    eq(['https://www.linkedin.com/in/a'], $v['links']);
    eq([null, null, null], array_slice($v['errors'], 0, 3));
    ok(is_string($v['errors'][3]));
    eq(LinkedIn::key("https://www.linkedin.com/in/b\nlinkedin.com/in/a/"), LinkedIn::key("https://www.linkedin.com/in/a\nhttps://www.linkedin.com/in/b"));
});

test('A1 parsing', function () {
    eq([2, 0, 2, 16], A1::parse('A2:Q2'));
    eq([5, 12, 5, 12], A1::parse('M5'));
    eq([1, 0, 159, 20], A1::parse("'Signals to verify decision make'!A1:U159"));
    eq('U', A1::indexToCol(20));
    throws(fn () => A1::parse('nope'), InvalidArgumentException::class);
});

test('Sheet: separators, latest block, last data row from A:Q only', function () {
    $rows = Sheet::toRows(['startRow' => 1, 'startCol' => 0, 'values' => t_signals_tab()], 18);
    $blocks = Sheet::blocks($rows);
    eq(['2026-10-16', '2026-10-15'], Sheet::blockDates($blocks));
    eq('2026-10-16', Sheet::latestDate($blocks));
    ok(Sheet::isSeparator(t_sep('2026-10-16')));
    ok(!Sheet::isSeparator(t_signal('2026-10-16', 'X', 'u')));

    $v = t_verify_tab();
    $v[] = array_merge(array_fill(0, 17, ''), ['stray R', 'stray S', '', '']); // row 5: only R/S filled
    $vr = Sheet::toRows(['startRow' => 1, 'startCol' => 0, 'values' => $v], 21);
    eq(4, Sheet::lastValueRow($vr), 'R:U must not move the last data row');
});

test('Sheet: R and S:U count only under the right headers', function () {
    $rows = Sheet::toRows(['startRow' => 1, 'startCol' => 0, 'values' => t_verify_tab()], 21);
    ok(Sheet::hasSubGroup($rows));
    ok(Sheet::hasDmDetails($rows));
    $noHdr = t_verify_tab();
    $noHdr[0] = array_merge(t_header_aq(), ['', '', '', '']);
    $r2 = Sheet::withoutForeignColumns(Sheet::toRows(['startRow' => 1, 'startCol' => 0, 'values' => $noHdr], 21), true);
    ok(!Sheet::hasSubGroup($r2));
    eq(null, $r2[3]['cells'][17]);
    eq(null, $r2[3]['cells'][19]);
});
