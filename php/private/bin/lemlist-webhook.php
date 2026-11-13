<?php
// One-time lemlist webhook setup (run by hand over SSH, or once from your own computer with the live config.php):
//
//   php bin/lemlist-webhook.php --status            list the team's webhooks (ids, URLs, disabled flag)
//   php bin/lemlist-webhook.php --register          register APP_ORIGIN/hooks/lemlist for ALL events, with LEMLIST_WEBHOOK_SECRET
//   php bin/lemlist-webhook.php --delete=<hookId>   delete one webhook (e.g. a disabled one before registering again)
//
// Needs the API key's user to have lemlist's "Edit integrations" permission. Never prints the API key or the secret.
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

$opts = getopt('', ['status', 'register', 'delete:']);
try {
    $config = Config::load($privateDir);
    if (!is_dir($config['STORAGE_DIR'])) @mkdir($config['STORAGE_DIR'], 0700, true);
    Audit::$file = $config['STORAGE_DIR'] . '/audit.log';
    $client = Lemlist::client($config) ?? throw new RuntimeException('lemlist is off: set LEMLIST_API_KEY in config.php first');
    $client->setup = true; // the only place POST/DELETE /hooks are allowed
    $target = rtrim($config['APP_ORIGIN'], '/') . '/hooks/lemlist';
    if (isset($opts['register'])) {
        if ($config['APP_ORIGIN'] === '' || !str_starts_with($target, 'https://')) throw new RuntimeException('APP_ORIGIN must be the live https:// address (e.g. https://signals.swooscale.com)');
        if (($config['LEMLIST_WEBHOOK_SECRET'] ?? '') === '') throw new RuntimeException('Set LEMLIST_WEBHOOK_SECRET in config.php first (a long random string)');
        $h = $client->write('POST', '/hooks', ['targetUrl' => $target, 'secret' => $config['LEMLIST_WEBHOOK_SECRET']]);
        Audit::event('lemlist_hook_register', ['targetUrl' => $target, 'id' => $h['_id'] ?? null]);
        fwrite(STDOUT, 'Registered webhook ' . ($h['_id'] ?? '?') . " for $target (all events).\n");
    } elseif (isset($opts['delete'])) {
        $id = (string) $opts['delete'];
        $client->write('DELETE', '/hooks/' . $id, []);
        Audit::event('lemlist_hook_delete', ['id' => $id]);
        fwrite(STDOUT, "Deleted webhook $id.\n");
    } else {
        $hooks = $client->get('/hooks');
        fwrite(STDOUT, count($hooks) . " webhook(s) (lemlist allows 200, disabled ones included):\n");
        foreach ($hooks as $h) {
            fwrite(STDOUT, sprintf("  %s  %s%s%s\n", $h['_id'] ?? '?', $h['targetUrl'] ?? '?', !empty($h['disabled']) ? '  DISABLED (' . ($h['disabledReason'] ?? '') . ')' : '',
                rtrim((string) ($h['targetUrl'] ?? ''), '/') === $target ? '  <- this Signal Desk' : ''));
        }
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'lemlist-webhook: ' . $e->getMessage() . "\n");
    exit(1);
}
