<?php
// Pure helpers ported 1:1 from the Next.js app: Excel dates, Job URL keys, LinkedIn links, A1 addresses.
declare(strict_types=1);

namespace SignalDesk;

final class ExcelDate
{
    private const SECONDS_PER_DAY = 86400;
    private const MAX_SERIAL = 2958465; // 9999-12-31

    /** Excel (1900 system) serial -> yyyy-mm-dd, or null. Serial 60 is Excel's fictional 1900-02-29. */
    public static function serialToIso(mixed $serial): ?string
    {
        if (!is_int($serial) && !is_float($serial)) return null;
        if (!is_finite((float) $serial)) return null;
        $whole = (int) floor((float) $serial);
        if ($whole < 1 || $whole > self::MAX_SERIAL) return null;
        if ($whole === 60) return '1900-02-29';
        $days = $whole < 60 ? $whole + 1 : $whole;
        $epoch = gmmktime(0, 0, 0, 12, 30, 1899);
        return gmdate('Y-m-d', $epoch + $days * self::SECONDS_PER_DAY);
    }

    /** yyyy-mm-dd -> Excel serial, or null for an invalid date. */
    public static function isoToSerial(string $iso): ?int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) return null;
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($y === 1900 && $mo === 2 && $d === 29) return 60;
        if (!checkdate($mo, $d, $y)) return null;
        $epoch = gmmktime(0, 0, 0, 12, 30, 1899);
        $days = (int) round((gmmktime(0, 0, 0, $mo, $d, $y) - $epoch) / self::SECONDS_PER_DAY);
        $serial = $days < 61 ? $days - 1 : $days;
        return ($serial >= 1 && $serial <= self::MAX_SERIAL) ? $serial : null;
    }

    /** Any cell -> yyyy-mm-dd or null (serials, ISO strings, dd/mm/yyyy, serials stored as text). */
    public static function cellToIso(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) return self::serialToIso($value);
        if (!is_string($value)) return null;
        $s = trim($value);
        if ($s === '') return null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ].*)?$/', $s, $m)) return self::isoToSerial($m[1]) !== null ? $m[1] : null;
        if (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})$/', $s, $m)) {
            $c = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            return self::isoToSerial($c) !== null ? $c : null;
        }
        if (preg_match('/^\d{4,6}(\.\d+)?$/', $s)) return self::serialToIso((float) $s);
        return null;
    }

    /** Today's date as an Excel serial in the configured timezone. */
    public static function todaySerial(string $timezone): int
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        return (int) self::isoToSerial($today);
    }
}

final class JobUrl
{
    /** Query parameters that ARE the job's identity on some boards; everything else is stripped. */
    public const IDENTITY_PARAMS = ['jk', 'vjk', 'currentjobid', 'gh_jid', 'jobid', 'job_id'];

    /** Normalised Job URL key: trim, drop #fragment and query (except identity params), trailing slashes; lower-case scheme+host. */
    public static function normalise(mixed $raw): string
    {
        if ($raw === null) return '';
        $s = trim(is_scalar($raw) ? (string) $raw : '');
        if ($s === '') return '';
        $hash = strpos($s, '#');
        if ($hash !== false) $s = substr($s, 0, $hash);
        $query = '';
        $q = strpos($s, '?');
        if ($q !== false) {
            $query = substr($s, $q + 1);
            $s = substr($s, 0, $q);
        }
        $s = rtrim($s, '/');
        $s = preg_replace_callback('~^([a-z][a-z0-9+.\-]*://)([^/]+)~i', fn ($m) => strtolower($m[1]) . strtolower($m[2]), $s) ?? $s;
        $kept = [];
        foreach (explode('&', $query) as $p) {
            if ($p === '') continue;
            $name = strtolower(explode('=', $p, 2)[0]);
            if (in_array($name, self::IDENTITY_PARAMS, true)) $kept[] = $p;
        }
        sort($kept);
        return $kept ? $s . '?' . implode('&', $kept) : $s;
    }
}

final class LinkedIn
{
    public const MAX_LINKS = 10;
    private const MAX_LENGTH = 500;

