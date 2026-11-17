<?php
// SQLite on 20i shared hosting (network file system): robust storage for storage/lemlist.sqlite.
//  - rollback journal (journal_mode=DELETE), never WAL: WAL needs a shared-memory file (-shm) that fails on network
//    file systems with "disk I/O error"; an old WAL database is converted once (opened in EXCLUSIVE locking mode, which
//    never touches the -shm file)
//  - every write runs under storage/lemlist.lock (flock), in short transactions: the cron and web requests never write at once
//  - the webhook never opens SQLite: it appends to storage/lemlist-hooks.jsonl (ids and types only), the cron imports it
//  - app-only data (call log, outcomes) lives in its own small file storage/lemlist-app.sqlite (attached as "app"),
//    backed up daily; lemlist.sqlite itself can always be rebuilt from lemlist
//  - I/O errors: one retry after 2 s, then a status file for /health.php; a corrupt database is renamed
//    (lemlist.sqlite.broken-<date>) and rebuilt on the next run
declare(strict_types=1);

namespace SignalDesk;

/** PDO that takes the store's write lock for every writing statement (INSERT/UPDATE/DELETE/CREATE/…). */
final class LemlistPdo extends \PDO
{
    public function __construct(string $dsn, private LemlistStore $store)
    {
        parent::__construct($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STATEMENT_CLASS => [LemlistStmt::class, [$store]]]);
    }

    public static function writes(string $sql): bool
    {
        return preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|VACUUM|REINDEX)\b/i', $sql) === 1;
    }

    public function exec(string $statement): int|false
    {
        if (!self::writes($statement)) return parent::exec($statement);
        return $this->store->locked(fn () => parent::exec($statement));
    }
}

final class LemlistStmt extends \PDOStatement
{
    protected function __construct(private LemlistStore $store)
    {
    }

    public function execute(?array $params = null): bool
    {
        if (!LemlistPdo::writes($this->queryString)) return parent::execute($params);
        return $this->store->locked(fn () => parent::execute($params));
    }
}

/** A database that failed PRAGMA integrity_check. */
final class LemlistCorrupt extends \RuntimeException
{
}

/** storage/lemlist-db-status.json: the last cron outcome for /health.php (reasons and sizes only, never data). */
final class LemlistDbStatus
{
    public const FILE = 'lemlist-db-status.json';

    public static function isIo(\Throwable $e): bool
    {
        if ($e instanceof AppError) return $e->errCode === 'DB_BUSY';
        if (!$e instanceof \PDOException) return false;
        $code = (int) ($e->errorInfo[1] ?? 0);
        return in_array($code, [5, 6, 8, 10, 14, 15], true) // BUSY, LOCKED, READONLY, IOERR, CANTOPEN, PROTOCOL
            || preg_match('/disk I\/O|unable to open|database is locked|readonly database|locking protocol/i', $e->getMessage()) === 1;
    }

    public static function isCorrupt(\Throwable $e): bool
    {
        if ($e instanceof LemlistCorrupt) return true;
        if (!$e instanceof \PDOException) return false;
        return in_array((int) ($e->errorInfo[1] ?? 0), [11, 26], true) // CORRUPT, NOTADB
            || preg_match('/malformed|not a database|file is encrypted/i', $e->getMessage()) === 1;
    }

    /** Free space, file sizes and leftover journal files (no data). */
    public static function diag(string $dir): array
    {
        $f = "$dir/lemlist.sqlite";
        $free = @disk_free_space($dir);
        return ['freeBytes' => $free === false ? null : (int) $free, 'dbBytes' => is_file($f) ? (int) filesize($f) : null,
            'appBytes' => is_file("$dir/lemlist-app.sqlite") ? (int) filesize("$dir/lemlist-app.sqlite") : null,
            'journal' => is_file("$f-journal"), 'wal' => is_file("$f-wal"), 'shm' => is_file("$f-shm")];
    }

    public static function read(string $dir): array
    {
        return JsonStore::read("$dir/" . self::FILE, []) ?: [];
    }

    public static function ok(string $dir, int $now): void
    {
        $prev = self::read($dir);
        JsonStore::write("$dir/" . self::FILE, ['state' => 'ok', 'at' => $now] + self::diag($dir) + array_intersect_key($prev, ['rebuiltAt' => 1, 'brokenFile' => 1]));
    }

