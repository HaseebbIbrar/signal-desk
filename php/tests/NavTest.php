<?php
// Navigation: 5 top tabs with sub-tabs, clear paths, 301s from the old URLs (queries kept), the last-used sub-tab per user,
// and the Calls / Inbox badges.
declare(strict_types=1);

use SignalDesk\App;
use SignalDesk\CallDesk;
use SignalDesk\Config;
use SignalDesk\HookBuffer;
use SignalDesk\JsonStore;
use SignalDesk\Nav;
use SignalDesk\Views;

function t_nav_config(string $storage, string $lemlist = 'mock', string $atlas = 'mock'): array
{
    return Config::validate([
        'APP_ENV' => 'development', 'WORKBOOK_BACKEND' => 'mock', 'APP_ORIGIN' => 'https://signals.test', 'TIMEZONE' => '', 'AZURE_TENANT_ID' => '',
        'AZURE_CLIENT_ID' => '', 'AZURE_CLIENT_SECRET' => '', 'GRAPH_DRIVE_ID' => '', 'GRAPH_MASTER_ITEM_ID' => '', 'GRAPH_DEV_ITEM_ID' => '',
        'AUTH_USERS' => 'jp@x.test|JP|' . base64_encode(password_hash('long enough pass', PASSWORD_BCRYPT, ['cost' => 4])), 'STORAGE_DIR' => $storage,
        'LEMLIST_BACKEND' => $lemlist, 'ATLAS_BACKEND' => $atlas,
    ], '/p');
}

/** GET a page through App::run as a signed-in user: [html, redirect location or null]. */
function t_nav_get(array $config, string $uri, ?string $user = 'jp@x.test'): array
{
    $saved = [$_SERVER, $_SESSION ?? [], $_GET];
    $_SESSION = $user ? ['uid' => $user, 'login_at' => time(), 'csrf' => 'tok'] : [];
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'signals.test'];
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
    try {
        ob_start();
        (new App($config))->run();
        $html = (string) ob_get_clean();
    } finally {
        [$_SERVER, $_SESSION, $_GET] = $saved;
    }
    return [$html, Nav::redirect((string) parse_url($uri, PHP_URL_PATH), (function () use ($uri) { parse_str((string) parse_url($uri, PHP_URL_QUERY), $q); return $q; })(), $config['STORAGE_DIR'], $user, $config)];
}

test('nav: every new path renders its page (data-page for app.js) with the right top tab and sub-tab', function () {
    $c = t_nav_config(tmpdir());
    foreach (['/bd/signals' => ['signals', 'bd'], '/bd/verified' => ['verified', 'bd'], '/bd/hr' => ['hr', 'bd'], '/sourcing/candidates' => ['candidates', 'sourcing'],
        '/sourcing/lists' => ['lists', 'sourcing'], '/dashboard' => ['dashboard', 'dashboard'], '/calls/tasks' => ['calls', 'calls'], '/calls/manual' => ['manual', 'calls'], '/inbox' => ['inbox', 'inbox']] as $path => [$page, $top]) {
        eq($page, Nav::pageFor($path), $path);
        [$html, $redir] = t_nav_get($c, $path);
        eq(null, $redir, "$path is not a redirect");
        ok(str_contains($html, 'data-page="' . $page . '"'), "$path renders $page");
        ok((bool) preg_match('#class="sd-top-tab sd-top-tab-strong sd-top-tab-on" data-top="' . $top . '"#', $html), "$path: top tab $top highlighted");
        if (count(Nav::TOPS[$top][1]) > 1) ok(str_contains($html, 'sd-sub-tab-on" aria-current="page" data-sub="' . $page . '"'), "$path: sub-tab highlighted");
    }
    eq(null, Nav::pageFor('/nope'));
    ok(str_contains(t_nav_get($c, '/nope')[0], 'Page not found'));
});

test('nav: old URLs redirect 301 to the new ones, keeping their query (campaign picker, Atlas badge links)', function () {
    $c = t_nav_config(tmpdir());
    $r = fn (string $path, array $q = []) => Nav::redirect($path, $q, $c['STORAGE_DIR'], 'jp@x.test', $c);
    eq(['/bd/signals', 301], $r('/signals'));
    eq(['/bd/signals?date=2026-11-18', 301], $r('/signals', ['date' => '2026-11-18']));
    eq(['/bd/verified', 301], $r('/verified'));
    eq(['/bd/hr', 301], $r('/hr'));
    eq(['/sourcing/candidates?title=Security%20Architect&sub=Cyber&group=A%20-%20Security&jobUrl=https%3A%2F%2Fjobs.test%2F1', 301],
        $r('/candidates', ['title' => 'Security Architect', 'sub' => 'Cyber', 'group' => 'A - Security', 'jobUrl' => 'https://jobs.test/1']), 'Atlas badge link (the #jd fragment stays in the browser)');
    eq(['/sourcing/lists', 301], $r('/lists'));
    eq(['/dashboard?c=cam_it000001%2Ccam_hr000001&p=30', 301], $r('/outreach', ['c' => 'cam_it000001,cam_hr000001', 'p' => '30']), 'campaign picker state kept');
    eq(['/dashboard', 301], $r('/outreach'));
    eq(['/calls/tasks', 301], $r('/calls'));
    eq(['/calls/tasks', 301], $r('/calls', ['sub' => 'calls']));
    eq(['/calls/manual', 301], $r('/calls', ['sub' => 'manual']));
    eq(['/inbox', 301], $r('/calls', ['sub' => 'inbox']));
    eq(null, $r('/bd/signals'));
    eq(null, $r('/dashboard', ['c' => 'x']));
    // through the app: a redirect sends no page; a signed-out visitor is redirected too (then to the login)
    [$html] = t_nav_get($c, '/outreach?c=cam_it000001&p=7');
    eq('', $html, 'no body on a redirect');
    eq(['/bd/signals', 301], Nav::redirect('/signals', [], $c['STORAGE_DIR'], null, $c));
    // links app.js builds use the new paths
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    ok(str_contains($js, 'return "/sourcing/candidates" + ') && str_contains($js, 'replaceState(null, "", "/dashboard" + d.selection.query)'));
    foreach (['"/outreach', '"/candidates', '"/calls?sub', '"/calls" +'] as $old) ok(!str_contains($js, $old), "no old link $old in app.js");
});

