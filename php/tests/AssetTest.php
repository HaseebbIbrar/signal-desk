<?php
// Cache-busting asset URLs: /assets/<file>?v=<filemtime> (20i's StackCDN caches /assets/* hard).
declare(strict_types=1);

use SignalDesk\App;
use SignalDesk\Config;
use SignalDesk\Health;
use SignalDesk\Views;

test('App::asset: ?v=<filemtime> from DOCUMENT_ROOT/assets; changes when the file changes', function () {
    $root = tmpdir();
    mkdir("$root/assets");
    file_put_contents("$root/assets/app.js", '// v1');
    touch("$root/assets/app.js", 1_700_000_000);
    $saved = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $_SERVER['DOCUMENT_ROOT'] = $root;
    try {
        eq('/assets/app.js?v=1700000000', App::asset('app.js'));
        touch("$root/assets/app.js", 1_700_000_500); // a new upload
        eq('/assets/app.js?v=1700000500', App::asset('app.js'), 'new upload -> new URL');
        eq('/assets/missing.css?v=' . App::ASSET_FALLBACK_VERSION, App::asset('missing.css'), 'missing file -> fallback version');
    } finally {
        $_SERVER['DOCUMENT_ROOT'] = $saved;
    }
});

test('App::asset: falls back to the public folder when DOCUMENT_ROOT is elsewhere', function () {
    $saved = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $_SERVER['DOCUMENT_ROOT'] = tmpdir(); // no assets/ here
    try {
        $public = realpath(__DIR__ . '/../public');
        if (!defined('SD_PUBLIC_DIR')) define('SD_PUBLIC_DIR', $public);
        eq('/assets/app.css?v=' . filemtime(SD_PUBLIC_DIR . '/assets/app.css'), App::asset('app.css'));
    } finally {
        $_SERVER['DOCUMENT_ROOT'] = $saved;
    }
});

test('App::asset: refuses anything that is not a plain file name', function () {
    foreach (['../config.php', 'sub/app.js', '', 'app.js?x=1', "a\nb"] as $bad) {
        throws(fn () => App::asset($bad), InvalidArgumentException::class);
    }
});

test('Pages and /health.php load app.css, extra.css and app.js with ?v= (no bare asset URLs)', function () {
    $saved = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../public');
    try {
        $config = Config::validate([
            'APP_ENV' => 'development', 'WORKBOOK_BACKEND' => 'mock', 'APP_ORIGIN' => '', 'TIMEZONE' => '', 'AZURE_TENANT_ID' => '',
            'AZURE_CLIENT_ID' => '', 'AZURE_CLIENT_SECRET' => '', 'GRAPH_DRIVE_ID' => '', 'GRAPH_MASTER_ITEM_ID' => '', 'GRAPH_DEV_ITEM_ID' => '',
            'AUTH_USERS' => 'a@x.test|A|' . base64_encode(password_hash('long enough pass', PASSWORD_BCRYPT, ['cost' => 4])), 'STORAGE_DIR' => '',
        ], '/p');
        $pages = [
            'login' => Views::login(null, $config),
            'shell' => Views::shell('verified', ['email' => 'a@x.test', 'name' => 'A'], $config),
            'notFound' => Views::notFound(),
            'setupError' => Views::setupError('x'),
            'health' => Health::render([['PHP version', 'OK', '8.1']], 'A'),
        ];
        foreach ($pages as $name => $html) {
            ok(!preg_match('#(href|src)="/assets/[^"?]+"#', $html), "$name has a bare /assets/ URL");
            ok((bool) preg_match('#href="/assets/app\.css\?v=\d+"#', $html), "$name: app.css without ?v=");
            ok((bool) preg_match('#href="/assets/extra\.css\?v=\d+"#', $html), "$name: extra.css without ?v=");
        }
        ok((bool) preg_match('#<script src="/assets/app\.js\?v=\d+" defer>#', $pages['shell']), 'app.js without ?v=');
    } finally {
        $_SERVER['DOCUMENT_ROOT'] = $saved;
    }
});
