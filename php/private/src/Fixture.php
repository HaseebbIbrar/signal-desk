<?php
// Fake data for WORKBOOK_BACKEND=mock (local testing only; refused in production). Covers all 3 stages:
// signals to verify or reject, verify rows waiting for / ready with a decision maker, and one already sent.
declare(strict_types=1);

namespace SignalDesk;

final class Fixture
{
    public const SIGNALS_HEADER = [
        'Date Found', 'Day', 'Company', 'Domain', 'Job Title', 'Matched Keyword', 'Role Group', 'Company Type', 'Location',
        'Status', 'Source', 'Job URL', 'Decision Maker LinkedIn', 'Lemlist / Prior Outreach', 'Notes', 'Verified Evidence',
        'My Comments', 'Role Sub-Group',
    ];
    public const VERIFY_EXTRA_HEADER = ['Decision maker note', 'Decision Maker Name', 'Decision Maker Title'];

    private const COMPANIES = [
        ['Example Logistics NV', 'example-logistics.test', 'End user', 'Antwerp'],
        ['Demo Bank SA', 'demo-bank.test', 'End user', 'Brussels'],
        ['Sample MSSP BV', 'sample-mssp.test', 'MSSP/MSP', 'Ghent'],
        ['Placeholder Systems', 'placeholder-systems.test', 'Vendor', 'Leuven'],
        ['Mock Integrators NV', 'mock-integrators.test', 'Consultancy', 'Mechelen'],
        ['Test Energy Group', 'test-energy.test', 'End user', 'Liège'],
    ];
    private const NEW_ROLES = [
        ['Security Architect', 'security architect', 'A - Security', 'A2 - Security Architecture'],
        ['Data Engineer', 'data engineer', 'B - Data & BI', 'B1 - Data Engineering'],
        ['Back-end Developer (Java)', 'java developer', 'C - Software Development', 'C1 - Back-end (Java, .NET, Python, PHP)'],
        ['Cloud Platform Engineer', 'cloud engineer', 'D - Cloud & DevOps', 'D1 - Cloud Platform (Azure, AWS, GCP)'],
        ['Network Engineer', 'network engineer', 'E - Infrastructure & Network', 'E2 - Network & Connectivity'],
        ['Functional Analyst', 'functional analyst', 'F - Business & Functional Analysis', 'F1 - Functional Analysis'],
        ['Enterprise Architect', 'enterprise architect', 'G - Architecture', 'G1 - Enterprise Architecture'],
        ['SAP S/4HANA Consultant', 'sap consultant', 'H - ERP, CRM & Business Apps', 'H1 - SAP'],
        ['Machine Learning Engineer', 'machine learning', 'I - AI & Machine Learning', 'I1 - ML Engineering'],
        ['IT Project Manager', 'project manager', 'J - Project & Delivery', 'J1 - Project Management'],
        ['Service Desk Engineer', 'service desk', 'K - IT Service, Support & Workplace', 'K1 - Service Desk 2nd / 3rd Line'],
    ];
    private const OLD_ROLES = [
        ['SOC Analyst L2', 'soc analyst', 'A - Security'],
        ['Data Scientist', 'data scientist', 'B - Data & AI'],
        ['Full-stack Developer', 'full stack', 'C - Software Development / Engineering'],
        ['Head of IT Infrastructure', 'head of it', 'E - Infrastructure & Networking'],
        ['Business Analyst', 'business analyst', 'F - Project Management / Functional Analysis / Business Analysis'],
    ];

    private static function isoDaysAgo(\DateTimeImmutable $today, int $n): string
    {
        return $today->modify("-$n days")->format('Y-m-d');
    }

    private static function signalRow(string $iso, int $i, bool $newScheme): array
    {
        [$company, $domain, $type, $city] = self::COMPANIES[$i % count(self::COMPANIES)];
        if ($newScheme) {
            [$title, $keyword, $group, $sub] = self::NEW_ROLES[($i * 3) % count(self::NEW_ROLES)];
        } else {
            [$title, $keyword, $group] = self::OLD_ROLES[$i % count(self::OLD_ROLES)];
            $sub = '';
        }
        $slug = explode('.', $domain)[0];
        return [
            ExcelDate::isoToSerial($iso), (new \DateTimeImmutable($iso))->format('l'), $company, $domain, $title, $keyword,
            $group, $type, "$city, Belgium", 'New', $i % 2 ? 'LinkedIn Jobs' : 'Company careers page',
            "https://jobs.example.com/$slug/$iso-$i?utm_source=feed", '', $i % 3 === 0 ? 'Contacted in 2025 via Lemlist (no reply)' : '',
            "Fake note for $company. Hiring signal around $keyword.", 'Mock evidence: careers page listing seen.', '', $sub,
        ];
    }

