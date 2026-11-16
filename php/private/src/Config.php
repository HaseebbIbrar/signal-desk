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
        'ANTHROPIC_BACKEND', 'ANTHROPIC_API_KEY', 'ANTHROPIC_MODEL', 'ANTHROPIC_MONTHLY_LIMIT_EUR', 'ANTHROPIC_USD_TO_EUR',
        'PROSPEO_BACKEND', 'PROSPEO_API_KEY', 'PROSPEO_DAILY_CREDIT_CAP',
        'LEMLIST_BACKEND', 'LEMLIST_API_KEY', 'LEMLIST_WEBHOOK_SECRET', 'LEMLIST_USER_MAP', 'LEMLIST_SHARED_SENDER', 'LEMLIST_SENDER_ALLOWED', 'LEMLIST_ATTRIBUTION', 'LEMLIST_SHOW_STOPPED',
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
        foreach (['ANTHROPIC_BACKEND', 'ANTHROPIC_API_KEY', 'ANTHROPIC_MODEL', 'ANTHROPIC_MONTHLY_LIMIT_EUR', 'ANTHROPIC_USD_TO_EUR'] as $k) $c[$k] = trim((string) ($c[$k] ?? ''));
        $c['ANTHROPIC_BACKEND'] = strtolower($c['ANTHROPIC_BACKEND']);
        if (!in_array($c['ANTHROPIC_BACKEND'], ['', 'anthropic', 'mock', 'off'], true)) throw new ConfigError('ANTHROPIC_BACKEND must be off, anthropic or mock.');
        if ($c['ANTHROPIC_BACKEND'] === 'mock' && $c['APP_ENV'] === 'production') {
            throw new ConfigError('ANTHROPIC_BACKEND=mock is not allowed when APP_ENV=production.');
        }
        if ($c['ANTHROPIC_MODEL'] !== '' && !preg_match('/^claude-[a-z0-9.-]+$/', $c['ANTHROPIC_MODEL'])) throw new ConfigError('ANTHROPIC_MODEL must look like claude-opus-5-5.');
        foreach (['ANTHROPIC_MONTHLY_LIMIT_EUR', 'ANTHROPIC_USD_TO_EUR'] as $k) {
            if ($c[$k] !== '' && (!is_numeric($c[$k]) || (float) $c[$k] < 0)) throw new ConfigError("$k must be a number (e.g. 50).");
        }
        foreach (['PROSPEO_BACKEND', 'PROSPEO_API_KEY', 'PROSPEO_DAILY_CREDIT_CAP'] as $k) $c[$k] = trim((string) ($c[$k] ?? ''));
        $c['PROSPEO_BACKEND'] = strtolower($c['PROSPEO_BACKEND']);
        if (!in_array($c['PROSPEO_BACKEND'], ['', 'prospeo', 'mock', 'off'], true)) throw new ConfigError('PROSPEO_BACKEND must be prospeo, mock or off.');
        if ($c['PROSPEO_BACKEND'] === 'mock' && $c['APP_ENV'] === 'production') {
            throw new ConfigError('PROSPEO_BACKEND=mock is not allowed when APP_ENV=production.');
        }
        if ($c['PROSPEO_DAILY_CREDIT_CAP'] !== '' && (!ctype_digit($c['PROSPEO_DAILY_CREDIT_CAP']) || (int) $c['PROSPEO_DAILY_CREDIT_CAP'] > 100000)) {
            throw new ConfigError('PROSPEO_DAILY_CREDIT_CAP must be a whole number (e.g. 200).');
        }
        foreach (['LEMLIST_BACKEND', 'LEMLIST_API_KEY', 'LEMLIST_WEBHOOK_SECRET', 'LEMLIST_USER_MAP', 'LEMLIST_SHARED_SENDER', 'LEMLIST_SENDER_ALLOWED', 'LEMLIST_ATTRIBUTION'] as $k) $c[$k] = trim((string) ($c[$k] ?? ''));
        $c['LEMLIST_BACKEND'] = strtolower($c['LEMLIST_BACKEND']);
        if (!in_array($c['LEMLIST_BACKEND'], ['', 'lemlist', 'mock', 'off'], true)) throw new ConfigError('LEMLIST_BACKEND must be lemlist, mock or off.');
        if ($c['LEMLIST_BACKEND'] === 'mock' && $c['APP_ENV'] === 'production') throw new ConfigError('LEMLIST_BACKEND=mock is not allowed when APP_ENV=production.');
        if ($c['LEMLIST_WEBHOOK_SECRET'] !== '' && strlen($c['LEMLIST_WEBHOOK_SECRET']) < 24) throw new ConfigError('LEMLIST_WEBHOOK_SECRET must be at least 24 characters (use a long random string).');
        if ($c['LEMLIST_SHARED_SENDER'] !== '' && !preg_match('/^usr_[A-Za-z0-9]{6,40}$/', $c['LEMLIST_SHARED_SENDER'])) throw new ConfigError('LEMLIST_SHARED_SENDER must be a lemlist user id like usr_…');
        if ($c['LEMLIST_USER_MAP'] !== '') {
            $map = json_decode($c['LEMLIST_USER_MAP'], true);
            if (!is_array($map) || array_is_list($map)) throw new ConfigError('LEMLIST_USER_MAP must be a JSON object: {"login@email": {"userId": "usr_…", "email": "…", "mailboxId": "usm_…"}}.');
            foreach ($map as $login => $m) {
                if (!is_array($m) || !preg_match('/^usr_[A-Za-z0-9]{6,40}$/', (string) ($m['userId'] ?? ''))) throw new ConfigError("LEMLIST_USER_MAP: \"$login\" needs a userId like usr_….");
            }
        }
        foreach (array_filter(array_map('trim', explode(',', $c['LEMLIST_SENDER_ALLOWED']))) as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new ConfigError("LEMLIST_SENDER_ALLOWED: \"$e\" is not an email address.");
        }
        if (!in_array($c['LEMLIST_ATTRIBUTION'], ['', 'first_positive', 'all_touches'], true)) throw new ConfigError('LEMLIST_ATTRIBUTION must be first_positive or all_touches.');
        if ($c['LEMLIST_ATTRIBUTION'] === '') $c['LEMLIST_ATTRIBUTION'] = 'first_positive';
        $c['LEMLIST_SHOW_STOPPED'] = in_array(strtolower(trim((string) ($c['LEMLIST_SHOW_STOPPED'] ?? ''))), ['1', 'true', 'yes'], true) ? 'true' : 'false';
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
