<?php
// Front controller: routing, security headers, JSON API, HTML views.
declare(strict_types=1);

namespace SignalDesk;

final class App
{
    private const MAX_BODY = 20000;
    private ?Service $service = null;

    /** Version used when an asset's modification time can't be read (keeps the URL valid). */
    public const ASSET_FALLBACK_VERSION = '1';

    public function __construct(private array $config) {}

    /**
     * Cache-busting asset URL: /assets/<file>?v=<filemtime>. 20i's StackCDN caches /assets/* hard, so a
     * new upload gets a new URL and browsers fetch the new file. The file is looked up under
     * DOCUMENT_ROOT/assets, then under the public folder (SD_PUBLIC_DIR, set by index.php/health.php),
     * and if neither works the version falls back to ASSET_FALLBACK_VERSION.
     */
    public static function asset(string $file): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $file) || str_contains($file, '..')) {
            throw new \InvalidArgumentException("Invalid asset name: $file");
        }
        $roots = [];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) $roots[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\');
        if (defined('SD_PUBLIC_DIR')) $roots[] = rtrim((string) constant('SD_PUBLIC_DIR'), '/\\');
        $version = self::ASSET_FALLBACK_VERSION;
        foreach ($roots as $root) {
            $path = $root . '/assets/' . $file;
            if (is_file($path)) {
                clearstatcache(true, $path);
                $mtime = @filemtime($path);
                if ($mtime) {
                    $version = (string) $mtime;
                    break;
                }
            }
        }
        return '/assets/' . $file . '?v=' . $version;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public function expectedOrigin(): string
    {
        if ($this->config['APP_ORIGIN'] !== '') return rtrim($this->config['APP_ORIGIN'], '/');
        return (self::isHttps() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    public static function securityHeaders(bool $https): void
    {
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        if ($https) header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
        header_remove('X-Powered-By');
    }

    private bool $atlasBuilt = false;
    private ?AtlasListsSource $atlas = null;

    /** The Candidate Desk source (Atlas lists), or null when Atlas is off / not configured. */
    private function atlas(): ?AtlasListsSource
    {
        if (!$this->atlasBuilt) {
            $this->atlas = Atlas::source($this->config, (string) ($this->config['PRIVATE_DIR'] ?? dirname(__DIR__)));
            $this->atlasBuilt = true;
        }
        return $this->atlas;
    }

    private function requireAtlas(): AtlasListsSource
    {
        return $this->atlas() ?? throw new AppError(503, 'ATLAS_OFF', 'Atlas is not configured (ATLAS_API_KEY in config.php).');
    }

    private static function str(mixed $v, int $max = 300): string
    {
        if (!is_string($v)) return '';
        $v = trim($v);
        return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
    }

    private function service(): Service
    {
        return $this->service ??= new Service(
            Config::backend($this->config), $this->config['STORAGE_DIR'], $this->config['TIMEZONE'],
            $this->config['WORKBOOK_BACKEND'] === 'graph' ? 60 : 0,
        );
    }

    private function auth(): Auth
    {
        return new Auth(Auth::parseUsers($this->config['AUTH_USERS']), new Lockout($this->config['STORAGE_DIR'] . '/lockout.json'));
    }

    private static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public function run(): void
    {
        $https = self::isHttps();
        self::securityHeaders($https);
        if ($this->config['APP_ENV'] === 'production' && !$https) {
            header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            return;
        }
        Auth::startSession($this->config['STORAGE_DIR'], $https);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $auth = $this->auth();
        $user = $auth->user();

        if ($path === '/login') {
            if ($method === 'POST') {
                if (!Auth::originValid($this->expectedOrigin()) || !Auth::csrfValid($_POST['csrf'] ?? null)) {
                    $this->html(Views::login('Your session expired. Please try again.', $this->config), 403);
                    return;
                }
                $r = $auth->login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''), self::clientIp());
                if ($r['ok']) {
                    $this->redirect('/signals');
                    return;
                }
                $this->html(Views::login($r['error'], $this->config), 401);
                return;
            }
            if ($user) {
                $this->redirect('/signals');
                return;
            }
            $this->html(Views::login(null, $this->config));
            return;
        }

        if ($path === '/logout' && $method === 'POST') {
            if (Auth::originValid($this->expectedOrigin()) && Auth::csrfValid($_POST['csrf'] ?? null)) $auth->logout();
            $this->redirect('/login');
            return;
        }

        if (str_starts_with($path, '/api/')) {
            $this->api($method, substr($path, 5), $user);
            return;
        }

        if (!$user) {
            $this->redirect('/login');
            return;
        }
        match ($path) {
            '/', '/signals' => $path === '/' ? $this->redirect('/signals') : $this->html(Views::shell('signals', $user, $this->config)),
            '/verified' => $this->html(Views::shell('verified', $user, $this->config)),
            '/candidates' => $this->html(Views::shell('candidates', $user, $this->config)),
            '/lists' => $this->html(Views::shell('lists', $user, $this->config)),
            default => $this->html(Views::notFound(), 404),
        };
    }

    private function api(string $method, string $route, ?array $user): void
    {
        try {
            if (!$user) {
                $this->json(['error' => 'Please sign in again.', 'code' => 'UNAUTHENTICATED'], 401);
                return;
            }
            if ($method === 'GET') {
                $data = match ($route) {
                    'signals' => $this->service()->signalsView(
                        isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date']) ? (string) $_GET['date'] : null,
                        ($_GET['rejected'] ?? '') === '1',
                    ),
                    'verified' => $this->service()->verifiedView(),
                    'atlas/search' => $this->requireAtlas()->search(
                        self::str($_GET['title'] ?? ''), self::str($_GET['sub'] ?? ''), self::str($_GET['group'] ?? ''),
                        max(1, min(10000, (int) ($_GET['page'] ?? 1))),
                    ),
                    'atlas/map' => $this->requireAtlas()->mapView(),
                    'atlas/cv' => ['url' => $this->requireAtlas()->cvUrl(self::str($_GET['personId'] ?? '', 64))
                        ?? throw new NotFoundError('No CV for this person in Atlas.')],
                    default => throw new NotFoundError('Unknown API route'),
                };
                $this->json($data);
                return;
            }
            if ($method !== 'POST') throw new AppError(405, 'METHOD', 'Method not allowed');
            if (!Auth::originValid($this->expectedOrigin())) {
                $this->json(['error' => 'Cross-site request refused.', 'code' => 'BAD_ORIGIN'], 403);
                return;
            }
            if (!Auth::csrfValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
                $this->json(['error' => 'Security token missing or expired. Reload the page.', 'code' => 'BAD_CSRF'], 403);
                return;
            }
            if (!str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
                $this->json(['error' => 'Expected JSON', 'code' => 'BAD_CONTENT_TYPE'], 415);
                return;
            }
            $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
            if (strlen($raw) > self::MAX_BODY) throw new ValidationError('Request is too large');
            $body = json_decode($raw, true);
            if (!is_array($body)) throw new ValidationError('Invalid JSON');
            $s = $this->service();
            $job = $body['jobUrl'] ?? null;
            $row = $body['row'] ?? null; // Verified-page writes identify the card by row number + Job URL
            $data = match ($route) {
                'tick' => $s->tick($job, $user['email']),
                'untick' => $s->untick($job, $user['email']),
                'reject' => $s->reject($job, $user['email']),
                'unreject' => $s->unreject($job, $user['email']),
                'linkedin' => $s->saveLinkedIn($row, $job, $body['links'] ?? null, $user['email']),
                'add-decision-maker' => $s->addDecisionMaker($row, $job, $body['link'] ?? null, $user['email']),
                'atlas/counts' => $this->atlasCounts($body['items'] ?? null),
                'atlas/map' => $this->requireAtlas()->saveFamily(self::str($body['family'] ?? '', 200), $body['keywords'] ?? null, $body['roleSubGroups'] ?? null, $user['email']),
                'atlas/refresh' => $this->requireAtlas()->refresh($user['email']),
                'approve' => $s->approve($row, $job, $user),
                'undo-approve' => $s->undoApprove($row, $job, $user['email']),
                default => throw new NotFoundError('Unknown API route'),
            };
            $this->json($data);
        } catch (AppError $e) {
            $this->json(['error' => $e->getMessage(), 'code' => $e->errCode], $e->status);
        } catch (\Throwable $e) {
            Audit::event('unhandled_error', ['message' => $e->getMessage(), 'where' => basename($e->getFile()) . ':' . $e->getLine()]);
            $this->json(['error' => 'Something went wrong on the server. Please try again.', 'code' => 'INTERNAL'], 500);
        }
    }

    /** Badge counts for up to 20 signals per request (the page sends them in small batches). */
    private function atlasCounts(mixed $items): array
    {
        if (!is_array($items) || count($items) > 20) throw new ValidationError('items must be a list of at most 20');
        $atlas = $this->requireAtlas();
        $out = [];
        foreach (array_values($items) as $it) {
            $title = self::str($it['title'] ?? '');
            $sub = self::str($it['sub'] ?? '');
            $group = self::str($it['group'] ?? '');
            $m = $atlas->match($title, $sub, $group);
            if (!$m['families']) {
                $out[] = ['none' => true];
                continue;
            }
            try {
                $c = $atlas->counts($m['families']);
                $out[] = ['n' => $c['n'], 'p' => $c['p'], 'f' => $c['f'], 'families' => $m['families'], 'reason' => $m['reason']];
            } catch (AtlasRateLimited $e) {
                $out[] = ['error' => 'rate', 'retryAfter' => $e->retryAfter];
            } catch (AppError $e) {
                $out[] = ['error' => $e->errCode];
            }
        }
        return ['items' => $out];
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function html(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $body;
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
    }
}

final class Views
{
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** <link> tags for the stylesheets, with cache-busting ?v=<filemtime> (see App::asset). */
    public static function styles(): string
    {
        return '<link rel="stylesheet" href="' . self::e(App::asset('app.css')) . '"><link rel="stylesheet" href="' . self::e(App::asset('extra.css')) . '">';
    }

    private static function head(string $title): string
    {
        return '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#1a0d2e">'
            . '<title>' . self::e($title) . '</title>' . Views::styles() . '</head>';
    }

    private static function banner(array $config): string
    {
        $label = Config::label($config);
        if ($label === '') return '';
        $text = $label === 'MOCK DATA' ? 'MOCK DATA: not connected to SharePoint' : 'DEV COPY: writing to the test copy of the workbook';
        return '<div class="bg-warn px-4 py-1 text-center text-xs font-bold tracking-wide text-black">' . self::e($text) . '</div>';
    }

    public static function login(?string $error, array $config): string
    {
        $csrf = Auth::csrfToken();
        $err = $error ? '<p role="alert" class="rounded-lg border border-bad/50 bg-bad/10 px-3 py-2 text-sm text-bad">' . self::e($error) . '</p>' : '';
        $input = 'w-full rounded-xl border border-line bg-black/40 px-3 py-3 text-base text-white placeholder:text-white/40 focus:border-pink focus:outline-none';
        return self::head('Signal Desk · SwooScale') . '<body class="antialiased">' . self::banner($config)
            . '<main class="flex min-h-dvh items-center justify-center px-4"><div class="w-full max-w-sm rounded-2xl border border-line bg-card/90 p-6 shadow-2xl shadow-black/50">'
            . '<div class="mb-6 flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-pink" aria-hidden="true"></span><h1 class="text-xl font-semibold tracking-tight">Signal Desk</h1></div>'
            . '<p class="mb-6 text-sm text-white/70">SwooScale · sign in to review today\'s job signals.</p>'
            . '<form method="post" action="/login" class="space-y-4"><input type="hidden" name="csrf" value="' . self::e($csrf) . '">'
            . '<label class="block"><span class="mb-1 block text-sm text-white/80">Email</span><input class="' . $input . '" type="email" name="email" autocomplete="username" required></label>'
            . '<label class="block"><span class="mb-1 block text-sm text-white/80">Password</span><input class="' . $input . '" type="password" name="password" autocomplete="current-password" required></label>'
            . $err
            . '<button type="submit" class="w-full rounded-xl bg-pink px-4 py-3 font-semibold text-white shadow-lg shadow-pink/20 hover:brightness-110">Sign in</button>'
            . '</form></div></main></body></html>';
    }

    public static function shell(string $page, array $user, array $config): string
    {
        $csrf = Auth::csrfToken();
        $tab = fn (string $href, string $label, bool $active) => '<a href="' . $href . '"' . ($active ? ' aria-current="page"' : '')
            . ' class="border-b-2 px-3 py-2 text-sm font-medium ' . ($active ? 'border-pink text-white' : 'border-transparent text-white/60 hover:text-white') . '">' . $label . '</a>';
        return self::head('Signal Desk · SwooScale')
            . '<body class="antialiased" data-page="' . self::e($page) . '" data-csrf="' . self::e($csrf) . '" data-atlas="' . (Atlas::mode($config) === 'off' ? 'off' : 'on') . '"><div class="min-h-dvh">' . self::banner($config)
            . '<header class="sticky top-0 z-20 border-b border-line/60 bg-black/70 backdrop-blur"><div class="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">'
            . '<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-pink" aria-hidden="true"></span><span class="font-semibold tracking-tight">Signal Desk</span></div>'
            . '<div class="flex items-center gap-3 text-sm"><span class="hidden text-white/60 sm:inline">' . self::e($user['name']) . '</span>'
            . '<form method="post" action="/logout"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button type="submit" class="rounded-lg border border-line px-3 py-1.5 text-white/80 hover:border-pink hover:text-white">Log out</button></form></div></div>'
            . '<nav class="mx-auto flex max-w-3xl gap-1 px-3 sm:px-4" aria-label="Main">' . $tab('/signals', 'Signals', $page === 'signals') . $tab('/verified', 'Verified', $page === 'verified') . $tab('/candidates', 'Candidates', $page === 'candidates') . (Atlas::mode($config) === 'off' ? '' : $tab('/lists', 'Lists', $page === 'lists')) . '</nav></header>'
            . '<main id="app" class="mx-auto max-w-3xl px-3 pb-16 pt-4 sm:px-4"><p class="flex items-center gap-2 text-sm text-white/70"><span class="sd-spinner"></span> Reading the master sheet…</p></main>'
            . '</div><script src="' . self::e(App::asset('app.js')) . '" defer></script></body></html>';
    }

    public static function notFound(): string
    {
        return self::head('Not found · Signal Desk') . '<body class="antialiased"><main class="flex min-h-dvh items-center justify-center px-4"><p class="text-white/70">Page not found. <a class="text-pink-soft underline" href="/signals">Back to Signals</a></p></main></body></html>';
    }

    public static function setupError(string $message): string
    {
        return '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Signal Desk: setup needed</title>' . Views::styles() . '</head>'
            . '<body class="antialiased"><main class="flex min-h-dvh items-center justify-center px-4"><div class="w-full max-w-lg rounded-2xl border border-line bg-card p-6">'
            . '<h1 class="mb-3 text-lg font-semibold">Signal Desk: setup needed</h1><p class="text-sm text-white/80">' . self::e($message) . '</p>'
            . '<p class="mt-3 text-sm text-white/60">See php/DEPLOY-20I.md.</p></div></main></body></html>';
    }
}
