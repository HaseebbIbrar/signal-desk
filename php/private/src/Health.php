<?php
// /health.php (login required): confirms config and storage are set up and NOT reachable over HTTP.
// Never shows secret values: only "set" / "missing".
declare(strict_types=1);

namespace SignalDesk;

final class Health
{
    /** @return array<int,array{0:string,1:string,2:string}> [check, status OK|WARN|FAIL, detail] */
    public static function checks(array $config, string $privateDir, string $layout, string $origin): array
    {
        $out = [];
        $add = function (string $check, string $status, string $detail) use (&$out) { $out[] = [$check, $status, $detail]; };

        $add('PHP version', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'OK' : 'FAIL', PHP_VERSION . ' (needs 8.1+)');
        foreach (['curl', 'json', 'openssl', 'mbstring'] as $ext) {
            $need = $ext === 'mbstring' ? 'WARN' : 'FAIL';
            $add("PHP extension $ext", extension_loaded($ext) ? 'OK' : $need, extension_loaded($ext) ? 'loaded' : 'missing');
        }
        $add('APP_ENV / backend', 'OK', $config['APP_ENV'] . ' / ' . $config['WORKBOOK_BACKEND'] . (Config::label($config) ? ' (' . Config::label($config) . ')' : ''));
        $add('APP_ORIGIN', $config['APP_ORIGIN'] !== '' ? 'OK' : ($config['APP_ENV'] === 'production' ? 'FAIL' : 'WARN'), $config['APP_ORIGIN'] !== '' ? $config['APP_ORIGIN'] : 'not set');
        $add('HTTPS', App::isHttps() ? 'OK' : ($config['APP_ENV'] === 'production' ? 'FAIL' : 'WARN'), App::isHttps() ? 'this request is HTTPS' : 'this request is plain HTTP');
        foreach (['AZURE_TENANT_ID', 'AZURE_CLIENT_ID', 'AZURE_CLIENT_SECRET', 'GRAPH_DRIVE_ID', 'GRAPH_MASTER_ITEM_ID', 'GRAPH_DEV_ITEM_ID'] as $k) {
            $needed = $config['WORKBOOK_BACKEND'] === 'graph' && ($k !== 'GRAPH_DEV_ITEM_ID' || $config['APP_ENV'] === 'development');
            $add($k, $config[$k] !== '' ? 'OK' : ($needed ? 'FAIL' : 'OK'), $config[$k] !== '' ? 'set' : ($needed ? 'missing' : 'not needed'));
        }
        $add('AUTH_USERS', 'OK', count(Auth::parseUsers($config['AUTH_USERS'])) . ' user(s)');

        $storage = $config['STORAGE_DIR'];
        $probe = $storage . '/.write-test-' . bin2hex(random_bytes(4));
        $writable = @file_put_contents($probe, 'x') !== false;
        @unlink($probe);
        $add('storage/ writable', $writable ? 'OK' : 'FAIL', $writable ? 'yes' : 'cannot write to the storage folder');

        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
        $priv = realpath($privateDir) ?: $privateDir;
        $inside = $docRoot !== '' && str_starts_with(str_replace('\\', '/', $priv) . '/', rtrim(str_replace('\\', '/', $docRoot), '/') . '/');
        $add('Private folder location', 'OK', $inside
            ? 'layout B: inside the web root (protected by .htaccess "Require all denied")'
            : 'layout A: outside the web root (not reachable over HTTP by design)');

        // A folder outside THIS site's web root can still be inside ANOTHER site's (e.g. public_html/... of the main domain).
        $segments = explode('/', strtolower(str_replace('\\', '/', $priv)));
        $webish = array_intersect($segments, ['public_html', 'www', 'htdocs', 'html', 'httpdocs']);
        if (!$inside && $webish) {
            $add('Private folder placement', 'WARN', 'it is inside a "' . implode('/', $webish) . '" folder, which another domain may serve. Its own .htaccess denies access, but move it next to public_html, not inside it.');
        }
        if (!$inside) {
            $add('config.php over HTTP', 'OK', 'outside this site\'s web root');
            $add('storage/ over HTTP', 'OK', 'outside this site\'s web root');
            return $out;
        }
        if (!function_exists('curl_init')) {
            $add('HTTP reachability self-test', 'WARN', 'curl missing: open the URLs below in a browser yourself and confirm 403/404');
            return $out;
        }
        // Layout B: prove the private files are refused over HTTP.
        $rel = trim(substr(str_replace('\\', '/', $priv), strlen(rtrim(str_replace('\\', '/', $docRoot), '/'))), '/');
        $canary = 'canary-' . bin2hex(random_bytes(8));
        @file_put_contents($storage . '/health-canary.txt', $canary);
        $targets = [
            'config.php' => "/$rel/config.php",
            'config.example.php' => "/$rel/config.example.php",
            'storage/health-canary.txt' => "/$rel/storage/health-canary.txt",
            'storage/audit.log' => "/$rel/storage/audit.log",
            'storage/rejections.json' => "/$rel/storage/rejections.json",
            'src/Service.php' => "/$rel/src/Service.php",
        ];
        foreach ($targets as $label => $path) {
            [$status, $body] = (self::$fetcher ?? [self::class, 'fetch'])($origin . $path);
            [$result, $why] = self::classify($status, $body, $canary);
            $add("$label over HTTP", $result, "GET $path -> $why");
        }
        @unlink($storage . '/health-canary.txt');
        return $out;
    }

