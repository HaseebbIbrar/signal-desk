<?php
// /health.php: login-protected setup check. Confirms config and storage are NOT reachable over HTTP.
declare(strict_types=1);

define('SD_PUBLIC_DIR', __DIR__); // App::asset() fallback when DOCUMENT_ROOT isn't this folder
[$privateDir, $layout] = require __DIR__ . '/locate.inc.php';
if ($privateDir === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Signal Desk: the private folder was not found. See php/DEPLOY-20I.md.";
    exit;
}
require $privateDir . '/src/bootstrap.php';
$config = SignalDesk\boot($privateDir);

$https = SignalDesk\App::isHttps();
SignalDesk\App::securityHeaders($https);
SignalDesk\Auth::startSession($config['STORAGE_DIR'], $https);
$auth = new SignalDesk\Auth(SignalDesk\Auth::parseUsers($config['AUTH_USERS']), new SignalDesk\Lockout($config['STORAGE_DIR'] . '/lockout.json'));
if (!$auth->user()) {
    header('Location: /login', true, 303);
    exit;
}
$app = new SignalDesk\App($config);
header('Content-Type: text/html; charset=utf-8');
echo SignalDesk\Health::render(SignalDesk\Health::checks($config, $privateDir, (string) $layout, $app->expectedOrigin()), (string) $layout);
