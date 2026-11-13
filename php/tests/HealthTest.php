<?php
// /health.php: only 403/404 prove a private file is blocked over HTTP.
declare(strict_types=1);

use SignalDesk\Config;
use SignalDesk\Health;

test('Health: only 403/404 count as blocked; 200 is FAIL; no response or other statuses are WARN', function () {
    eq(['OK', '403 (blocked)'], Health::classify(403, 'Forbidden', 'canary-x'));
    eq(['OK', '404 (blocked)'], Health::classify(404, '', 'canary-x'));
    eq('FAIL', Health::classify(200, '', 'canary-x')[0]);
    eq(['WARN', 'no response or timeout: could not verify, check manually in a browser'], Health::classify(0, '', 'canary-x'));
    foreach ([301, 302, 401, 500, 503] as $s) {
        $r = Health::classify($s, '', 'canary-x');
        eq('WARN', $r[0], "status $s");
        ok(str_contains($r[1], 'check manually in a browser'));
    }
    eq('FAIL', Health::classify(403, 'leaked canary-x', 'canary-x')[0], 'leaked contents always FAIL');
    eq('FAIL', Health::classify(0, "<?php return ['AZURE_CLIENT_SECRET' => 'x'];", 'canary-x')[0]);
});

test('Health: layout B self-test maps each HTTP result per file', function () {
    $docRoot = tmpdir();
    $private = "$docRoot/_private";
    mkdir("$private/storage", 0700, true);
    $config = Config::validate([
        'APP_ENV' => 'development', 'WORKBOOK_BACKEND' => 'mock', 'APP_ORIGIN' => 'https://signals.example', 'TIMEZONE' => '',
        'AZURE_TENANT_ID' => '', 'AZURE_CLIENT_ID' => '', 'AZURE_CLIENT_SECRET' => '', 'GRAPH_DRIVE_ID' => '', 'GRAPH_MASTER_ITEM_ID' => '',
        'GRAPH_DEV_ITEM_ID' => '', 'AUTH_USERS' => 'a@x.test|A|' . base64_encode(password_hash('long enough pass', PASSWORD_BCRYPT, ['cost' => 4])),
        'STORAGE_DIR' => "$private/storage",
    ], $private);
    $saved = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $_SERVER['DOCUMENT_ROOT'] = $docRoot;
    $responses = [
        '/_private/config.php' => [403, 'Forbidden'],
        '/_private/config.example.php' => [404, ''],
        '/_private/storage/health-canary.txt' => [0, ''],           // timeout
        '/_private/storage/audit.log' => [200, ''],                 // reachable
        '/_private/storage/rejections.json' => [500, 'error'],
        '/_private/src/Service.php' => [301, ''],
    ];
    $seen = [];
    Health::$fetcher = function (string $url) use ($responses, &$seen) {
        $path = substr($url, strlen('https://signals.example'));
        $seen[] = $path;
        return $responses[$path] ?? [0, ''];
    };
    try {
        $checks = Health::checks($config, $private, 'B', 'https://signals.example');
    } finally {
        Health::$fetcher = null;
        $_SERVER['DOCUMENT_ROOT'] = $saved;
    }
    eq(array_keys($responses), $seen, 'every private file is requested');
    $byName = [];
    foreach ($checks as [$name, $status, $detail]) $byName[$name] = [$status, $detail];
    ok(str_contains($byName['Private folder location'][1], 'layout B'));
    eq('OK', $byName['config.php over HTTP'][0]);
    eq('OK', $byName['config.example.php over HTTP'][0]);
    eq('WARN', $byName['storage/health-canary.txt over HTTP'][0]);
    ok(str_contains($byName['storage/health-canary.txt over HTTP'][1], 'no response or timeout'));
    eq('FAIL', $byName['storage/audit.log over HTTP'][0]);
    eq('WARN', $byName['storage/rejections.json over HTTP'][0]);
    eq('WARN', $byName['src/Service.php over HTTP'][0]);
    ok(!is_file("$private/storage/health-canary.txt"), 'canary file removed afterwards');

    $html = Health::render($checks, 'B');
    ok(str_contains($html, '1 check(s) failed.'), 'summary counts the FAIL');
    $warns = count(array_filter($checks, fn ($c) => $c[1] === 'WARN')); // 3 above + "HTTPS" (CLI is plain HTTP) + "Atlas off" + "Prospeo off" + "lemlist off"
    eq(7, $warns);
    ok(str_contains($html, "$warns check(s) could not be verified (WARN)"), 'summary counts the WARNs');
});