test('nav: a top tab opens its last-used sub-tab, remembered per user (first one the first time)', function () {
    $dir = tmpdir();
    $c = t_nav_config($dir);
    eq('/bd/signals', Nav::href('bd', $dir, 'jp@x.test', $c));
    eq(['/bd/signals', 302], Nav::redirect('/', [], $dir, 'jp@x.test', $c));
    t_nav_get($c, '/bd/hr');
    t_nav_get($c, '/sourcing/lists');
    t_nav_get($c, '/calls/manual');
    eq(['/bd/hr', '/sourcing/lists', '/calls/manual'], [Nav::href('bd', $dir, 'jp@x.test', $c), Nav::href('sourcing', $dir, 'jp@x.test', $c), Nav::href('calls', $dir, 'jp@x.test', $c)]);
    eq(['/bd/hr', 302], Nav::redirect('/', [], $dir, 'jp@x.test', $c), '/ opens Business Development where you left it');
    eq(['/bd/hr', 302], Nav::redirect('/bd', [], $dir, 'jp@x.test', $c));
    eq(['/sourcing/lists', 302], Nav::redirect('/sourcing', [], $dir, 'jp@x.test', $c));
    eq('/bd/signals', Nav::href('bd', $dir, 'haseeb@x.test', $c), 'per user');
    $html = Views::shell('dashboard', ['email' => 'jp@x.test', 'name' => 'JP'], $c);
    ok(str_contains($html, '<a href="/bd/hr" class="sd-top-tab sd-top-tab-strong" data-top="bd">') && str_contains($html, '<a href="/calls/manual" class="sd-top-tab sd-top-tab-strong" data-top="calls">'), 'top tabs link to the remembered sub-tab');
    $raw = (string) file_get_contents("$dir/" . Nav::FILE);
    ok(!str_contains($raw, 'jp@x.test'), 'stored under a hash, not the email');
    // Lists hidden (Atlas off): the memory falls back to the first sub-tab
    $noAtlas = t_nav_config($dir, 'mock', 'off');
    eq('/sourcing/candidates', Nav::href('sourcing', $dir, 'jp@x.test', $noAtlas));
    ok(!str_contains(Views::shell('candidates', ['email' => 'jp@x.test', 'name' => 'JP'], $noAtlas), '/sourcing/lists'), 'no Lists sub-tab without Atlas');
    // visiting a page that has no sub-tabs changes nothing
    $before = $raw;
    t_nav_get($c, '/dashboard');
    eq($before, (string) file_get_contents("$dir/" . Nav::FILE));
});

test('nav badges: Calls = open call + manual tasks; Inbox = unread conversations, re-counted every 2 minutes or after a reply webhook', function () {
    [$d, $store, $mock] = t_desk();
    $dir = $store->dir;
    eq(null, CallDesk::cachedUnread($dir));
    $from = count($mock->calls);
    eq(2, $d->unread()['unread']);
    eq(2, CallDesk::cachedUnread($dir));
    $n = count($mock->calls);
    ok($n > $from, 'first count reads the lemlist inbox');
    eq(2, $d->unread()['unread']);
    eq($n, count($mock->calls), 'cached for 2 minutes: no lemlist request');
    $mock->inbox['ctc_it05']['users'][0]['read'] = false; // a new unread conversation
    $cfg = ['LEMLIST_BACKEND' => 'mock', 'LEMLIST_WEBHOOK_SECRET' => T_LEM_SECRET];
    \SignalDesk\LemlistWebhook::handle($cfg, 'POST', json_encode(['_id' => 'act_nav_reply', 'type' => 'emailsReplied', 'secret' => T_LEM_SECRET, 'contactId' => 'ctc_it05']), $store, fn () => T_CALL_NOW + 30);
    eq(3, $d->unread()['unread'], 'a reply webhook makes it count again at once');
    $d->now = fn () => T_CALL_NOW + 400;
    $mock->inbox['ctc_it05']['users'][0]['read'] = true;
    eq(2, $d->unread()['unread'], 'and again after 2 minutes');
    // Calls badge
    $html = Views::shell('calls', ['email' => 'jp@x.test', 'name' => 'JP'], t_nav_config($dir));
    ok(str_contains($html, '<span id="sd-badge-calls" class="sd-top-badge">' . CallDesk::dueCount($store) . '</span>'));
    ok(str_contains($html, '<span id="sd-badge-inbox" class="sd-top-badge">2</span>'));
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    ok(str_contains($js, 'setBadge("sd-badge-calls", d.counts.badge)') && str_contains($js, 'setBadge("sd-badge-inbox", d.unread)') && str_contains($js, 'api("/api/inbox/unread")'), 'app.js keeps both badges current');
});
