<?php
// The approved mockup design for the lemlist pages: one-row top bar (tabs, Calls badge, live status), self-hosted Inter
// (CSP-safe, content-hashed files), KPI tiles without the change line, the lead board's "More filters" pill.
declare(strict_types=1);

use SignalDesk\Config;
use SignalDesk\Views;

function t_design_config(string $storage, string $lemlist = 'mock'): array
{
    return Config::validate([
        'APP_ENV' => 'development', 'WORKBOOK_BACKEND' => 'mock', 'APP_ORIGIN' => '', 'TIMEZONE' => '', 'AZURE_TENANT_ID' => '',
        'AZURE_CLIENT_ID' => '', 'AZURE_CLIENT_SECRET' => '', 'GRAPH_DRIVE_ID' => '', 'GRAPH_MASTER_ITEM_ID' => '', 'GRAPH_DEV_ITEM_ID' => '',
        'AUTH_USERS' => 'a@x.test|A|' . base64_encode(password_hash('long enough pass', PASSWORD_BCRYPT, ['cost' => 4])), 'STORAGE_DIR' => $storage,
        'LEMLIST_BACKEND' => $lemlist, 'ATLAS_BACKEND' => 'mock',
    ], '/p');
}

test('Top bar: one row with the tabs in mockup order, Calls with its open-task badge, the live status and log out', function () {
    [$store, , $dir] = t_lem_synced();
    $store->setMeta('last_event_at', '2026-11-16T09:00:00Z');
    $due = \SignalDesk\CallDesk::dueCount($store);
    ok($due > 0, 'mock has due tasks');
    $html = Views::shell('outreach', ['email' => 'a@x.test', 'name' => 'Jonathan Perkins'], t_design_config($dir));
    eq(1, substr_count($html, '<nav'), 'one nav row, no second one');
    ok((bool) preg_match('#<header class="sd-top">.*<span class="sd-top-brand">Signal Desk</span><nav class="sd-top-nav" aria-label="Main">#s', $html), 'logo and tabs on the same line');
    preg_match_all('#<a href="(/[a-z]+)"[^>]*class="sd-top-tab[^"]*">([^<]+)#', $html, $m);
    eq(['Signals', 'Verified', 'HR signals', 'Candidates', 'Lists', 'Outreach', 'Calls'], array_map('trim', $m[2]));
    eq(['/signals', '/verified', '/hr', '/candidates', '/lists', '/outreach', '/calls'], $m[1]);
    ok(str_contains($html, 'aria-current="page" class="sd-top-tab sd-top-tab-strong sd-top-tab-on">Outreach'), 'Outreach is the active tab');
    ok(str_contains($html, '>Calls <span class="sd-top-badge">' . $due . '</span>'), 'Calls badge = open (due) call + manual tasks');
    ok(str_contains($html, '<span id="sd-live" class="sd-live" data-at="2026-11-16T09:00:00Z">'), 'live status from the last webhook event');
    ok(str_contains($html, 'Jonathan Perkins · Log out'), 'user and log out on the right');
    ok(str_contains($html, '<body class="antialiased sd-o-body"') && str_contains($html, '<main id="app" class="sd-o-main">'), 'mockup page frame on the lemlist pages');
    $other = Views::shell('signals', ['email' => 'a@x.test', 'name' => 'A'], t_design_config($dir));
    ok(!str_contains($other, 'sd-o-body') && str_contains($other, 'max-w-3xl'), 'other pages keep their layout');
    ok(str_contains(Views::shell('calls', ['email' => 'a@x.test', 'name' => 'A'], t_design_config($dir)), 'data-page="calls"'), 'Calls page');
    $off = Views::shell('signals', ['email' => 'a@x.test', 'name' => 'A'], t_design_config(tmpdir(), 'off'));
    ok(!str_contains($off, '/outreach') && !str_contains($off, '/calls') && !str_contains($off, 'sd-live'), 'lemlist off: no Outreach/Calls tabs, no live status');
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    ok(str_contains($js, 'else if (page === "calls") callsPage();') && !str_contains($js, 'Calls: coming soon'), 'the Calls tab is the SDR desk now');
    foreach (['"SDR desk"', '"Call tasks"', '"Manual tasks"', '"Inbox"', '"Now calling"', '"1. Did they answer?"', '"2. Outcome"', '"3. Note (goes to lemlist)"', 'Save & complete task in lemlist',
        '"Send via lemlist"', 'Approximate preview'] as $t) ok(str_contains($js, $t), "Calls tab wording $t");
});

