<?php
// lemlist storage on 20i (network file system): rollback journal (never WAL), leftover WAL converted once, app-only data in its
// own file, short batched transactions under one lock, webhook buffer imported by the cron, I/O retry + status for /health,
// --check-db, and a damaged database renamed and rebuilt with the call log and outcomes kept.
declare(strict_types=1);

use SignalDesk\AppError;
use SignalDesk\HookBuffer;
use SignalDesk\LemlistCron;
use SignalDesk\LemlistDbStatus;
use SignalDesk\LemlistStore;
use SignalDesk\LemlistSync;
use SignalDesk\LemlistWebhook;

const T_DB_NOW = 1_794_600_000;

function t_db_ioerror(): PDOException
{
    $e = new PDOException('SQLSTATE[HY000]: General error: 10 disk I/O error');
    $e->errorInfo = ['HY000', 10, 'disk I/O error'];
    return $e;
}

test('lemlist storage: rollback journal (DELETE), synchronous NORMAL, busy_timeout 15 s; call log and outcomes in lemlist-app.sqlite', function () {
    $dir = tmpdir();
    $s = new LemlistStore("$dir/lemlist.sqlite");
    eq(['delete', 'delete', 1, 15000], [$s->one('PRAGMA main.journal_mode'), $s->one('PRAGMA app.journal_mode'), (int) $s->one('PRAGMA main.synchronous'), (int) $s->one('PRAGMA busy_timeout')]);
    eq(['calls', 'outcomes'], array_column($s->all("SELECT name FROM app.sqlite_master WHERE type = 'table' ORDER BY name"), 'name'));
    eq(0, (int) $s->one("SELECT COUNT(*) FROM main.sqlite_master WHERE name IN ('calls','outcomes')"), 'not in the re-syncable database');
    $s->db->prepare('INSERT INTO outcomes (lead_id, outcome, by_user, at) VALUES (?,?,?,?)')->execute(['lea_1', 'Meeting', 'jp', 1]);
    eq('Meeting', $s->one('SELECT outcome FROM app.outcomes'), 'unqualified names reach the app file');
    $s->close();
    ok(!is_file("$dir/lemlist.sqlite-wal") && !is_file("$dir/lemlist.sqlite-shm"));
});

test('lemlist storage: a database left in WAL mode (with -shm) is checkpointed and switched to DELETE once, data kept', function () {
    $dir = tmpdir();
    $pdo = new PDO("sqlite:$dir/lemlist.sqlite");
    $pdo->exec("PRAGMA journal_mode=WAL; CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT); INSERT INTO meta VALUES ('kept', '\"yes\"');");
    $pdo = null;
    file_put_contents("$dir/lemlist.sqlite-shm", str_repeat("\0", 32768)); // leftover from the old WAL setup
    ok(LemlistStore::walLeftover("$dir/lemlist.sqlite"));
    $s = new LemlistStore("$dir/lemlist.sqlite");
    eq('delete', $s->one('PRAGMA main.journal_mode'));
    eq('yes', $s->meta('kept'));
    $s->close();
    ok(!is_file("$dir/lemlist.sqlite-wal") && !is_file("$dir/lemlist.sqlite-shm"), 'no -wal/-shm left');
    ok(!LemlistStore::walLeftover("$dir/lemlist.sqlite"));
    ok(str_contains(json_encode($GLOBALS['__events']), 'wal_to_delete'));
});

