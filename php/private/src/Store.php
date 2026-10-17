<?php
// Small file-based storage: JSON files with locking, the audit log, and app-side rejections.
declare(strict_types=1);

namespace SignalDesk;

final class JsonStore
{
    public static function read(string $file, mixed $default): mixed
    {
        if (!is_file($file)) return $default;
        $fh = @fopen($file, 'rb');
        if (!$fh) return $default;
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        if ($raw === false || $raw === '') return $default;
        $data = json_decode($raw, true);
        return $data ?? $default;
    }

    /** Atomic write (temp file + rename), directory created 0700 if needed. */
    public static function write(string $file, mixed $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), LOCK_EX);
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            // Windows cannot rename over an open/existing file in some cases: fall back to copy.
            copy($tmp, $file);
            @unlink($tmp);
        }
    }

    /** Read-modify-write under an exclusive lock. $fn receives the current data and returns the new data. */
    public static function update(string $file, mixed $default, callable $fn): mixed
    {
        return self::locked($file . '.lock', function () use ($file, $default, $fn) {
            $new = $fn(self::read($file, $default));
            self::write($file, $new);
            return $new;
        });
    }

    /** Run $fn while holding an exclusive lock on $lockFile (serialises writers across requests). */
    public static function locked(string $lockFile, callable $fn): mixed
    {
        $dir = dirname($lockFile);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $fh = fopen($lockFile, 'c');
        if (!$fh) throw new \RuntimeException('Cannot open lock file');
        try {
            flock($fh, LOCK_EX);
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}

/** JSON-lines audit log in storage/audit.log. Tests can set Audit::$sink to capture entries. */
final class Audit
{
    public static ?string $file = null;
    /** @var callable|null */
    public static $sink = null;

    /** A write (or refused write): user, time, action, Job URL, sheet, row, outcome. */
    public static function write(string $user, string $action, string $jobUrl, string $sheet, ?int $row, string $outcome, ?string $detail = null): void
    {
        $e = ['type' => 'audit', 'time' => gmdate('c'), 'user' => $user, 'action' => $action, 'jobUrl' => $jobUrl, 'sheet' => $sheet, 'row' => $row, 'outcome' => $outcome];
        if ($detail !== null) $e['detail'] = $detail;
        self::emit($e);
    }

    public static function event(string $type, array $data): void
    {
        self::emit(['type' => $type, 'time' => gmdate('c')] + $data);
    }

    private static function emit(array $e): void
    {
        if (self::$sink) {
            (self::$sink)($e);
            return;
        }
        if (self::$file) @file_put_contents(self::$file, json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
}

/** Rejections live app-side only (never in the workbook): storage/rejections.json keyed by normalised Job URL. */
final class Rejections
{
    public function __construct(private string $file) {}

    /** @return array<string,array{jobUrl:string,date:string,user:string}> */
    public function all(): array
    {
        $d = JsonStore::read($this->file, []);
        return is_array($d) ? $d : [];
    }

    public function add(string $key, string $jobUrl, string $user): array
    {
        $entry = ['jobUrl' => $jobUrl, 'date' => gmdate('c'), 'user' => $user];
        JsonStore::update($this->file, [], function ($d) use ($key, $entry) {
            $d = is_array($d) ? $d : [];
            $d[$key] = $entry;
            return $d;
        });
        return $entry;
    }

    public function remove(string $key): bool
    {
        $existed = false;
        JsonStore::update($this->file, [], function ($d) use ($key, &$existed) {
            $d = is_array($d) ? $d : [];
            $existed = isset($d[$key]);
            unset($d[$key]);
            return $d;
        });
        return $existed;
    }
}
