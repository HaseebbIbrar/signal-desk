<?php
// Builders for small test workbooks.
declare(strict_types=1);

use SignalDesk\ExcelDate;
use SignalDesk\Fixture;
use SignalDesk\MemoryBackend;
use SignalDesk\Service;
use SignalDesk\Sheet;

const T_SIGNALS_HEADER_AR = Fixture::SIGNALS_HEADER;                 // A:R
function t_header_aq(): array { return array_slice(Fixture::SIGNALS_HEADER, 0, 17); }
function t_verify_header_au(): array { return array_merge(Fixture::SIGNALS_HEADER, Fixture::VERIFY_EXTRA_HEADER); }

function t_sep(string $iso): array { return array_merge([ExcelDate::isoToSerial($iso)], array_fill(0, 17, '')); }

/** A:R signal row. $extra: [colIndex => value] */
function t_signal(string $iso, string $company, string $url, array $extra = []): array
{
    $row = [ExcelDate::isoToSerial($iso), 'Monday', $company, strtolower($company) . '.test', 'Security Architect', 'security',
        'A - Security', 'End user', 'Brussels', 'New', 'LinkedIn', $url, '', '', 'note', 'evidence', '', 'A2 - Security Architecture'];
    foreach ($extra as $k => $v) $row[$k] = $v;
    return $row;
}

/** A:U verify row = signal A:R + S, T, U. */
function t_verify_row(string $iso, string $company, string $url, string $m = '', string $s = '', string $t = '', string $u = ''): array
{
    $r = t_signal($iso, $company, $url, [12 => $m]);
    return array_merge($r, [$s, $t, $u]);
}

const T_DAY = '2026-10-16';

function t_signals_tab(): array
{
    return [
        T_SIGNALS_HEADER_AR,
        t_sep('2026-10-15'),
        t_signal('2026-10-15', 'Old Co', 'https://jobs.test/old', [6 => 'B - Data & AI', 17 => '']),
        t_sep(T_DAY),
        t_signal(T_DAY, 'Acme', 'https://jobs.test/acme?utm=feed'),
        t_signal(T_DAY, 'Beta', 'https://jobs.test/beta'),
        t_signal(T_DAY, 'Gamma', 'https://jobs.test/gamma', [14 => '=HYPERLINK(evil)']),
    ];
}

function t_verify_tab(): array
{
    return [
        t_verify_header_au(),
        t_verify_row('2026-10-01', 'Kept Empty', 'https://jobs.test/k-empty'),
        t_verify_row('2026-10-01', 'Kept Note', 'https://jobs.test/k-note', '', 'researching'),
        t_verify_row('2026-10-01', 'Kept Ready', 'https://jobs.test/k-ready', 'https://www.linkedin.com/in/jane-doe', 'met at event', 'Jane Doe', 'CTO'),
    ];
}

function t_dm_tab(): array { return [Sheet::DM_TAB_HEADERS]; }

/** @return array{0:MemoryBackend,1:Service,2:string} */
function t_setup(?array $signals = null, ?array $verify = null, ?array $dm = null): array
{
    $b = new MemoryBackend([
        Sheet::SIGNALS => $signals ?? t_signals_tab(),
        Sheet::VERIFY => $verify ?? t_verify_tab(),
        Sheet::DECISION_MAKERS => $dm ?? t_dm_tab(),
    ], 'TEST');
    $storage = tmpdir();
    return [$b, new Service($b, $storage, 'Europe/Brussels', 0), $storage];
}

/** Row (1-based) of a memory sheet, padded to $width. */
function t_row(MemoryBackend $b, string $sheet, int $row, int $width = 21): array
{
    $r = $b->grid[$sheet][$row - 1] ?? [];
    $out = [];
    for ($i = 0; $i < $width; $i++) $out[] = $r[$i] ?? null;
    return $out;
}

function t_writes(MemoryBackend $b): array
{
    return array_map(fn ($w) => [$w['op'], $w['sheet'], $w['address']], $b->writes);
}

function t_user(): array { return ['email' => 'jp@swooscale.test', 'name' => 'Jonathan Perkins']; }
