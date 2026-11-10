<?php
// Workbook layout (set up by hand in the master; the app never writes headers) and row helpers.
declare(strict_types=1);

namespace SignalDesk;

final class Sheet
{
    public const SIGNALS = 'Signals';
    public const VERIFY = 'Signals to verify decision make';
    public const DECISION_MAKERS = 'Decision Makers';
    /** HR track: same A:U layout as the verify tab; approvals go to their own tab with the Decision Makers headers. */
    public const HR = 'Hr signals';
    public const HR_DECISION_MAKERS = 'HR Decision Makers';
    /** Tabs matched ignoring case and surrounding spaces (the master's HR tab is called "Hr signals " with a trailing space). */
    public const LOOSE_NAMES = [self::HR, self::HR_DECISION_MAKERS];
    public const MASTER_FILE_NAME = 'SwooScale Master Data.xlsx';

    // Column indexes (0-based) shared by Signals (A:R) and the verify tab (A:U).
    public const DATE_FOUND = 0, DAY = 1, COMPANY = 2, DOMAIN = 3, JOB_TITLE = 4, MATCHED_KEYWORD = 5,
        ROLE_GROUP = 6, COMPANY_TYPE = 7, LOCATION = 8, STATUS = 9, SOURCE = 10, JOB_URL = 11,
        DM_LINKEDIN = 12, PRIOR_OUTREACH = 13, NOTES = 14, VERIFIED_EVIDENCE = 15, MY_COMMENTS = 16,
        ROLE_SUB_GROUP = 17, DM_NOTE = 18, DM_NAME = 19, DM_TITLE = 20;

    public const BASE_COLS = 17;   // A:Q
    public const SIGNALS_COLS = 18; // A:R
    public const VERIFY_COLS = 21;  // A:U

    public const SUB_GROUP_HEADER = 'Role Sub-Group';
    /** Verify tab S1, T1, U1. */
    public const DM_DETAIL_HEADERS = [18 => 'Decision maker note', 19 => 'Decision Maker Name', 20 => 'Decision Maker Title'];

    /** "Decision Makers" tab, row 1, A:Q, exactly. */
    public const DM_TAB_HEADERS = [
        'Date Verified', 'Verified By', 'Date Found', 'Company', 'Domain', 'Job Title', 'Role Group', 'Role Sub-Group',
        'Company Type', 'Location', 'Job URL', 'Decision Maker LinkedIn', 'Decision Maker Name', 'Decision Maker Title',
        'Status', 'Source Row', 'Notes',
    ];
    public const DM_TAB_COLS = 17; // A:Q
    // Decision Makers tab column indexes
    public const DMT_DATE_VERIFIED = 0, DMT_JOB_URL = 10, DMT_LINKEDIN = 11;

    public static function isBlank(mixed $c): bool
    {
        return $c === null || (is_string($c) && trim($c) === '');
    }

    /** @param array<int,mixed> $cells */
    public static function isRowBlank(array $cells): bool
    {
        foreach ($cells as $c) if (!self::isBlank($c)) return false;
        return true;
    }

    public static function display(mixed $c): string
    {
        if ($c === null) return '';
        if (is_bool($c)) return $c ? 'TRUE' : 'FALSE';
        if (is_float($c) && floor($c) === $c && abs($c) < 1e15) return (string) (int) $c;
        return (string) $c;
    }

    /**
     * Used range -> rows with absolute row numbers and exactly $width cells.
     * @param array{startRow:int,startCol:int,values:array<int,array<int,mixed>>} $used
     * @return array<int,array{row:int,cells:array<int,mixed>}>
     */
    public static function toRows(array $used, int $width): array
    {
        $rows = [];
        foreach ($used['values'] as $i => $vals) {
            $cells = [];
            for ($col = 0; $col < $width; $col++) {
                $idx = $col - $used['startCol'];
                $cells[] = ($idx >= 0 && $idx < count($vals)) ? $vals[$idx] : null;
            }
            $rows[] = ['row' => $used['startRow'] + $i, 'cells' => $cells];
        }
        return $rows;
    }

    public static function headerCell(array $rows, int $col): mixed
    {
        foreach ($rows as $r) if ($r['row'] === 1) return $r['cells'][$col] ?? null;
        return null;
    }

    public static function headerIs(mixed $value, string $expected): bool
    {
        return is_string($value) && strtolower(trim($value)) === strtolower($expected);
    }