test('Inter is self-hosted: 400/500/600/700 woff2 with content-hash names, @font-face in extra.css, no Google Fonts, served by .htaccess', function () {
    $pub = __DIR__ . '/../public';
    $css = (string) file_get_contents("$pub/assets/extra.css");
    preg_match_all('#@font-face \{ font-family: Inter; font-style: normal; font-weight: (\d00);[^}]*src: url\("fonts/([a-z0-9.-]+\.woff2)"\)#', $css, $m, PREG_SET_ORDER);
    $weights = [];
    foreach ($m as [, $w, $file]) {
        $path = "$pub/assets/fonts/$file";
        ok(is_file($path), "font file $file exists");
        ok(preg_match('#\.([0-9a-f]{8})\.woff2$#', $file, $h) && substr(hash_file('sha256', $path), 0, 8) === $h[1], "$file name carries its content hash (cache-busting)");
        eq('wOF2', file_get_contents($path, false, null, 0, 4), "$file is woff2");
        $weights[$w] = true;
    }
    eq([400, 500, 600, 700], array_keys($weights));
    eq(8, count($m), 'latin + latin-ext for each weight');
    ok(is_file("$pub/assets/fonts/Inter-LICENSE.txt"), 'OFL licence shipped with the fonts');
    foreach (['fonts.googleapis', 'fonts.gstatic', '@import'] as $bad) ok(!str_contains($css, $bad) && !str_contains((string) file_get_contents("$pub/assets/app.js"), $bad), "no $bad");
    ok(str_contains($css, '.sd-o-body {') && str_contains($css, 'font-family: Inter, system-ui, sans-serif'), 'Inter is the lemlist pages\' font');
    $ht = (string) file_get_contents("$pub/.htaccess");
    ok(str_contains($ht, 'RewriteRule ^assets/fonts/[A-Za-z0-9._-]+\.woff2$ - [L]'), '.htaccess serves assets/fonts/*.woff2');
    ok(str_contains($ht, 'AddType font/woff2 .woff2'));
    ok(str_contains((string) file_get_contents("$pub/index.php"), "#^/assets/(fonts/)?[A-Za-z0-9._-]+$#"), 'local dev router serves the fonts too');
});

test('Outreach markup: tiles are label + number + one sub-line (change only in the tooltip); "More filters" pill holds the extra board filters', function () {
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    $page = substr($js, strpos($js, 'function outreachPage'), 60000);
    $tile = substr($page, strpos($page, 'function tile('), 900);
    eq(3, substr_count($tile, 'h("span"'), 'three lines per tile');
    ok(str_contains($tile, 'title: delta') && !str_contains($tile, 'sd-o-delta'), 'change vs previous period only as a tooltip');
    ok(str_contains($page, '"More filters"') && str_contains($page, 'if (b.more) boardBox.appendChild('), 'extra filters behind "More filters", closed by default');
    ok(str_contains($page, 'board: { view: "table", f:') && !str_contains($page, 'more: true'), 'closed by default');
    foreach (['Campaign', 'Channel state', 'IT or HR', 'Role group'] as $label) ok(str_contains($page, 'select("' . $label . '"'), "filter $label kept");
    ok(str_contains($page, 'Needs action only'));
    $tl = substr($page, strpos($page, 'function renderPanel'), 5000);
    ok(str_contains($tl, '"sd-card sd-o-tl"') && str_contains($tl, '"sd-o-tl-body"') && str_contains($tl, '"sd-o-tl-side"'), 'timeline card: timeline left, outcomes right');
    ok(str_contains($page, 'top, body, boardBox, panel'), 'timeline card full width below the board');
    $css = (string) file_get_contents(__DIR__ . '/../public/assets/extra.css');
    ok(str_contains($css, '.sd-o-tl { padding: 20px; display: flex; flex-direction: column; gap: 14px; border-color: var(--sd-accent); }'), 'mockup paddings, pink border');
    ok(str_contains($css, '.sd-o-tl-body { display: flex; flex-wrap: wrap; gap: 24px; }'));
});