    public static function fail(string $dir, int $now, \Throwable|string $why): void
    {
        $reason = is_string($why) ? $why : self::reason($why);
        JsonStore::write("$dir/" . self::FILE, ['state' => 'error', 'at' => $now, 'reason' => $reason] + self::diag($dir));
    }

    /** The SQLite message only (it names no rows or values), shortened. */
    public static function reason(\Throwable $e): string
    {
        return mb_substr(preg_replace('/^SQLSTATE\[\w+\]:\s*/', '', $e->getMessage()) ?? $e->getMessage(), 0, 200);
    }

    /** /health.php row. */
    public static function healthRow(string $dir, int $now): ?array
    {
        $s = self::read($dir);
        if (!$s) return null;
        $mb = fn ($b) => $b === null ? '?' : number_format($b / 1048576, 1) . ' MB';
        $files = implode(', ', array_keys(array_filter(['-journal' => $s['journal'] ?? false, '-wal' => $s['wal'] ?? false, '-shm' => $s['shm'] ?? false]))) ?: 'none';
        $facts = 'free ' . $mb($s['freeBytes'] ?? null) . ', lemlist.sqlite ' . $mb($s['dbBytes'] ?? null) . ', leftover files: ' . $files;
        $when = gmdate('Y-m-d H:i', (int) ($s['at'] ?? 0)) . ' UTC';
        if (($s['state'] ?? '') === 'error') {
            return ['lemlist storage', 'FAIL', 'ERROR ' . ($s['reason'] ?? '?') . " (cron $when; $facts). Run bin/lemlist-sync.php --check-db"];
        }
        if (($s['state'] ?? '') === 'rebuild') {
            return ['lemlist storage', 'WARN', 'the database was damaged and renamed to ' . ($s['brokenFile'] ?? '?') . " ($when): the next cron run rebuilds it from lemlist; call log and outcomes are kept"];
        }
        $rebuilt = !empty($s['rebuiltAt']) && (int) $s['rebuiltAt'] > $now - 7 * 86400 ? '; rebuilt from lemlist on ' . gmdate('Y-m-d', (int) $s['rebuiltAt']) : '';
        return ['lemlist storage', 'OK', "last cron $when; $facts$rebuilt"];
    }

    /**
     * A corrupt lemlist.sqlite: back up the app data first, then rename the database (and its journal files)
     * to lemlist.sqlite.broken-<date>; the next run starts an empty database and syncs everything again.
     */
    public static function quarantine(string $dir, int $now, string $reason): string
    {
        $f = "$dir/lemlist.sqlite";
        self::backupApp($dir, $now, true);
        $broken = 'lemlist.sqlite.broken-' . gmdate('Ymd-His', $now);
        gc_collect_cycles(); // a half-opened connection must not keep the file open (Windows cannot rename it)
        JsonStore::locked("$dir/lemlist.lock", function () use ($f, $dir, $broken) {
            foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
                if (is_file($f . $suffix)) @rename($f . $suffix, "$dir/$broken$suffix");
            }
        });
        JsonStore::write("$dir/" . self::FILE, ['state' => 'rebuild', 'at' => $now, 'reason' => mb_substr($reason, 0, 200), 'brokenFile' => $broken] + self::diag($dir));
        Audit::event('lemlist_db', ['event' => 'corrupt', 'renamedTo' => $broken]);
        return $broken;
    }

    /** Copy storage/lemlist-app.sqlite (call log, outcomes) to lemlist-app.sqlite.bak-<date>; keeps the newest 7. */
    public static function backupApp(string $dir, int $now, bool $force = false): ?string
    {
        $src = "$dir/lemlist-app.sqlite";
        if (!is_file($src)) return null;
        $dest = "$src.bak-" . gmdate('Ymd', $now);
        if (!$force && is_file($dest)) return null;
        JsonStore::locked("$dir/lemlist.lock", fn () => @copy($src, $dest));
        @chmod($dest, 0600);
        $all = glob("$src.bak-*") ?: [];
        rsort($all);
        foreach (array_slice($all, 7) as $old) @unlink($old);
        return basename($dest);
    }
}

/**
 * Webhook events: appended to storage/lemlist-hooks.jsonl (ids, types and a masked 90-character reply line only),
 * imported into SQLite by the cron. storage/lemlist-hook-state.json keeps what the pages need at once
 * (last event, inbox/tasks changed, tasks closed in lemlist, alerts, refused deliveries).
 */
