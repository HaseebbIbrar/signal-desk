<?php
// AUTH_USERS parsing, password_verify (incl. $2b$ hashes from the Node app), lockout, config rules.
declare(strict_types=1);

use SignalDesk\Auth;
use SignalDesk\Config;
use SignalDesk\ConfigError;
use SignalDesk\Lockout;

function t_entry(string $email, string $name, string $password, bool $as2b = false): string
{
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    if ($as2b) $hash = '$2b$' . substr($hash, 4);
    return "$email|$name|" . base64_encode($hash);
}

test('AUTH_USERS: parses entries, lower-cases emails, accepts $2y$ and $2b$ hashes', function () {
    $users = Auth::parseUsers(t_entry('JP@SwooScale.test', 'Jonathan Perkins', 'correct horse battery') . ' ; ' . t_entry('h@swooscale.test', 'Haseeb', 'another long pass', true));
    eq(2, count($users));
    eq('jp@swooscale.test', $users[0]['email']);
    eq('Jonathan Perkins', $users[0]['name']);
    ok(password_verify('another long pass', $users[1]['hash']), '$2b$ hash from bcryptjs must verify');
    eq([], Auth::parseUsers(''));
});

test('AUTH_USERS: malformed entries are refused', function () {
    throws(fn () => Auth::parseUsers('jp@x.test|JP'), ConfigError::class, 'email|Name|base64hash');
    throws(fn () => Auth::parseUsers('not-an-email|JP|' . base64_encode(password_hash('x', PASSWORD_BCRYPT))), ConfigError::class, 'invalid email');
    throws(fn () => Auth::parseUsers('jp@x.test|JP|' . base64_encode('plaintext')), ConfigError::class, 'not a base64-encoded bcrypt hash');
});

test('Login: right password signs in (session regenerated + CSRF set), wrong one does not', function () {
    $lock = new Lockout(tmpdir() . '/lockout.json');
    $auth = new Auth(Auth::parseUsers(t_entry('jp@x.test', 'JP', 'correct horse battery')), $lock);
    $_SESSION = [];
    $before = session_id();
    eq(['ok' => false, 'error' => 'Invalid email or password.'], $auth->login('jp@x.test', 'wrong password!', '1.2.3.4'));
    eq(['ok' => false, 'error' => 'Invalid email or password.'], $auth->login('nobody@x.test', 'correct horse battery', '1.2.3.4'));
    eq(['ok' => true], $auth->login('JP@x.test', 'correct horse battery', '1.2.3.4'));
    ok(session_id() !== $before, 'session id regenerated on login');
    eq('jp@x.test', $auth->user()['email']);
    ok(Auth::csrfValid($_SESSION['csrf']));
    ok(!Auth::csrfValid('nope'));
    $_SESSION['login_at'] = time() - Auth::SESSION_MAX_AGE - 1;
    eq(null, $auth->user(), 'sessions expire after 12 hours');
    $_SESSION = [];
});

test('Lockout: 5 failed logins per email lock it for 15 minutes; success resets', function () {
    $lock = new Lockout(tmpdir() . '/lockout.json');
    $now = 1_000_000;
    $lock->now = function () use (&$now) { return $now; };
    $auth = new Auth(Auth::parseUsers(t_entry('jp@x.test', 'JP', 'correct horse battery')), $lock);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start(); // the previous test logged out (session destroyed)
    $_SESSION = [];
    for ($i = 0; $i < 5; $i++) $auth->login('jp@x.test', 'bad password ' . $i, '9.9.9.9');
    $r = $auth->login('jp@x.test', 'correct horse battery', '9.9.9.9');
    eq(false, $r['ok'], 'locked even with the right password');
    ok(str_contains($r['error'], '15 minutes'));
    $now += 901;
    eq(['ok' => true], $auth->login('jp@x.test', 'correct horse battery', '9.9.9.9'), 'unlocked after 15 minutes');
    ok(!$lock->isLocked('jp@x.test', '9.9.9.9'));
    $_SESSION = [];
});

test('Origin check: APP_ORIGIN, or "Origin: null" only with Sec-Fetch-Site: same-origin', function () {
    $saved = $_SERVER;
    $check = function (array $headers) {
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER', 'HTTP_SEC_FETCH_SITE'] as $k) unset($_SERVER[$k]);
        foreach ($headers as $k => $v) $_SERVER[$k] = $v;
        return Auth::originValid('https://signals.swooscale.com');
    };
    ok($check(['HTTP_ORIGIN' => 'https://signals.swooscale.com']));
    ok(!$check(['HTTP_ORIGIN' => 'https://evil.example']));
    ok(!$check(['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_SEC_FETCH_SITE' => 'same-origin']), 'a real foreign Origin always loses');
    ok($check(['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'same-origin']), 'form POST under no-referrer');
    ok(!$check(['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'cross-site']));
    ok(!$check(['HTTP_ORIGIN' => 'null']));
    ok(!$check([]));
    ok($check(['HTTP_REFERER' => 'https://signals.swooscale.com/login']));
    $_SERVER = $saved;
});

test('Lockout: 20 failures from one IP lock that IP', function () {
    $lock = new Lockout(tmpdir() . '/lockout.json');
    for ($i = 0; $i < 20; $i++) $lock->fail("user$i@x.test", '5.5.5.5');
    ok($lock->isLocked('someone-new@x.test', '5.5.5.5'));
    ok(!$lock->isLocked('someone-new@x.test', '6.6.6.6'));
});

test('Config: mock refused in production; dev must not point at the master; graph needs ids', function () {
    $base = ['APP_ENV' => 'development', 'WORKBOOK_BACKEND' => 'mock', 'APP_ORIGIN' => '', 'TIMEZONE' => '', 'AZURE_TENANT_ID' => '', 'AZURE_CLIENT_ID' => '',
        'AZURE_CLIENT_SECRET' => '', 'GRAPH_DRIVE_ID' => '', 'GRAPH_MASTER_ITEM_ID' => '', 'GRAPH_DEV_ITEM_ID' => '', 'AUTH_USERS' => t_entry('a@x.test', 'A', 'long enough pass'), 'STORAGE_DIR' => ''];
    eq('MOCK DATA', Config::label(Config::validate($base, '/p')));
    throws(fn () => Config::validate(['APP_ENV' => 'production', 'APP_ORIGIN' => 'https://x'] + $base, '/p'), ConfigError::class, 'mock is not allowed');
    $graph = ['WORKBOOK_BACKEND' => 'graph', 'AZURE_TENANT_ID' => 't', 'AZURE_CLIENT_ID' => 'c', 'AZURE_CLIENT_SECRET' => 's', 'GRAPH_DRIVE_ID' => 'd', 'GRAPH_MASTER_ITEM_ID' => 'm'] + $base;
    throws(fn () => Config::validate(['GRAPH_DEV_ITEM_ID' => 'm'] + $graph, '/p'), ConfigError::class, 'COPY');
    eq('DEV COPY', Config::label(Config::validate(['GRAPH_DEV_ITEM_ID' => 'dev'] + $graph, '/p')));
    throws(fn () => Config::validate(['APP_ENV' => 'production'] + $graph, '/p'), ConfigError::class, 'APP_ORIGIN');
    eq('', Config::label(Config::validate(['APP_ENV' => 'production', 'APP_ORIGIN' => 'https://signals.swooscale.com'] + $graph, '/p')));
    throws(fn () => Config::validate(['AZURE_CLIENT_SECRET' => ''] + $graph, '/p'), ConfigError::class, 'AZURE_CLIENT_SECRET');
});
