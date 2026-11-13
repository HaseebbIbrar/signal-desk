<?php
// lemlist sync for the 20i cron job (every 15 minutes, its own line next to atlas-index.php):
//
//   php /home/sites/<site>/signal-desk-private/bin/lemlist-sync.php
//
// Reads running campaigns (steps, leads, stats), activities since the last run, open tasks and call statuses into
// storage/lemlist.sqlite, then links leads to the approved decision makers (Decision Makers / HR Decision Makers tabs,
// read-only) and their Atlas pool counts. Resumable: stops after --max-seconds (default 240) and carries on next run.
// Prunes data older than 12 months once a day. Output: counts only (no names, emails or phones). Exit 0 = OK.
//   --max-seconds=N   time budget (default 240)
//   --no-link         skip the workbook/Atlas linking step
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$privateDir = dirname(__DIR__);
require $privateDir . '/src/bootstrap.php';

use SignalDesk\Audit;
use SignalDesk\Config;
use SignalDesk\Lemlist;
use SignalDesk\LemlistLinker;
use SignalDesk\LemlistSync;

$opts = getopt('', ['max-seconds::', 'no-link']);
$maxSeconds = max(10, min(3000, (int) ($opts['max-seconds'] ?? 240)));
try {
    $config = Config::load($privateDir);
    date_default_timezone_set($config['TIMEZONE']);
    if (!is_dir($config['STORAGE_DIR'])) @mkdir($config['STORAGE_DIR'], 0700, true);
    Audit::$file = $config['STORAGE_DIR'] . '/audit.log';
    $config['PRIVATE_DIR'] = $privateDir;
    $client = Lemlist::client($config);
    if (!$client) {
        fwrite(STDOUT, "lemlist is off (no LEMLIST_API_KEY): nothing to do.\n");
        exit(0);
    }
    @set_time_limit($maxSeconds + 90);
    $store = Lemlist::store($config);
    $r = (new LemlistSync($client, $store))->run($maxSeconds);
    $line = sprintf('%s sync=%s phase=%s', gmdate('c'), $r['result'], $r['state']['phase']);
    if ($r['result'] === 'done' && !isset($opts['no-link'])) {
        $l = LemlistLinker::run($store, Lemlist::tabReader(Config::backend($config)), Lemlist::atlasCounter($config, $privateDir));
        $line .= sprintf(' linked=%d (IT %d, HR %d)', $l['linked'], $l['it'], $l['hr']);
    }
    $line .= sprintf(' leads=%d openTasks=%d', $store->one('SELECT COUNT(*) FROM leads'), $store->one("SELECT COUNT(*) FROM tasks WHERE status IN ('due','upcoming','paused')"));
    fwrite(STDOUT, $line . "\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'lemlist-sync: ' . $e->getMessage() . "\n");
    exit(1);
}