final class HookBuffer
{
    public const FILE = 'lemlist-hooks.jsonl';
    public const WORK = 'lemlist-hooks.importing';
    public const STATE = 'lemlist-hook-state.json';
    public const MAX_BYTES = 20_000_000;
    private const KEEP = ['_id', 'type', 'createdAt', 'leadId', 'campaignId', 'contactId', 'stepId', 'sequenceStep', 'totalSequenceStep', 'sendUserId', 'taskId'];

    public static function state(string $dir): array
    {
        return JsonStore::read("$dir/" . self::STATE, []) ?: [];
    }

    public static function updateState(string $dir, callable $fn): array
    {
        return JsonStore::update("$dir/" . self::STATE, [], fn ($s) => $fn(is_array($s) ? $s : []));
    }

    /** The fields Signal Desk uses; never the lead's email, phone, name or custom fields. */
    public static function slim(array $d, string $id, string $type, int $t): array
    {
        $row = ['_id' => $id, 'type' => $type, 'receivedAt' => $t];
        foreach (self::KEEP as $k) if (!isset($row[$k]) && (is_string($d[$k] ?? null) || is_int($d[$k] ?? null))) $row[$k] = is_string($d[$k]) ? mb_substr($d[$k], 0, 120) : $d[$k];
        if (preg_match('/Replied$/', $type)) {
            foreach (['text', 'message', 'body', 'emailText'] as $k) {
                if (is_string($d[$k] ?? null) && trim($d[$k]) !== '') {
                    $row['snippet'] = LemlistSync::maskTitle(CallDesk::snippet($d[$k])); // the inbox line; emails/phones masked
                    break;
                }
            }
        }
        return $row;
    }

    /** Append one event (deduplicated on its id) and update the live state. @return bool true when it was a redelivery */
    public static function push(string $dir, array $row, int $t): bool
    {
        $dup = false;
        self::updateState($dir, function (array $s) use ($dir, $row, $t, &$dup) {
            $s['last_event_at'] = $t;
            $recent = $s['recent'] ?? [];
            if (isset($recent[$row['_id']])) {
                $dup = true;
                return $s;
            }
            $file = "$dir/" . self::FILE;
            if (is_file($file) && filesize($file) > self::MAX_BYTES) throw new AppError(503, 'HOOK_BUFFER_FULL', 'Webhook buffer full: is the lemlist-sync cron running?');
            JsonStore::locked("$dir/lemlist-hooks.lock", function () use ($file, $row) {
                if (file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND) === false) throw new \RuntimeException('Cannot write the webhook buffer');
                @chmod($file, 0600);
            });
            $recent[$row['_id']] = $t;
            $s['recent'] = array_slice($recent, -2000, null, true);
            $type = (string) $row['type'];
            if (preg_match('/Replied$|^linkedinInterested$|^emailsInterested$|^opportunityReplied$/', $type)) $s['inbox_event_at'] = $t;
            $taskId = (string) ($row['taskId'] ?? '');
            if ($taskId !== '' || preg_match('/^(aircall|manual)(Done|Ignored)$|^taskDone$|^skipped$/', $type)) $s['tasks_event_at'] = $t;
            if (preg_match('/^opp_[A-Za-z0-9]{3,60}$/', $taskId) && preg_match('/Done$|Ignored$|^skipped$|^aircallEnded$/', $type)) {
                $closed = array_filter($s['closed_tasks'] ?? [], fn ($at) => $at > $t - 7 * 86400);
                $closed[$taskId] = $t;
                $s['closed_tasks'] = array_slice($closed, -500, null, true);
            }
            if (in_array($type, ['sendLimitReached', 'connectionIssue', 'customDomainErrors', 'lemwarmPaused', 'deliverabilityAlertTriggered'], true)) {
                $s['alerts'] = array_slice(array_merge($s['alerts'] ?? [], [['type' => $type, 'at' => $t]]), -50);
            }
            return $s;
        });
        return $dup;
    }

    /** Buffered events not imported yet, and when the oldest arrived. */
    public static function pending(string $dir): array
    {
        $n = 0;
        $oldest = null;
        foreach ([self::WORK, self::FILE] as $f) {
            if (!is_file("$dir/$f")) continue;
            $fh = fopen("$dir/$f", 'rb');
            while ($fh && ($line = fgets($fh)) !== false) {
                if (trim($line) === '') continue;
                $n++;
                if ($oldest === null) $oldest = (int) ((json_decode($line, true)['receivedAt'] ?? 0));
            }
            if ($fh) fclose($fh);
        }
        return ['count' => $n, 'oldest' => $oldest];
    }

    /** Cron: move the buffer aside, store its events in batches of 200, then delete it. @return int new events */
    public static function import(LemlistStore $store): int
    {
        $dir = $store->dir;
        $file = "$dir/" . self::FILE;
        $work = "$dir/" . self::WORK;
        if (!is_file($work)) {
            JsonStore::locked("$dir/lemlist-hooks.lock", function () use ($file, $work) {
                if (is_file($file)) rename($file, $work);
            });
        }
        if (!is_file($work)) return 0;
        $rows = [];
        foreach (file($work, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && is_string($r['_id'] ?? null)) $rows[] = $r;
        }
        $new = 0;
        $last = 0;
        $store->batch($rows, function (array $r) use ($store, &$new, &$last) {
            $t = (int) ($r['receivedAt'] ?? 0);
            $last = max($last, $t);
            $fresh = $store->addEvent(['id' => mb_substr($r['_id'], 0, 80), 'type' => (string) $r['type'], 'created_at' => (string) ($r['createdAt'] ?? gmdate('c', $t)), 'received_at' => $t,
                'lead_id' => $r['leadId'] ?? null, 'campaign_id' => $r['campaignId'] ?? null, 'contact_id' => $r['contactId'] ?? null]);
            if (!$fresh) return;
            $new++;
            if (str_starts_with($r['_id'], 'act_')) $store->addActivity(LemlistSync::activityRow($r, 'hook'));
            if (!empty($r['snippet']) && is_string($r['contactId'] ?? null)) $store->setSnippet($r['contactId'], (string) $r['snippet'], (string) ($r['createdAt'] ?? gmdate('Y-m-d\TH:i:s.000\Z', $t)), $t);
            $taskId = (string) ($r['taskId'] ?? '');
            if (preg_match('/^opp_[A-Za-z0-9]{3,60}$/', $taskId) && preg_match('/Done$|Ignored$|^skipped$|^aircallEnded$/', (string) $r['type'])) {
                $store->db->prepare("UPDATE tasks SET status = 'closed' WHERE id = ?")->execute([$taskId]);
            }
        });
        if ($last && $last > (int) $store->meta('last_event_at', 0)) $store->setMeta('last_event_at', $last);
        @unlink($work);
        return $new;
    }
}

