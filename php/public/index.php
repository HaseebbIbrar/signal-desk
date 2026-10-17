<?php
// Signal Desk (PHP). The only page entry point besides health.php; .htaccess sends every route here.
declare(strict_types=1);

// Local testing with `php -S localhost:8080 -t public public/index.php`: let the built-in server
// serve the static assets, and route /health.php to its file.
if (PHP_SAPI === 'cli-server') {
    $p = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (preg_match('#^/assets/[A-Za-z0-9._-]+$#', $p) && is_file(__DIR__ . $p)) return false;
    if ($p === '/health.php') {
        require __DIR__ . '/health.php';
        return true;
    }
}

define('SD_PUBLIC_DIR', __DIR__); // App::asset() fallback when DOCUMENT_ROOT isn't this folder
[$privateDir] = require __DIR__ . '/locate.inc.php';
if ($privateDir === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Signal Desk: the private folder (config.php, src/, storage/) was not found. See php/DEPLOY-20I.md.";
    exit;
}
require $privateDir . '/src/bootstrap.php';
$config = SignalDesk\boot($privateDir);
(new SignalDesk\App($config))->run();
