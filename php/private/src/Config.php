<?php
// Loads config.php (kept outside the web root, or in a folder denied by .htaccess). Environment
// variables with the same names override it. Secrets never reach the browser.
declare(strict_types=1);

namespace SignalDesk;

final class ConfigError extends \RuntimeException {}

final class Config
{
    public const KEYS = [
        'APP_ENV', 'WORKBOOK_BACKEND', 'APP_ORIGIN', 'TIMEZONE',
        'AZURE_TENANT_ID', 'AZURE_CLIENT_ID', 'AZURE_CLIENT_SECRET',
        'GRAPH_DRIVE_ID', 'GRAPH_MASTER_ITEM_ID', 'GRAPH_DEV_ITEM_ID',
        'AUTH_USERS', 'STORAGE_DIR',
        'ATLAS_BACKEND', 'ATLAS_API_KEY', 'ATLAS_BASE_URL', 'ATLAS_PROFILE_URL',
    ];

    /** @return array<string,string> validated settings */
    public static function load(string $privateDir): array
    {
        $file = $privateDir . '/config.php';
        if (!is_file($file)) throw new ConfigError('config.php is missing. Copy config.example.php to config.php and fill it in.');
        $raw = require $file;
        if (!is_array($raw)) throw new ConfigError('config.php must return an array.');
        $c = [];
        foreach (self::KEYS as $k) {
            $env = getenv($k);
            $c[$k] = trim((string) ($env !== false && $env !== '' ? $env : ($raw[$k] ?? '')));
        }
        return self::validate($c, $privateDir);
    }

    /** @param array<string,string> $c */
    public static function validate(array $c, string $privateDir): array
    {
        $c['APP_ENV'] = $c['APP_ENV'] === 'production' ? 'production' : 'development';
        $c['WORKBOOK_BACKEND'] = $c['WORKBOOK_BACKEND'] === 'mock' ? 'mock' : 'graph';
        $c['TIMEZONE'] = $c['TIMEZONE'] ?: 'Europe/Brussels';
        $c['STORAGE_DIR'] = $c['STORAGE_DIR'] ?: $privateDir . '/storage';
        if ($c['WORKBOOK_BACKEND'] === 'mock' && $c['APP_ENV'] === 'production') {
            throw new ConfigError('WORKBOOK_BACKEND=mock is not allowed when APP_ENV=production.');
        }
        foreach (['ATLAS_BACKEND', 'ATLAS_API_KEY', 'ATLAS_BASE_URL', 'ATLAS_PROFILE_URL'] as $k) $c[$k] = trim((string) ($c[$k] ?? ''));
        $c['ATLAS_BACKEND'] = strtolower($c['ATLAS_BACKEND']);
        if (!in_array($c['ATLAS_BACKEND'], ['', 'atlas', 'mock', 'off'], true)) throw new ConfigError('ATLAS_BACKEND must be atlas, mock or off.');
        if ($c['ATLAS_BACKEND'] === 'mock' && $c['APP_ENV'] === 'production') {
            throw new ConfigError('ATLAS_BACKEND=mock is not allowed when APP_ENV=production.');
        }
        if ($c['ATLAS_BASE_URL'] !== '' && !preg_match('~^https://[a-z0-9.-]+(?::\d+)?/?$~i', $c['ATLAS_BASE_URL'])) {
            throw new ConfigError('ATLAS_BASE_URL must look like https://api.recruitwithatlas.com');
        }
        if ($c['APP_ENV'] === 'production' && $c['APP_ORIGIN'] === '') {
            throw new ConfigError('APP_ORIGIN must be set in production (e.g. https://signals.swooscale.com).');
        }
        if ($c['AUTH_USERS'] === '') throw new ConfigError('AUTH_USERS is empty: add at least one user (see bin/hash-password.php).');
        Auth::parseUsers($c['AUTH_USERS']); // throws on a malformed entry
        if ($c['WORKBOOK_BACKEND'] === 'graph') {
            foreach (['AZURE_TENANT_ID', 'AZURE_CLIENT_ID', 'AZURE_CLIENT_SECRET', 'GRAPH_DRIVE_ID', 'GRAPH_MASTER_ITEM_ID'] as $k) {
                if ($c[$k] === '') throw new ConfigError("$k is missing.");
            }
            if ($c['APP_ENV'] === 'development') {
                if ($c['GRAPH_DEV_ITEM_ID'] === '') throw new ConfigError('GRAPH_DEV_ITEM_ID is missing (development writes to a COPY of the workbook).');
                if ($c['GRAPH_DEV_ITEM_ID'] === $c['GRAPH_MASTER_ITEM_ID']) {
                    throw new ConfigError('GRAPH_DEV_ITEM_ID equals GRAPH_MASTER_ITEM_ID. Development must run against a COPY of the workbook.');
                }
            }
        }
        return $c;
    }

    public static function label(array $c): string
    {
        if ($c['WORKBOOK_BACKEND'] === 'mock') return 'MOCK DATA';
        return $c['APP_ENV'] === 'development' ? 'DEV COPY' : '';
    }

    public static function backend(array $c): WorkbookBackend
    {
        $storage = $c['STORAGE_DIR'];
        if ($c['WORKBOOK_BACKEND'] === 'mock') {
            return MemoryBackend::persisted($storage . '/mock-workbook.json', fn () => Fixture::build());
        }
        $dev = $c['APP_ENV'] === 'development';
        return new GraphBackend(
            $c['AZURE_TENANT_ID'], $c['AZURE_CLIENT_ID'], $c['AZURE_CLIENT_SECRET'], $c['GRAPH_DRIVE_ID'],
            $dev ? $c['GRAPH_DEV_ITEM_ID'] : $c['GRAPH_MASTER_ITEM_ID'],
            self::label($c), $storage . '/graph-token.json', $dev ? Sheet::MASTER_FILE_NAME : null,
        );
    }
}