/** The cron's database handling: open (with a daily integrity check), run, close; one retry on I/O errors; status for /health. */
final class LemlistCron
{
    public const RETRY_MS = 2000;

    /**
     * @param callable(LemlistStore): mixed $body the sync work
     * @param callable(int $ms): void|null $sleep
     * @return array{ok:bool, out?:mixed, error?:string, tries:int, rebuild?:string}
     */
    public static function guarded(string $dir, callable $body, ?callable $sleep = null, ?int $now = null): array
    {
        $sleep ??= fn (int $ms) => usleep($ms * 1000);
        $now ??= time();
        for ($try = 1; ; $try++) {
            $store = null;
            try {
                $store = self::open($dir, $now);
                $out = $body($store);
                $store->close();
                LemlistDbStatus::backupApp($dir, $now);
                LemlistDbStatus::ok($dir, $now);
                return ['ok' => true, 'out' => $out, 'tries' => $try];
            } catch (\Throwable $e) {
                $store?->close();
                if (LemlistDbStatus::isCorrupt($e)) {
                    $broken = LemlistDbStatus::quarantine($dir, $now, LemlistDbStatus::reason($e));
                    return ['ok' => false, 'error' => 'database damaged (' . LemlistDbStatus::reason($e) . "): renamed to $broken, rebuilt from lemlist on the next run", 'tries' => $try, 'rebuild' => $broken];
                }
                if (!LemlistDbStatus::isIo($e)) throw $e; // lemlist API errors etc.: the caller reports them
                if ($try < 2) {
                    $sleep(self::RETRY_MS);
                    continue;
                }
                LemlistDbStatus::fail($dir, $now, $e);
                return ['ok' => false, 'error' => LemlistDbStatus::reason($e), 'tries' => $try];
            }
        }
    }