test('lemlist storage: outcomes and call log of an older database move to lemlist-app.sqlite', function () {
    $dir = tmpdir();
    $pdo = new PDO("sqlite:$dir/lemlist.sqlite");
    $pdo->exec("CREATE TABLE outcomes (lead_id TEXT PRIMARY KEY, outcome TEXT, by_user TEXT, at INTEGER); INSERT INTO outcomes VALUES ('lea_9', 'Deal', 'jp', 5);
        CREATE TABLE calls (external_id TEXT PRIMARY KEY, lemlist_id TEXT, task_id TEXT, lead_id TEXT, user_id TEXT, by_user TEXT, call_status TEXT, started_at TEXT, created_at INTEGER, answered INTEGER);
        INSERT INTO calls (external_id, lead_id, answered) VALUES ('sd_x1', 'lea_9', 1);");
    $pdo = null;
    $s = new LemlistStore("$dir/lemlist.sqlite");
    eq(['Deal', 'lea_9'], [$s->one('SELECT outcome FROM app.outcomes'), $s->one('SELECT lead_id FROM app.calls')]);
    eq(0, (int) $s->one("SELECT COUNT(*) FROM main.sqlite_master WHERE name IN ('calls','outcomes')"));
    $s->close();
});

test('lemlist storage: writes in short transactions of 200 rows (sync, linker, tasks)', function () {
    $dir = tmpdir();
    $s = new LemlistStore("$dir/lemlist.sqlite");
    $tx = $s->transactions;
    eq(450, $s->batch(range(1, 450), fn ($i) => $s->setMeta("k$i", $i)));
    eq(3, $s->transactions - $tx, '200 + 200 + 50');
    eq(450, (int) $s->one("SELECT COUNT(*) FROM meta WHERE key LIKE 'k%'"));
    $tx = $s->transactions;
    throws(fn () => $s->write(function () use ($s) { $s->setMeta('half', 1); throw new RuntimeException('boom'); }), RuntimeException::class);
    eq(null, $s->meta('half'), 'a failed batch is rolled back');
    $s->close();
    [$store] = t_lem_synced();
    ok($store->transactions >= 5, 'the sync writes through transactions');
});

test('lemlist storage: one lock for all writers; a writer waits, then gives a clear "busy" error; reads keep working', function () {
    $dir = tmpdir();
    $s = new LemlistStore("$dir/lemlist.sqlite");
    $s->setMeta('a', 1);
    $s->lockWaitMs = 200;
    $other = fopen("$dir/lemlist.lock", 'c'); // the cron holding the lock
    ok(flock($other, LOCK_EX | LOCK_NB));
    eq('DB_BUSY', throws(fn () => $s->setMeta('b', 2), AppError::class)->errCode, 'a plain write takes the lock too');
    eq('DB_BUSY', throws(fn () => $s->write(fn () => $s->setMeta('b', 2)), AppError::class)->errCode);
    eq(1, $s->meta('a'), 'reads do not need the lock');
    flock($other, LOCK_UN);
    fclose($other);
    $s->setMeta('b', 2);
    eq(2, $s->meta('b'));
    $s->close();
});

test('lemlist webhook buffer: an import interrupted half-way is finished next time; events arriving during an import are not lost', function () {
    $cfg = t_lem_config();
    [, $store] = t_lem();
    $send = fn (string $id) => LemlistWebhook::handle($cfg, 'POST', json_encode(['_id' => $id, 'type' => 'emailsOpened', 'secret' => T_LEM_SECRET, 'leadId' => 'lea_it01']), $store, fn () => T_DB_NOW);
    $send('act_b1');
    $send('act_b2');
    rename($store->dir . '/' . HookBuffer::FILE, $store->dir . '/' . HookBuffer::WORK); // the cron died after moving the buffer aside
    $send('act_b3'); // a new event meanwhile
    eq(['count' => 3, 'oldest' => T_DB_NOW], HookBuffer::pending($store->dir));
    eq(2, HookBuffer::import($store), 'first the interrupted file');
    eq(1, HookBuffer::import($store), 'then the new one');
    eq(0, HookBuffer::import($store));
    eq(3, (int) $store->one("SELECT COUNT(*) FROM events WHERE id LIKE 'act_b%'"));
    eq(0, HookBuffer::pending($store->dir)['count']);
});

test('lemlist cron: an I/O error is retried once after 2 s; a second failure writes the reason, sizes and leftover files for /health', function () {
    $dir = tmpdir();
    $sleeps = [];
    $tries = 0;
    $r = LemlistCron::guarded($dir, function (LemlistStore $s) use (&$tries) {
        if (++$tries === 1) throw t_db_ioerror();
        $s->setMeta('last_sync_at', T_DB_NOW);
        return 'synced';
    }, function (int $ms) use (&$sleeps) { $sleeps[] = $ms; }, T_DB_NOW);
    eq([true, 'synced', 2, [2000]], [$r['ok'], $r['out'], $r['tries'], $sleeps]);
    eq('ok', LemlistDbStatus::read($dir)['state']);
    eq('OK', LemlistDbStatus::healthRow($dir, T_DB_NOW)[1]);
    $r = LemlistCron::guarded($dir, fn () => throw t_db_ioerror(), fn () => null, T_DB_NOW + 900);
    eq([false, 2], [$r['ok'], $r['tries']]);
    $st = LemlistDbStatus::read($dir);
    eq(['error', 'General error: 10 disk I/O error'], [$st['state'], $st['reason']]);
    ok(is_int($st['freeBytes']) && is_int($st['dbBytes']) && array_key_exists('wal', $st) && array_key_exists('shm', $st) && array_key_exists('journal', $st));
    $row = LemlistDbStatus::healthRow($dir, T_DB_NOW + 1000);
    eq(['lemlist storage', 'FAIL'], [$row[0], $row[1]]);
    ok(str_starts_with($row[2], 'ERROR General error: 10 disk I/O error') && str_contains($row[2], 'free ') && str_contains($row[2], 'leftover files: none'), $row[2]);
    // other errors (lemlist API etc.) are not swallowed
    throws(fn () => LemlistCron::guarded($dir, fn () => throw new RuntimeException('lemlist API down'), fn () => null), RuntimeException::class);
    // the connection is closed after a run: the file can be renamed (Windows refuses while a handle is open)
    LemlistCron::guarded($dir, fn ($s) => $s->setMeta('x', 1), fn () => null, T_DB_NOW);
    ok(rename("$dir/lemlist.sqlite", "$dir/moved.sqlite") && rename("$dir/moved.sqlite", "$dir/lemlist.sqlite"), 'no open handle');
});

test('lemlist cron --check-db: journal mode, sizes, free space, integrity, buffered events; no personal data', function () {
    [$store, , $dir] = t_lem_synced();
    $store->close();
    $out = implode("\n", LemlistCron::checkDb($dir));
    foreach (['journal_mode: main=delete, app=delete', 'integrity_check lemlist.sqlite: ok', 'integrity_check lemlist-app.sqlite: ok', 'free ', 'lemlist.sqlite: ', 'buffered webhook events: 0', 'leftover files before opening: -journal no, -wal no, -shm no'] as $want) {
        ok(str_contains($out, $want), $want);
    }
    foreach (['mail.example', '+32 470', 'Robin', 'Replier BV'] as $pii) ok(!str_contains($out, $pii), "no $pii");
});

test('lemlist cron: a damaged database is renamed lemlist.sqlite.broken-<date> after backing up the app data, and rebuilt from lemlist next run', function () {
    [$store, $mock, $dir, $client] = t_lem_synced();
    $store->db->prepare('INSERT INTO outcomes (lead_id, outcome, by_user, at) VALUES (?,?,?,?)')->execute(['lea_it05', 'Meeting', 'jp', T_DB_NOW]);
    $store->close();
    // damage the middle of the file (page 3 onwards): integrity_check / reads fail
    $fh = fopen("$dir/lemlist.sqlite", 'r+b');
    fseek($fh, 4096 * 2);
    fwrite($fh, str_repeat("\xA5", 4096 * 3));
    fclose($fh);
    $r = LemlistCron::guarded($dir, fn ($s) => $s->one('SELECT COUNT(*) FROM leads'), fn () => null, T_DB_NOW);
    eq(false, $r['ok']);
    ok(str_contains($r['error'], 'rebuilt from lemlist on the next run'), $r['error']);
    ok(!is_file("$dir/lemlist.sqlite") && is_file("$dir/" . $r['rebuild']) && str_starts_with($r['rebuild'], 'lemlist.sqlite.broken-'), 'renamed');
    ok((bool) glob("$dir/lemlist-app.sqlite.bak-*"), 'app data backed up first');
    eq('WARN', LemlistDbStatus::healthRow($dir, T_DB_NOW)[1]);
    // next run: a fresh database, synced again from lemlist; outcomes (app file) survive
    $r = LemlistCron::guarded($dir, function (LemlistStore $s) use ($client) {
        $sync = new LemlistSync($client, $s);
        $sync->now = fn () => T_DB_NOW;
        return $sync->run(600)['result'];
    }, fn () => null, T_DB_NOW + 900);
    eq([true, 'done'], [$r['ok'], $r['out']]);
    $s = new LemlistStore("$dir/lemlist.sqlite");
    ok((int) $s->one('SELECT COUNT(*) FROM leads') > 5, 'leads re-synced');
    eq('Meeting', $s->one("SELECT outcome FROM outcomes WHERE lead_id = 'lea_it05'"), 'outcomes kept');
    $s->close();
    $st = LemlistDbStatus::read($dir);
    eq(['ok', T_DB_NOW + 900], [$st['state'], $st['rebuiltAt']]);
    ok(str_contains(LemlistDbStatus::healthRow($dir, T_DB_NOW + 1000)[2], 'rebuilt from lemlist on'));
    // a file that is not SQLite at all is handled the same way
    file_put_contents("$dir/lemlist.sqlite", str_repeat('not a database ', 500));
    $r = LemlistCron::guarded($dir, fn ($s) => 1, fn () => null, T_DB_NOW + 2000);
    ok(!$r['ok'] && isset($r['rebuild']) && !is_file("$dir/lemlist.sqlite"));
});
