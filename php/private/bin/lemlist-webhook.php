<?php
// One-time lemlist webhook setup (run by hand over SSH, or once from your own computer with the live config.php):
//
//   php bin/lemlist-webhook.php --status            list the team's webhooks (ids, URLs, disabled flag)
//   php bin/lemlist-webhook.php --register          register APP_ORIGIN/hooks/lemlist for ALL events, with LEMLIST_WEBHOOK_SECRET
//   php bin/lemlist-webhook.php --delete=<hookId>   delete one webhook (e.g. a disabled one before registering again)
//   php bin/lemlist-webhook.php --test              send a signed test delivery to APP_ORIGIN/hooks/lemlist through 20i (stores nothing)
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
use SignalDesk\LemlistWebhook;

$opts = getopt('', ['status', 'register', 'delete:', 'test']);
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
    } elseif (isset($opts['test'])) {
        // the same path lemlist uses: HTTPS through 20i to our route, signed with this config's secret
        if (($config['LEMLIST_WEBHOOK_SECRET'] ?? '') === '') throw new RuntimeException('Set LEMLIST_WEBHOOK_SECRET in config.php first');
        $ch = curl_init($target);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: signal-desk-webhook-test'],
            CURLOPT_POSTFIELDS => json_encode(['_id' => 'test_' . bin2hex(random_bytes(6)), 'type' => LemlistWebhook::TEST_TYPE, 'secret' => $config['LEMLIST_WEBHOOK_SECRET'],
                'createdAt' => gmdate('c'), 'message' => '<html><p>HTML like a real email event</p></html>'])]);
        $out = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);
        $ok = $code === 200 && str_contains($out, '"test":true');
        fwrite(STDOUT, "POST $target -> HTTP $code" . ($err !== '' ? " ($err)" : '') . ($type !== '' ? " [$type]" : '') . "\n");
        if ($ok) fwrite(STDOUT, "OK: a delivery signed with this config's secret is accepted. If lemlist's deliveries still fail, its webhook has another secret: --status, --delete=<id>, --register.\n");
        elseif ($code === 401) fwrite(STDOUT, "401: the app refused this config's own secret, so the server's config.php differs from the one used here.\n");
        elseif (str_contains($type, 'json')) fwrite(STDOUT, 'The app answered: ' . mb_substr($out, 0, 200) . "\n");
        else fwrite(STDOUT, "Not the app's answer (a 20i or firewall page?): " . mb_substr(trim(strip_tags($out)), 0, 200) . "\n");
        exit($ok ? 0 : 1);
    } else {
        $hooks = $client->get('/hooks');
        $mine = array_values(array_filter($hooks, fn ($h) => rtrim((string) ($h['targetUrl'] ?? ''), '/') === $target));
        fwrite(STDOUT, count($hooks) . " webhook(s) (lemlist allows 200, disabled ones included):\n");
        foreach ($hooks as $h) {
            fwrite(STDOUT, sprintf("  %s  %s%s%s%s\n", $h['_id'] ?? '?', $h['targetUrl'] ?? '?',
                !empty($h['disabled']) ? '  DISABLED (' . ($h['disabledReason'] ?? '') . ', ' . ($h['disabledAt'] ?? '') . ')' : '',
                isset($h['lastErrorStatus']) ? '  last error: HTTP ' . $h['lastErrorStatus'] : '',
                rtrim((string) ($h['targetUrl'] ?? ''), '/') === $target ? '  <- this Signal Desk' : ''));
        }
        if (count($mine) > 1) fwrite(STDOUT, 'WARNING: ' . count($mine) . " webhooks point here: every event arrives several times (duplicates are ignored); delete the extra ones.\n");
        if (!$mine) fwrite(STDOUT, "No webhook points to $target: run --register.\n");
        // what Signal Desk itself saw (counts and reasons only)
        $store = Lemlist::store($config);
        $last = (int) $store->live('last_event_at', 0);
        fwrite(STDOUT, 'Last accepted event: ' . ($last ? gmdate('Y-m-d H:i', $last) . ' UTC' : 'none yet') . "\n");
        $rej = $store->live('hook_rejected', []) ?: [];
        if (!empty($rej['count'])) {
            fwrite(STDOUT, 'Refused deliveries since ' . gmdate('Y-m-d H:i', (int) $rej['since']) . ' UTC: ' . $rej['count'] . ' ('
                . implode(', ', array_map(fn ($k, $v) => "$k: $v", array_keys($rej['reasons']), $rej['reasons'])) . ")\n");
            if (isset($rej['reasons']['secret mismatch']) || isset($rej['reasons']['no secret field'])) {
                fwrite(STDOUT, "-> lemlist sends another secret (or none) than LEMLIST_WEBHOOK_SECRET. A webhook's secret can't be changed: --delete=<id> it and --register again.\n");
            }
        } else {
            fwrite(STDOUT, "Refused deliveries: none recorded.\n");
        }
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'lemlist-webhook: ' . $e->getMessage() . "\n");
    exit(1);
}