    /** Open lemlist.sqlite; once a day PRAGMA integrity_check (a failure throws LemlistCorrupt). Remembers a fresh rebuild. */
    public static function open(string $dir, int $now): LemlistStore
    {
        $fresh = !is_file("$dir/lemlist.sqlite");
        $store = new LemlistStore("$dir/lemlist.sqlite");
        if ($fresh && (LemlistDbStatus::read($dir)['state'] ?? '') === 'rebuild') {
            $s = LemlistDbStatus::read($dir);
            JsonStore::write("$dir/" . LemlistDbStatus::FILE, ['state' => 'ok', 'at' => $now, 'rebuiltAt' => $now, 'brokenFile' => $s['brokenFile'] ?? null] + LemlistDbStatus::diag($dir));
        }
        try {
            if ((int) $store->meta('integrity_at', 0) < $now - 86400) {
                $bad = $store->integrity('main');
                if ($bad) throw new LemlistCorrupt('integrity_check failed: ' . mb_substr(implode('; ', $bad), 0, 150));
                $store->setMeta('integrity_at', $now);
            }
        } catch (\Throwable $e) {
            $store->close();
            throw $e;
        }
        return $store;
    }

    /** bin/lemlist-sync.php --check-db: journal mode, sizes, free space, integrity (no data). */
    public static function checkDb(string $dir): array
    {
        $mb = fn ($b) => $b === null ? 'missing' : number_format($b / 1048576, 2) . ' MB';
        $before = LemlistDbStatus::diag($dir); // leftover files as found, before opening converts them
        $lines = [];
        $version = '?';
        try {
            $store = new LemlistStore("$dir/lemlist.sqlite");
            $version = (string) $store->one('SELECT sqlite_version()');
            $lines[] = 'journal_mode: main=' . $store->one('PRAGMA main.journal_mode') . ', app=' . $store->one('PRAGMA app.journal_mode') . '; synchronous=' . $store->one('PRAGMA synchronous') . ', busy_timeout=' . $store->one('PRAGMA busy_timeout');
            foreach (['main' => 'lemlist.sqlite', 'app' => 'lemlist-app.sqlite'] as $schema => $name) {
                $bad = $store->integrity($schema);
                $lines[] = "integrity_check $name: " . ($bad ? 'FAILED: ' . mb_substr(implode('; ', array_slice($bad, 0, 5)), 0, 300) : 'ok');
            }
            $lines[] = 'rows: ' . $store->one('SELECT COUNT(*) FROM campaigns') . ' campaigns, ' . $store->one('SELECT COUNT(*) FROM leads') . ' leads, ' . $store->one('SELECT COUNT(*) FROM activities') . ' activities, '
                . $store->one('SELECT COUNT(*) FROM app.calls') . ' logged calls, ' . $store->one('SELECT COUNT(*) FROM app.outcomes') . ' outcomes';
            $store->close();
        } catch (\Throwable $e) {
            $lines[] = 'open/check FAILED: ' . LemlistDbStatus::reason($e) . (LemlistDbStatus::isCorrupt($e) ? ' (damaged: the next cron run renames it and rebuilds from lemlist)' : '');
        }
        $d = LemlistDbStatus::diag($dir);
        $out = array_merge(['SQLite ' . $version . ', PHP ' . PHP_VERSION . ', pdo_sqlite ' . (extension_loaded('pdo_sqlite') ? 'yes' : 'NO'),
            'storage: ' . $dir . ', free ' . $mb($d['freeBytes']), 'lemlist.sqlite: ' . $mb($d['dbBytes']) . ', lemlist-app.sqlite: ' . $mb($d['appBytes']),
            'leftover files before opening: -journal ' . ($before['journal'] ? 'yes' : 'no') . ', -wal ' . ($before['wal'] ? 'yes' : 'no') . ', -shm ' . ($before['shm'] ? 'yes' : 'no')
                . (LemlistStore::walLeftover("$dir/lemlist.sqlite") ? ' (still WAL!)' : '')], $lines);
        $p = HookBuffer::pending($dir);
        $out[] = 'buffered webhook events: ' . $p['count'] . ($p['oldest'] ? ' (oldest ' . gmdate('Y-m-d H:i', $p['oldest']) . ' UTC)' : '');
        $s = LemlistDbStatus::read($dir);
        if ($s) $out[] = 'last cron: ' . ($s['state'] ?? '?') . ' at ' . gmdate('Y-m-d H:i', (int) ($s['at'] ?? 0)) . ' UTC' . (isset($s['reason']) ? ': ' . $s['reason'] : '');
        return $out;
    }
}