    /** Column R counts as Role Sub-Group only when R1 says so. */
    public static function hasSubGroup(array $rows): bool
    {
        return self::headerIs(self::headerCell($rows, self::ROLE_SUB_GROUP), self::SUB_GROUP_HEADER);
    }

    /** True when S1, T1, U1 carry the decision-maker headers. */
    public static function hasDmDetails(array $rows): bool
    {
        foreach (self::DM_DETAIL_HEADERS as $col => $name) {
            if (!self::headerIs(self::headerCell($rows, $col), $name)) return false;
        }
        return true;
    }

    /** Blank any column whose header doesn't belong to us, so unrelated content is never read as data. */
    public static function withoutForeignColumns(array $rows, bool $checkDmDetails): array
    {
        $blank = [];
        if (!self::hasSubGroup($rows)) $blank[] = self::ROLE_SUB_GROUP;
        if ($checkDmDetails) {
            foreach (self::DM_DETAIL_HEADERS as $col => $name) {
                if (!self::headerIs(self::headerCell($rows, $col), $name)) $blank[] = $col;
            }
        }
        if (!$blank) return $rows;
        foreach ($rows as &$r) foreach ($blank as $col) if (array_key_exists($col, $r['cells'])) $r['cells'][$col] = null;
        return $rows;
    }

    public static function isHeaderRow(array $cells): bool
    {
        $a = $cells[0] ?? null;
        return is_string($a) && strtolower(trim($a)) === 'date found';
    }

    /** A separator has a date in A and nothing else. */
    public static function isSeparator(array $cells): bool
    {
        if (self::isBlank($cells[0] ?? null)) return false;
        for ($i = 1; $i < count($cells); $i++) if (!self::isBlank($cells[$i])) return false;
        return ExcelDate::cellToIso($cells[0]) !== null;
    }

    /** A data row has something after column A. */
    public static function isDataRow(array $cells): bool
    {
        if (self::isHeaderRow($cells)) return false;
        for ($i = 1; $i < count($cells); $i++) if (!self::isBlank($cells[$i])) return true;
        return false;
    }

    /** Last row with any value in A:Q (1 = header only). Never trusts max_row; R and later are ignored. */
    public static function lastValueRow(array $rows, int $width = self::BASE_COLS): int
    {
        $last = 1;
        foreach ($rows as $r) {
            if ($r['row'] > $last && !self::isRowBlank(array_slice($r['cells'], 0, $width))) $last = $r['row'];
        }
        return $last;
    }

    /**
     * Split Signals into blocks (separator date + rows), in sheet order.
     * @return array<int,array{date:?string,separatorRow:?int,rows:array}>
     */
    public static function blocks(array $rows): array
    {
        $blocks = [];
        $current = null;
        $orphans = [];
        foreach ($rows as $row) {
            if ($row['row'] < 2 || self::isHeaderRow($row['cells'])) continue;
            if (self::isSeparator($row['cells'])) {
                $blocks[] = ['date' => ExcelDate::cellToIso($row['cells'][0]), 'separatorRow' => $row['row'], 'rows' => []];
                $current = count($blocks) - 1;
                continue;
            }
            if (!self::isDataRow($row['cells'])) continue;
            if ($current !== null) {
                $blocks[$current]['rows'][] = $row;
            } else {
                $date = ExcelDate::cellToIso($row['cells'][0]);
                $k = $date ?? '';
                if (!isset($orphans[$k])) {
                    $blocks[] = ['date' => $date, 'separatorRow' => null, 'rows' => []];
                    $orphans[$k] = count($blocks) - 1;
                }
                $blocks[$orphans[$k]]['rows'][] = $row;
            }
        }
        return $blocks;
    }

    /** @return string[] distinct block dates, newest first */
    public static function blockDates(array $blocks): array
    {
        $d = [];
        foreach ($blocks as $b) if ($b['date']) $d[$b['date']] = true;
        $dates = array_keys($d);
        rsort($dates);
        return $dates;
    }

    public static function latestDate(array $blocks): ?string
    {
        $sep = [];
        $all = [];
        foreach ($blocks as $b) {
            if (!$b['date']) continue;
            $all[] = $b['date'];
            if ($b['separatorRow'] !== null) $sep[] = $b['date'];
        }
        $pool = $sep ?: $all;
        return $pool ? max($pool) : null;
    }
}
