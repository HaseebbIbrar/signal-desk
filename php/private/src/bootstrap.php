<?php
// Loads the library and config. Called by public/index.php and public/health.php with the private dir.
declare(strict_types=1);

namespace SignalDesk;

foreach (['Util', 'Errors', 'Sheet', 'Guard', 'Store', 'Backend', 'Config', 'Fixture', 'Service', 'Auth', 'Atlas', 'AtlasIndex', 'JdRules', 'Llm', 'JdMatch', 'Prospeo', 'App', 'Health'] as $f) {
    require_once __DIR__ . "/$f.php";
}

/** @return array<string,string> config; on a config problem, renders a setup page (no secrets) and exits */
function boot(string $privateDir): array
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    try {
        $config = Config::load($privateDir);
    } catch (ConfigError $e) {
        App::securityHeaders(App::isHttps());
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo Views::setupError($e->getMessage());
        exit;
    }
    date_default_timezone_set($config['TIMEZONE']);
    if (!is_dir($config['STORAGE_DIR'])) @mkdir($config['STORAGE_DIR'], 0700, true);
    Audit::$file = $config['STORAGE_DIR'] . '/audit.log';
    $config['PRIVATE_DIR'] = $privateDir; // for the default Atlas list map
    return $config;
}
