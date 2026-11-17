<?php
// lemlist sync for the 20i cron job (every 15 minutes, its own line next to atlas-index.php):
//
//   php /home/sites/<site>/signal-desk-private/bin/lemlist-sync.php
//
// Reads running campaigns (steps, leads, stats), activities since the last run, open tasks and call statuses into
// storage/lemlist.sqlite, then links leads to the approved decision makers (Decision Makers / HR Decision Makers tabs,
// read-only) and their Atlas pool counts. Resumable: stops after --max-seconds (default 240) and carries on next run.
// Prunes data older than 12 months once a day. Output: counts only (no names, emails or phones). Exit 0 = OK.
// Storage (20i network file system): rollback journal, writes in short locked transactions, webhook events imported
// from storage/lemlist-hooks.jsonl, connection closed at the end. On an I/O error: one retry after 2 s, then the reason
// goes to /health.php. A damaged database is renamed (lemlist.sqlite.broken-<date>) and rebuilt from lemlist next run.
//   --max-seconds=N   time budget (default 240)
//   --no-link         skip the workbook/Atlas linking step
//   --check-db        print journal mode, file sizes, free space and integrity_check (no data), change nothing
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$privateDir = dirname(__DIR__);
require $privateDir . '/src/bootstrap.php';

use SignalDesk\Audit;
use SignalDesk\Config;
use SignalDesk\HookBuffer;
use SignalDesk\Lemlist;
use SignalDesk\LemlistCron;
use SignalDesk\LemlistStore;
use SignalDesk\LemlistLinker;
use SignalDesk\LemlistSync;
use SignalDesk\Atlas;
use SignalDesk\OutreachBusiness;

$opts = getopt('', ['max-seconds::', 'no-link', 'check-db']);
$maxSeconds = max(10, min(3000, (int) ($opts['max-seconds'] ?? 240)));
try {
    $config = Config::load($privateDir);
    date_default_timezone_set($config['TIMEZONE']);
    if (!is_dir($config['STORAGE_DIR'])) @mkdir($config['STORAGE_DIR'], 0700, true);
    Audit::$file = $config['STORAGE_DIR'] . '/audit.log';
    $config['PRIVATE_DIR'] = $privateDir;
    if (isset($opts['check-db'])) {
        foreach (LemlistCron::checkDb($config['STORAGE_DIR']) as $l) fwrite(STDOUT, $l . "
");
        exit(0);
    }
    $client = Lemlist::client($config);
    if (!$client) {
        fwrite(STDOUT, "lemlist is off (no LEMLIST_API_KEY): nothing to do.
");
        exit(0);
    }
    @set_time_limit($maxSeconds + 90);
    $g = LemlistCron::guarded($config['STORAGE_DIR'], function (LemlistStore $store) use ($client, $config, $privateDir, $maxSeconds, $opts) {
        $hooks = HookBuffer::import($store);
        $r = (new LemlistSync($client, $store))->run($maxSeconds);
        $line = sprintf('%s sync=%s phase=%s hooks=%d', gmdate('c'), $r['result'], $r['state']['phase'], $hooks);
        if ($r['result'] === 'done' && !isset($opts['no-link'])) {
            $l = LemlistLinker::run($store, Lemlist::tabReader(Config::backend($config)), Lemlist::atlasCounter($config, $privateDir));
            $line .= sprintf(' linked=%d (IT %d, HR %d)', $l['linked'], $l['it'], $l['hr']);
            $atlas = Atlas::source($config, $privateDir);
            if ($atlas) { // Atlas new jobs / deals / placements for "From reach to business", hourly, read-only
                try {
                    $b = OutreachBusiness::sync($atlas->client(), $store, time());
                    if (empty($b['skipped'])) $line .= sprintf(' atlas: jobs=%d deals=%d placements=%d', $b['job'], $b['deal'], $b['placement']);
                } catch (\PDOException $e) {
                    throw $e; // storage problems are handled (retry / status) by LemlistCron
                } catch (\Throwable $e) {
                    $line .= ' atlas: ' . $e->getMessage();
                }
            }
        }
        return $line . sprintf(' leads=%d openTasks=%d', $store->one('SELECT COUNT(*) FROM leads'), $store->one("SELECT COUNT(*) FROM tasks WHERE status IN ('due','upcoming','paused')"));
    });
    if (!$g['ok']) {
        fwrite(STDERR, 'lemlist-sync: storage: ' . $g['error'] . ($g['tries'] > 1 ? ' (after a retry)' : '') . ". Details: bin/lemlist-sync.php --check-db and /health.php
");
        exit(1);
    }
    fwrite(STDOUT, $g['out'] . ($g['tries'] > 1 ? ' (storage retry ok)' : '') . "
");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'lemlist-sync: ' . $e->getMessage() . "
");
    exit(1);
}
