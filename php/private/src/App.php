<?php
// Front controller: routing, security headers, JSON API, HTML views.
declare(strict_types=1);

namespace SignalDesk;

final class App
{
    private const MAX_BODY = 20000;
    private const MAX_BODY_JD = 80000; // job descriptions
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

    private ?JdMatcher $jd = null;

    /** JD matching (Atlas title index; rule-based unless ANTHROPIC_BACKEND switches AI on). */
    private function jd(): JdMatcher
    {
        if ($this->jd) return $this->jd;
        $llm = Llms::build($this->config);
        $cv = new CvFetcher();
        if (Atlas::mode($this->config) === 'mock') $cv->transport = fn (string $url) => ['status' => 200, 'type' => 'text/plain', 'body' => MockAtlas::cvText($url)];
        return $this->jd = new JdMatcher($this->requireAtlas(), $llm, $this->config['STORAGE_DIR'], $cv);
    }

    /** Build (part of) the title index from the /lists page: start (at most hourly) or carry on, ~20 s per request. */
    private function atlasIndex(string $action, string $user): array
    {
        $b = $this->requireAtlas()->indexBuilder() ?? throw new AppError(500, 'NO_STORAGE', 'Storage is not available');
        if (!in_array($action, ['start', 'continue'], true)) throw new ValidationError('action must be start or continue');
        @set_time_limit(60);
        $st = $b->status();
        if ($action === 'continue' && $st['state'] !== 'running') return ['result' => 'idle'] + $st;
        $r = $b->run(20, $action === 'start' && $st['state'] !== 'running', 'button');
        Audit::event('atlas_index_button', ['user' => $user, 'action' => $action, 'result' => $r['result']]);
        return $r;
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
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        if ($path === '/hooks/lemlist') { // lemlist webhook: no session, no login; the shared secret is the only key
            $this->lemlistHook($method);
            return;
        }
        Auth::startSession($this->config['STORAGE_DIR'], $https);
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
            '/hr' => $this->html(Views::shell('hr', $user, $this->config)),
            '/outreach' => $this->html(Views::shell('outreach', $user, $this->config)),
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
                    'hr' => $this->service()->verifiedView('hr'),
                    'outreach' => $this->outreachView($user['email']),
                    'outreach/leads' => $this->outreach()->leads($this->outreachIds(), [
                        'status' => self::str($_GET['status'] ?? '', 40), 'campaign' => self::str($_GET['campaign'] ?? '', 64), 'track' => self::str($_GET['track'] ?? '', 4),
                        'group' => self::str($_GET['group'] ?? '', 120), 'channel' => self::str($_GET['channel'] ?? '', 40), 'needs' => ($_GET['needs'] ?? '') === '1', 'q' => self::str($_GET['q'] ?? '', 100),
                    ], max(1, min(1000, (int) ($_GET['page'] ?? 1)))),
                    'outreach/lead' => $this->outreach()->lead(self::str($_GET['id'] ?? '', 64)),
                    'outreach/compare' => $this->outreach()->compare($this->outreachIds(), self::str($_GET['p'] ?? '7', 8)),
                    'atlas/search' => $this->requireAtlas()->search(
                        self::str($_GET['title'] ?? ''), self::str($_GET['sub'] ?? ''), self::str($_GET['group'] ?? ''),
                        max(1, min(10000, (int) ($_GET['page'] ?? 1))), ($_GET['all'] ?? '') === '1',
                    ),
                    'atlas/index' => ($this->requireAtlas()->indexBuilder() ?? throw new NotFoundError('No index'))->status(),
                    'jd/status' => ['engine' => $this->jd()->engine(), 'remainingToday' => $this->jd()->dailyLimit()->remaining($user['email']), 'dailyLimit' => JdMatcher::DAILY_MATCHES,
                        'spentEur' => round(Llms::spendGuard($this->config)->spent(), 2), 'limitEur' => Llms::spendGuard($this->config)->limit(),
                        'indexReady' => $this->requireAtlas()->index() !== null, 'prospeo' => Prospeo::mode($this->config) !== 'off'],
                    'prospeo/status' => $this->prospeo()->status(),
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
            $max = str_starts_with($route, 'jd/') ? self::MAX_BODY_JD : self::MAX_BODY;
            $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
            if (strlen($raw) > $max) throw new ValidationError('Request is too large');
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
                'atlas/index' => $this->atlasIndex(self::str($body['action'] ?? '', 20), $user['email']),
                'jd/fetch' => $this->jdFetch($body['url'] ?? null),
                'outreach/outcome' => $this->outreach()->setOutcome($body['leadId'] ?? null, $body['outcome'] ?? null, $user['email']),
                'outreach/selection' => $this->outreachSelection($body, $user['email']),
                'jd/parse' => $this->jd()->parse($body['text'] ?? null, $user['email']),
                'jd/match' => $this->jd()->start($body['requirements'] ?? null, self::str($body['title'] ?? ''), self::str($body['sub'] ?? ''), self::str($body['group'] ?? ''), $user['email'], $body['jdText'] ?? ''),
                'jd/page' => $this->jd()->page(self::str($body['matchId'] ?? '', 40), max(1, min(10, (int) ($body['page'] ?? 1)))),
                'prospeo/filters' => $this->prospeo()->filtersFor(JdMatcher::requirements($body['requirements'] ?? null)),
                'prospeo/search' => $this->prospeo()->search($body['filters'] ?? null, max(1, min(1000, (int) ($body['page'] ?? 1))), $body['requirements'] ?? null, $user['email']),
                'prospeo/reveal' => $this->prospeo()->reveal(self::str($body['personId'] ?? '', 80), self::str($body['what'] ?? 'email', 10), ($body['confirm'] ?? false) === true, $user['email']),
                'jd/batch' => (function () use ($body, $user) {
                    @set_time_limit(200);
                    return $this->jd()->batch(self::str($body['matchId'] ?? '', 40), max(0, min(20, (int) ($body['batch'] ?? 0))), $user['email']);
                })(),
                'approve' => $s->approve($row, $job, $user),
                'undo-approve' => $s->undoApprove($row, $job, $user['email']),
                // HR track: same actions on "Hr signals" -> "HR Decision Makers"
                'hr/linkedin' => $s->saveLinkedIn($row, $job, $body['links'] ?? null, $user['email'], 'hr'),
                'hr/add-decision-maker' => $s->addDecisionMaker($row, $job, $body['link'] ?? null, $user['email'], 'hr'),
                'hr/approve' => $s->approve($row, $job, $user, 'hr'),
                'hr/undo-approve' => $s->undoApprove($row, $job, $user['email'], 'hr'),
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
            try {
                $s = $atlas->summary($title, $sub, $group);
                if (!empty($s['none'])) {
                    $out[] = ['none' => true];
                    continue;
                }
                $out[] = ['n' => $s['n'], 'm' => $s['m'], 'pools' => $s['families'], 'reason' => $s['reason'], 'index' => $s['index']];
            } catch (AtlasRateLimited $e) {
                $out[] = ['error' => 'rate', 'retryAfter' => $e->retryAfter];
            } catch (AppError $e) {
                $out[] = ['error' => $e->errCode];
            }
        }
        return ['items' => $out];
    }

    private ?MarketSearch $market = null;

    /** "In the market" (Prospeo). */
    private function prospeo(): MarketSearch
    {
        if ($this->market) return $this->market;
        $client = Prospeo::client($this->config) ?? throw new AppError(503, 'PROSPEO_OFF', 'Prospeo is not configured (PROSPEO_API_KEY in config.php).');
        return $this->market = new MarketSearch($client, Prospeo::ledger($this->config), $this->config['STORAGE_DIR'], $this->atlas(),
            $this->atlas()?->dictionary() ?? JdDictionary::default());
    }

    private ?Outreach $outreach = null;

    private function outreach(): Outreach
    {
        if ($this->outreach) return $this->outreach;
        $store = Lemlist::mode($this->config) === 'off' ? null : Lemlist::store($this->config);
        if (!$store) throw new AppError(503, 'LEMLIST_OFF', 'lemlist is not configured (LEMLIST_API_KEY in config.php).');
        return $this->outreach = new Outreach($store, $this->config, $this->config['TIMEZONE']);
    }

    private function selections(): OutreachSelections
    {
        return new OutreachSelections($this->config['STORAGE_DIR'] . '/outreach-selections.json');
    }

    /** Selected campaign ids from ?c= (empty = all visible). */
    private function outreachIds(): array
    {
        $visible = array_column($this->outreach()->campaigns(), 'id');
        $sel = OutreachSelection::parse(isset($_GET['c']) ? (string) $_GET['c'] : null, null, $visible);
        return $sel['all'] ? $visible : $sel['ids'];
    }

    /** The Outreach screen: campaigns, quick groups, saved selections, the resolved selection and its numbers. */
    private function outreachView(string $user): array
    {
        $o = $this->outreach();
        $campaigns = $o->campaigns();
        $visible = array_column($campaigns, 'id');
        $mine = $this->selections()->forUser($user);
        $c = isset($_GET['c']) ? (string) $_GET['c'] : null;
        $p = isset($_GET['p']) ? (string) $_GET['p'] : null;
        if ($c === null && $p === null && $mine['last']) { // nothing in the URL: the user's last selection
            $c = implode(',', $mine['last']['c'] ?? []);
            $p = (string) ($mine['last']['p'] ?? '7');
        }
        $sel = OutreachSelection::parse($c, $p, $visible);
        $ids = $sel['all'] ? $visible : $sel['ids'];
        $period = $sel['p'] ?? '7';
        if (!$sel['all'] && !$ids) { $ids = $visible; $sel['all'] = true; } // everything selected stopped running
        $this->selections()->remember($user, $sel['all'] ? [] : $ids, $period);
        $names = [];
        foreach ($this->outreachStoppedNames($sel['dropped']) as $n) $names[] = $n;
        return ['campaigns' => $campaigns, 'groups' => OutreachSelection::groups($campaigns), 'saved' => $mine['saved'],
            'selection' => ['ids' => $ids, 'all' => $sel['all'], 'dropped' => $names, 'p' => $period, 'query' => OutreachSelection::query($sel['all'] ? [] : $ids, $period, count($visible))],
            'dashboard' => $o->dashboard($ids, $period)];
    }

    private function outreachStoppedNames(array $ids): array
    {
        if (!$ids) return [];
        $store = Lemlist::store($this->config);
        $out = [];
        foreach ($ids as $id) $out[] = (string) ($store->one('SELECT name FROM campaigns WHERE id = ?', [$id]) ?: $id);
        return $out;
    }

    private function outreachSelection(array $body, string $user): array
    {
        $s = $this->selections();
        return match ((string) ($body['action'] ?? '')) {
            'save' => ['saved' => $s->save($user, $body['name'] ?? null, $body['c'] ?? null, isset($body['id']) && is_string($body['id']) ? $body['id'] : null)],
            'delete' => ['deleted' => $s->delete($user, self::str($body['id'] ?? '', 40))],
            default => throw new ValidationError('action must be save or delete'),
        };
    }

    private function lemlistHook(string $method): void
    {
        try {
            $raw = (string) file_get_contents('php://input', false, null, 0, LemlistWebhook::MAX_BODY + 1);
            $store = Lemlist::mode($this->config) === 'off' ? null : Lemlist::store($this->config);
            [$status, $body] = LemlistWebhook::handle($this->config, $method, $raw, $store);
        } catch (\Throwable $e) {
            Audit::event('lemlist_hook_error', ['where' => basename($e->getFile()) . ':' . $e->getLine()]);
            [$status, $body] = [500, ['error' => 'Server error']];
        }
        $this->json($body, $status);
    }

    /** Read a signal's job page for JD matching; anything blocked or empty asks the user to paste the JD. */
    private function jdFetch(mixed $url): array
    {
        $url = is_string($url) ? trim($url) : '';
        if ($url === '' || strlen($url) > 2000) throw new ValidationError('Job URL missing');
        $r = (new JobPageFetcher())->fetch($url);
        return $r['ok'] ? ['ok' => true, 'text' => $r['text'], 'host' => $r['host']] : ['ok' => false, 'needPaste' => true, 'reason' => $r['reason']];
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
            . '<body class="antialiased" data-page="' . self::e($page) . '" data-csrf="' . self::e($csrf) . '" data-atlas="' . (Atlas::mode($config) === 'off' ? 'off' : 'on') . '" data-jd="' . (Atlas::mode($config) !== 'off' ? 'on' : 'off') . '" data-ai="' . (Llms::mode($config) !== 'off' ? 'on' : 'off') . '" data-prospeo="' . (Prospeo::mode($config) !== 'off' ? 'on' : 'off') . '"><div class="min-h-dvh">' . self::banner($config)
            . '<header class="sticky top-0 z-20 border-b border-line/60 bg-black/70 backdrop-blur"><div class="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">'
            . '<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-pink" aria-hidden="true"></span><span class="font-semibold tracking-tight">Signal Desk</span></div>'
            . '<div class="flex items-center gap-3 text-sm"><span class="hidden text-white/60 sm:inline">' . self::e($user['name']) . '</span>'
            . '<form method="post" action="/logout"><input type="hidden" name="csrf" value="' . self::e($csrf) . '"><button type="submit" class="rounded-lg border border-line px-3 py-1.5 text-white/80 hover:border-pink hover:text-white">Log out</button></form></div></div>'
            . '<nav class="mx-auto flex max-w-3xl gap-1 px-3 sm:px-4" aria-label="Main">' . $tab('/signals', 'Signals', $page === 'signals') . $tab('/verified', 'Verified', $page === 'verified') . $tab('/hr', 'HR signals', $page === 'hr') . (Lemlist::mode($config) === 'off' ? '' : $tab('/outreach', 'Outreach', $page === 'outreach')) . $tab('/candidates', 'Candidates', $page === 'candidates') . (Atlas::mode($config) === 'off' ? '' : $tab('/lists', 'Lists', $page === 'lists')) . '</nav></header>'
            . '<main id="app" class="mx-auto ' . ($page === 'outreach' ? 'sd-o-main' : 'max-w-3xl') . ' px-3 pb-16 pt-4 sm:px-4"><p class="flex items-center gap-2 text-sm text-white/70"><span class="sd-spinner"></span> Reading the master sheet…</p></main>'
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