    /** Test hook: fn(string $url): array{0:int,1:string} [HTTP status (0 = no response), body]. */
    public static $fetcher = null;

    /**
     * Only a 403 or 404 proves a private file is blocked. A 200 (or any leaked content) is a FAIL.
     * No response, a timeout, or any other status can't prove anything: WARN, check manually.
     * @return array{0:string,1:string} [OK|WARN|FAIL, detail]
     */
    public static function classify(int $status, string $body, string $canary): array
    {
        if (str_contains($body, $canary) || str_contains($body, '<?php') || str_contains($body, 'AZURE_')) {
            return ['FAIL', ($status ?: 'no status') . ' and the file\'s contents came back (REACHABLE: check .htaccess)'];
        }
        if ($status === 200) return ['FAIL', '200 (REACHABLE: check .htaccess)'];
        if ($status === 403 || $status === 404) return ['OK', "$status (blocked)"];
        if ($status === 0) return ['WARN', 'no response or timeout: could not verify, check manually in a browser'];
        return ['WARN', "$status: could not verify, check manually in a browser (expected 403 or 404)"];
    }

    /** @return array{0:int,1:string} */
    private static function fetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RANGE => '0-2047']);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, is_string($body) ? $body : ''];
    }

    public static function render(array $checks, string $layout): string
    {
        $fail = count(array_filter($checks, fn ($c) => $c[1] === 'FAIL'));
        $rows = '';
        foreach ($checks as [$check, $status, $detail]) {
            $cls = $status === 'OK' ? 'text-ok' : ($status === 'WARN' ? 'text-warn' : 'text-bad');
            $rows .= '<tr class="border-t border-line/60"><td class="py-1.5 pr-3">' . Views::e($check) . '</td><td class="py-1.5 pr-3 font-semibold ' . $cls . '">' . $status . '</td><td class="py-1.5 text-white/80">' . Views::e($detail) . '</td></tr>';
        }
        $warn = count(array_filter($checks, fn ($c) => $c[1] === 'WARN'));
        $summary = $fail ? "<p class=\"mb-3 text-bad\">$fail check(s) failed.</p>" : '<p class="mb-3 text-ok">All required checks passed.</p>';
        if ($warn) $summary .= "<p class=\"mb-3 text-warn\">$warn check(s) could not be verified (WARN): see the details and check them manually.</p>";
        return '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Health · Signal Desk</title><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/extra.css"></head>'
            . '<body class="antialiased"><main class="mx-auto max-w-3xl px-4 py-6"><h1 class="mb-2 text-lg font-semibold">Signal Desk health</h1>' . $summary
            . '<table class="w-full text-left text-sm">' . $rows . '</table><p class="mt-4 text-sm"><a class="text-pink-soft underline" href="/signals">Back to Signals</a></p></main></body></html>';
    }
}