    /** @return array<string,array<int,array<int,mixed>>> */
    public static function build(?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels'));
        $signals = [self::SIGNALS_HEADER];
        $n = 0;
        foreach ([3, 2, 1, 0] as $back) {
            $iso = self::isoDaysAgo($today, $back);
            $signals[] = array_merge([ExcelDate::isoToSerial($iso)], array_fill(0, 17, ''));
            $count = $back === 0 ? 8 : 5;
            for ($i = 0; $i < $count; $i++) $signals[] = self::signalRow($iso, $n++, $back <= 1);
        }

        // Verify tab: rows copied from older signals, in different stages.
        $dataRows = array_values(array_filter($signals, fn ($r) => is_string($r[2]) && $r[2] !== '' && $r[2] !== 'Company'));
        $verify = [array_merge(self::SIGNALS_HEADER, self::VERIFY_EXTRA_HEADER)];
        $stages = [
            // [M linkedin, S note, T name, U title]
            ['', '', '', ''],                                                                  // waiting, untick allowed
            ['', 'Researching: two candidates, checking who owns the budget', '', ''],         // waiting, note only (untick blocked)
            ['https://www.linkedin.com/in/example-cto', 'Confirmed via team page', 'Alex Example', 'CTO'], // ready to review
            ['https://www.linkedin.com/in/example-head-of-it', '', 'Sam Sample', 'Head of IT'],          // ready to review
            ['https://www.linkedin.com/in/example-ciso', 'Spoke at event', 'Jo Placeholder', 'CISO'],    // already sent
        ];
        foreach ($stages as $i => [$m, $s, $t, $u]) {
            $row = $dataRows[$i];
            $row[Sheet::DM_LINKEDIN] = $m;
            $verify[] = array_merge($row, [$s, $t, $u]);
        }
        // A second decision maker for the job on row 4 (same Job URL, different person): its own card.
        $second = $dataRows[2];
        $second[Sheet::DM_LINKEDIN] = 'https://www.linkedin.com/in/example-vp-engineering';
        foreach ([Sheet::PRIOR_OUTREACH, Sheet::NOTES, Sheet::VERIFIED_EVIDENCE, Sheet::MY_COMMENTS] as $col) $second[$col] = '';
        $verify[] = array_merge($second, ['Owns the platform team', 'Robin Example', 'VP Engineering']);

        // Decision Makers tab: header + the already-sent row.
        $sent = $verify[5];
        $dm = [Sheet::DM_TAB_HEADERS, [
            ExcelDate::isoToSerial(self::isoDaysAgo($today, 1)), 'Jonathan Perkins', $sent[0], $sent[2], $sent[3], $sent[4], $sent[6], $sent[17],
            $sent[7], $sent[8], $sent[11], $sent[12], $sent[19], $sent[20], 'Ready to push', 6, $sent[18],
        ]];
        // HR track: "Hr signals" (same A:U layout) with a few jobs, and an empty "HR Decision Makers" tab (headers only).
        $hr = [array_merge(self::SIGNALS_HEADER, self::VERIFY_EXTRA_HEADER)];
        foreach ([[6, '', '', '', ''], [7, '', 'Ask for the HR business partner', '', ''],
                  [8, 'https://www.linkedin.com/in/example-hr-director', '', 'Kim Example', 'HR Director'], [9, '', '', '', '']] as [$i, $m, $s, $t, $u]) {
            $row = $dataRows[$i % count($dataRows)];
            $row[Sheet::DM_LINKEDIN] = $m;
            $hr[] = array_merge($row, [$s, $t, $u]);
        }
        return [Sheet::SIGNALS => $signals, Sheet::VERIFY => $verify, Sheet::DECISION_MAKERS => $dm,
            Sheet::HR => $hr, Sheet::HR_DECISION_MAKERS => [Sheet::DM_TAB_HEADERS]];
    }
}