    /** @return array{ok:bool, url?:string, error?:string} */
    public static function normalise(string $input): array
    {
        $s = trim($input);
        if ($s === '') return ['ok' => false, 'error' => 'Empty link'];
        if (strlen($s) > self::MAX_LENGTH) return ['ok' => false, 'error' => 'Link is too long'];
        if (preg_match('/\s/', $s)) return ['ok' => false, 'error' => 'Link must not contain spaces'];
        if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $s)) $s = 'https://' . $s;
        $u = parse_url($s);
        if ($u === false || !isset($u['scheme'], $u['host'])) return ['ok' => false, 'error' => 'Not a valid link'];
        $scheme = strtolower($u['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') return ['ok' => false, 'error' => 'Not a valid link'];
        if (isset($u['user']) || isset($u['pass']) || isset($u['port'])) return ['ok' => false, 'error' => 'Not a valid link'];
        if (!preg_match('/^(?:www\.|[a-z]{2}\.)?linkedin\.com$/', strtolower($u['host']))) return ['ok' => false, 'error' => 'Must be a linkedin.com link'];
        $path = $u['path'] ?? '';
        if (!preg_match('~^/(in|sales)/[^/]+~', $path, $m)) return ['ok' => false, 'error' => 'Use a linkedin.com/in/… or linkedin.com/sales/… link'];
        $path = rtrim($path, '/');
        $url = 'https://www.linkedin.com' . $path;
        if ($m[1] === 'sales' && isset($u['query']) && $u['query'] !== '') $url .= '?' . $u['query'];
        return ['ok' => true, 'url' => $url];
    }

    /** @param string[] $inputs @return array{links:string[], errors:array<int,?string>, tooMany:bool} */
    public static function validateList(array $inputs): array
    {
        $links = [];
        $errors = [];
        foreach (array_values($inputs) as $i => $raw) {
            $errors[$i] = null;
            if (!is_string($raw) || trim($raw) === '') continue;
            $r = self::normalise($raw);
            if (!$r['ok']) $errors[$i] = $r['error'];
            elseif (!in_array($r['url'], $links, true)) $links[] = $r['url'];
        }
        return ['links' => $links, 'errors' => $errors, 'tooMany' => count($links) > self::MAX_LINKS];
    }

    /** @return string[] */
    public static function splitCell(mixed $value): array
    {
        if ($value === null || $value === '') return [];
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $value) ?: []), fn ($s) => $s !== ''));
    }

    /** Key used for duplicate protection: each link normalised when valid, sorted, joined. */
    public static function key(mixed $cell): string
    {
        $parts = [];
        foreach (self::splitCell($cell) as $l) {
            $r = self::normalise($l);
            $parts[] = $r['ok'] ? $r['url'] : strtolower($l);
        }
        sort($parts);
        return implode("\n", $parts);
    }
}

final class A1
{
    public static function colToIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }

    public static function indexToCol(int $index): string
    {
        $s = '';
        $i = $index + 1;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }

    /** "A1", "A2:Q5" or "'Sheet'!A1:Q5" -> [startRow, startCol, endRow, endCol] (rows 1-based, cols 0-based). */
    public static function parse(string $address): array
    {
        $bang = strrpos($address, '!');
        $local = trim($bang === false ? $address : substr($address, $bang + 1));
        $parts = explode(':', $local);
        if (count($parts) > 2) throw new \InvalidArgumentException("Invalid A1 address: $address");
        $re = '/^\$?([A-Za-z]{1,3})\$?(\d{1,7})$/';
        if (!preg_match($re, $parts[0], $a) || !preg_match($re, $parts[1] ?? $parts[0], $b)) {
            throw new \InvalidArgumentException("Invalid A1 address: $address");
        }
        $r1 = (int) $a[2];
        $r2 = (int) $b[2];
        if ($r1 < 1 || $r2 < 1) throw new \InvalidArgumentException("Invalid A1 address: $address");
        $c1 = self::colToIndex($a[1]);
        $c2 = self::colToIndex($b[1]);
        return [min($r1, $r2), min($c1, $c2), max($r1, $r2), max($c1, $c2)];
    }
}
