<?php
// Nightly Atlas title index (storage/atlas-title-index.json). For the 20i cron job ("Scheduled Tasks"):
//
//   php /home/sites/<your site>/<private folder>/bin/atlas-index.php
//
// Run it every 15 minutes. Each run lasts at most --max-seconds (default 240) and then stops; the next run
// carries on where it left off. When the index is less than 20 hours old a run does nothing and exits at once,
// so in practice it rebuilds once a day. Options:
//   --max-seconds=N   stop after N seconds (default 240)
//   --force           start a new build now even if the index is fresh (still at most once an hour)
//   --status          print the status and exit
// Output: counts and status only (no names, no personal data). Exit code 0 = OK, 1 = error.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$privateDir = dirname(__DIR__);
require $privateDir . '/src/bootstrap.php';

use SignalDesk\Atlas;
use SignalDesk\Audit;
use SignalDesk\Config;

$opts = getopt('', ['max-seconds::', 'force', 'status']);
$maxSeconds = max(10, min(3000, (int) ($opts['max-seconds'] ?? 240)));
try {
    $config = Config::load($privateDir);
    date_default_timezone_set($config['TIMEZONE']);
    if (!is_dir($config['STORAGE_DIR'])) @mkdir($config['STORAGE_DIR'], 0700, true);
    Audit::$file = $config['STORAGE_DIR'] . '/audit.log';
    $config['PRIVATE_DIR'] = $privateDir;
    $source = Atlas::source($config, $privateDir);
    if (!$source) {
        fwrite(STDOUT, "Atlas is off (no ATLAS_API_KEY): nothing to do.\n");
        exit(0);
    }
    $builder = $source->indexBuilder();
    if (isset($opts['status'])) {
        fwrite(STDOUT, json_encode($builder->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(0);
    }
    @set_time_limit($maxSeconds + 60);
    $r = $builder->run($maxSeconds, isset($opts['force']), 'cron');
    $line = sprintf('%s result=%s state=%s people=%d/%d index=%s entries=%d', gmdate('c'), $r['result'], $r['state'], $r['done'], $r['total'], $r['builtAt'] ?? 'none', $r['entries']);
    fwrite(STDOUT, $line . "\n");
    exit($r['result'] === 'error' ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'atlas-index: ' . $e->getMessage() . "\n");
    exit(1);
}
