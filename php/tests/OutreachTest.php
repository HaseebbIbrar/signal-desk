<?php
// lemlist Phase 2 (Outreach tab): running campaigns only (new ones appear, paused ones disappear, restarted ones come back
// with their history), selection totals, saved selections, URL round-trip, compare mode, quick groups, outcomes, Atlas business.
declare(strict_types=1);

use SignalDesk\AtlasClient;
use SignalDesk\ListMap;
use SignalDesk\MockAtlas;
use SignalDesk\MockLemlist;
use SignalDesk\NotFoundError;
use SignalDesk\Outreach;
use SignalDesk\OutreachBusiness;
use SignalDesk\OutreachPeriod;
use SignalDesk\OutreachSelection;
use SignalDesk\OutreachSelections;
use SignalDesk\LemlistStore;
use SignalDesk\LemlistSync;
use SignalDesk\ValidationError;

const T_OUT_NOW = 1_794_500_000;
const T_OUT_IT = 'cam_it000001';
const T_OUT_HR = 'cam_hr000001';

function t_out(LemlistStore $store, array $cfg = []): Outreach
{
    $o = new Outreach($store, $cfg + ['LEMLIST_ATTRIBUTION' => 'first_positive', 'LEMLIST_SHOW_STOPPED' => 'false']);
    $o->now = fn () => T_OUT_NOW;
    return $o;
}

function t_out_resync(LemlistStore $store, MockLemlist $mock, $client): void
{
    $s = new LemlistSync($client, $store);
    $s->now = fn () => T_OUT_NOW + 3600;
    eq('done', $s->run(600)['result']);
}

/** Every number of a metrics() result that must add up across campaigns. */
function t_out_additive(array $m): array
{
    $out = $m['tiles'];
    foreach ($m['channels'] as $k => $c) { $out["$k.actions"] = $c['actions']; $out["$k.responses"] = $c['responses']; $out["$k.reached"] = $c['reached']; $out["$k.rateActions"] = $c['rate']['actions']; }
    foreach ($m['business'] as $r) foreach (['reached', 'responded', 'meeting', 'job', 'deal', 'client'] as $f) $out[$r['channel'] . ".$f"] = $r[$f];
    return $out;
}

test('outreach: only running campaigns; a new running one appears, a paused one disappears, a restart brings its history back', function () {
    [$store, $mock, , $client] = t_lem_synced();
    $o = t_out($store);
    eq([T_OUT_HR, T_OUT_IT], array_column($o->campaigns(), 'id'), 'the paused mock campaign is never shown');
    eq(0, (int) $store->one("SELECT COUNT(*) FROM campaigns WHERE id = 'cam_old00001'"), 'paused campaigns are not even synced');

    $mock->cloneCampaign(T_OUT_HR, 'cam_mpc00001', 'MPC HR – Brussels');
    t_out_resync($store, $mock, $client);
    $o = t_out($store);
    eq([T_OUT_HR, T_OUT_IT, 'cam_mpc00001'], array_column($o->campaigns(), 'id'), 'new running campaign appears');
    eq(3, $o->campaigns()[2]['leads']);

    foreach ($mock->campaigns as &$c) if ($c['_id'] === T_OUT_IT) $c['status'] = 'paused';
    unset($c);
    t_out_resync($store, $mock, $client);
    $o = t_out($store);
    eq([T_OUT_HR, 'cam_mpc00001'], array_column($o->campaigns(), 'id'), 'paused campaign disappears');
    eq('stopped', $store->one('SELECT status FROM campaigns WHERE id = ?', [T_OUT_IT]));
    $keptActs = (int) $store->one('SELECT COUNT(*) FROM activities WHERE campaign_id = ?', [T_OUT_IT]);
    ok($keptActs > 0, 'history of the stopped campaign is kept');
    eq(7, (int) $store->one('SELECT COUNT(*) FROM leads WHERE campaign_id = ?', [T_OUT_IT]), 'its leads are kept too');

    $shown = t_out($store, ['LEMLIST_SHOW_STOPPED' => 'true'])->campaigns();
    eq([T_OUT_HR, T_OUT_IT, 'cam_mpc00001'], array_column($shown, 'id'), 'LEMLIST_SHOW_STOPPED=true shows stopped ones');
    eq(false, $shown[1]['running']);

    // a selection holding the stopped campaign: dropped, with its id reported
    $sel = OutreachSelection::parse(T_OUT_IT . ',cam_mpc00001', '7', array_column($o->campaigns(), 'id'));
    eq(['cam_mpc00001'], $sel['ids']);
    eq([T_OUT_IT], $sel['dropped']);

    foreach ($mock->campaigns as &$c) if ($c['_id'] === T_OUT_IT) $c['status'] = 'running';
    unset($c);
    t_out_resync($store, $mock, $client);
    $o = t_out($store);
    eq([T_OUT_HR, T_OUT_IT, 'cam_mpc00001'], array_column($o->campaigns(), 'id'), 'restarted campaign comes back');
    ok((int) $store->one('SELECT COUNT(*) FROM activities WHERE campaign_id = ?', [T_OUT_IT]) >= $keptActs, 'with its history');
});

test('outreach: totals for a 2-campaign selection equal the sum of the two', function () {
    [$store] = t_lem_synced();
    $o = t_out($store);
    $all = [0, 2_000_000_000];
    $it = t_out_additive($o->metrics([T_OUT_IT], ...$all));
    $hr = t_out_additive($o->metrics([T_OUT_HR], ...$all));
    $both = t_out_additive($o->metrics([T_OUT_IT, T_OUT_HR], ...$all));
    ok($both['reached'] > 0 && $it['email.actions'] > 0 && $hr['invite.actions'] > 0, 'mock data reaches both campaigns');
    foreach ($both as $k => $v) eq($it[$k] + $hr[$k], $v, "sum of $k");

    $d = $o->dashboard([T_OUT_IT, T_OUT_HR], '30');
    eq(3, count($d['campaigns']), 'two campaign rows plus Total');
    $total = $d['campaigns'][2];
    eq('Total', $total['name']);
    foreach (['email', 'invite', 'message', 'call'] as $k) {
        eq($d['campaigns'][0][$k]['actions'] + $d['campaigns'][1][$k]['actions'], $total[$k]['actions'], "Total row $k actions");
        eq($d['campaigns'][0][$k]['responses'] + $d['campaigns'][1][$k]['responses'], $total[$k]['responses'], "Total row $k responses");
    }
    eq($d['tiles']['reached']['value'], $total['reached'], 'tile equals Total row');
    ok(isset($d['tiles']['reached']['delta']), 'tiles carry the change vs the previous period');
});

test('outreach: selection URL round-trip, defaults and junk', function () {
    $visible = ['cam_a00001', 'cam_b00001', 'cam_c00001'];
    $q = OutreachSelection::query(['cam_a00001', 'cam_c00001'], '30', 3);
    eq('?c=cam_a00001%2Ccam_c00001&p=30', $q);
    parse_str(substr($q, 1), $got);
    $sel = OutreachSelection::parse($got['c'], $got['p'], $visible);
    eq(['cam_a00001', 'cam_c00001'], $sel['ids']);
    eq('30', $sel['p']);
    eq(false, $sel['all']);
    eq('?p=7', OutreachSelection::query($visible, '7', 3), 'all selected: no c in the URL');
    $sel = OutreachSelection::parse(null, null, $visible);
    eq(true, $sel['all']);
    eq(null, $sel['p'], 'no period in the URL: caller uses the remembered/default one');
    $sel = OutreachSelection::parse('cam_a00001,<script>,cam_a00001', 'year', $visible);
    eq(['cam_a00001'], $sel['ids'], 'junk ids ignored, duplicates removed');
    eq([], $sel['dropped']);
    eq(null, $sel['p'], 'unknown period ignored');
    foreach (['today', '7', '30'] as $p) {
        $r = OutreachPeriod::range($p, T_OUT_NOW, 'Europe/Brussels');
        ok($r['start'] < $r['end'] && $r['end'] - $r['start'] === $r['prevEnd'] - $r['prevStart'], "period $p and an equally long previous period");
        $p === 'today' ? eq(86400, $r['start'] - $r['prevStart'], 'today vs yesterday up to the same hour') : eq($r['start'], $r['prevEnd'], "$p days vs the $p days before");
    }
});

test('outreach: saved selections per user (save, rename, delete, last remembered)', function () {
    $dir = tmpdir();
    $s = new OutreachSelections("$dir/outreach-selections.json");
    $a = $s->save('JP@Example.test', 'All MPC', ['cam_a00001', 'cam_b00001']);
    $s->save('jp@example.test', 'IT only', ['cam_c00001']);
    $s->save('other@example.test', 'Mine', ['cam_a00001']);
    $mine = $s->forUser('jp@example.test');
    eq(['All MPC', 'IT only'], array_column($mine['saved'], 'name'), 'per user, case-insensitive email');
    eq(['cam_a00001', 'cam_b00001'], $mine['saved'][0]['c']);
    $s->save('jp@example.test', 'MPC (all)', ['cam_a00001'], $a['id']);
    eq(['MPC (all)', 'IT only'], array_column($s->forUser('jp@example.test')['saved'], 'name'), 'edit keeps the entry');
    eq(['cam_a00001'], $s->forUser('jp@example.test')['saved'][0]['c']);
    throws(fn () => $s->save('jp@example.test', 'x', ['cam_a00001'], 'nope'), NotFoundError::class);
    throws(fn () => $s->save('jp@example.test', '', ['cam_a00001']), ValidationError::class);
    throws(fn () => $s->save('jp@example.test', 'Empty', []), ValidationError::class);
    eq(true, $s->delete('jp@example.test', $a['id']));
    eq(false, $s->delete('other@example.test', $s->forUser('jp@example.test')['saved'][0]['id']), "can't delete another user's selection");
    eq(['IT only'], array_column($s->forUser('jp@example.test')['saved'], 'name'));
    eq(['Mine'], array_column($s->forUser('other@example.test')['saved'], 'name'));
    eq(null, $s->forUser('jp@example.test')['last']);
    $s->remember('jp@example.test', ['cam_c00001'], '30');
    $last = (new OutreachSelections("$dir/outreach-selections.json"))->forUser('jp@example.test')['last'];
    eq(['cam_c00001'], $last['c']);
    eq('30', $last['p']);
});

test('outreach: compare mode numbers match each campaign on its own', function () {
    [$store] = t_lem_synced();
    $o = t_out($store);
    $c = $o->compare([T_OUT_IT, T_OUT_HR], '30');
    eq([T_OUT_IT, T_OUT_HR], array_column($c['campaigns'], 'id'));
    eq([7, 3], array_column($c['campaigns'], 'leads'));
    $d = $o->dashboard([T_OUT_IT, T_OUT_HR], '30');
    foreach ([0, 1] as $i) foreach (['reached', 'email', 'invite', 'message', 'call', 'meetings', 'jobs', 'deals', 'clients'] as $k)
        eq($d['campaigns'][$i][$k], $c['campaigns'][$i][$k], "compare $k for " . $c['campaigns'][$i]['id']);
    throws(fn () => $o->compare([T_OUT_IT], '7'), ValidationError::class);
    throws(fn () => $o->compare(['a00', 'b00', 'c00', 'd00'], '7'), ValidationError::class);
});

test('outreach: quick groups from campaign names', function () {
    $g = OutreachSelection::groups([
        ['id' => 'c1', 'name' => 'MPC – Security Architect'], ['id' => 'c2', 'name' => 'MPC – Cloud Engineer'], ['id' => 'c3', 'name' => 'HR directors Belgium'],
        ['id' => 'c4', 'name' => 'HR business partners'], ['id' => 'c5', 'name' => 'IT decision makers'],
    ]);
    eq(['All HR', 'All MPC'], array_column($g, 'name'));
    eq(['c1', 'c2'], $g[1]['ids']);
    eq([], OutreachSelection::groups([['id' => 'c1', 'name' => 'MPC a'], ['id' => 'c2', 'name' => 'MPC b']]), 'a word in every campaign is no group');
});

test('outreach: outcome buttons are stored in the app only, audited without personal data', function () {
    [$store, $mock, $dir] = t_lem_synced();
    $oldSink = \SignalDesk\Audit::$sink;
    $events = [];
    \SignalDesk\Audit::$sink = function (array $e) use (&$events) { $events[] = $e; };
    $o = t_out($store);
    $before = count($mock->calls);
    $r = $o->setOutcome('lea_it02', 'Meeting', 'jp@example.test');
    eq('Meeting', $r['outcome']['outcome']);
    eq($before, count($mock->calls), 'nothing is sent to lemlist');
    $lead = $o->lead('lea_it02');
    eq('Meeting', $lead['outcome']['outcome']);
    ok(in_array('Meeting', $lead['outcomes'], true));
    $m = $o->metrics([T_OUT_IT], 0, 2_000_000_000);
    ok($m['tiles']['meetings'] >= 1, 'the Meeting button counts as a meeting');
    throws(fn () => $o->setOutcome('lea_it02', 'Married', 'jp@example.test'), ValidationError::class);
    throws(fn () => $o->setOutcome('lea_nobody', 'Deal', 'jp@example.test'), NotFoundError::class);
    eq(null, $o->setOutcome('lea_it02', null, 'jp@example.test')['outcome'], 'clearing works');
    \SignalDesk\Audit::$sink = $oldSink;
    $log = json_encode($events);
    ok(str_contains($log, 'outreach_outcome') && str_contains($log, 'lea_it02'), 'audited with ids');
    ok(!str_contains($log, 'mail.example') && !str_contains($log, '+32'), 'no emails or phones in the audit log');
});

test('outreach: Atlas jobs, deals and placements are matched to lead companies', function () {
    eq('examplemssp', OutreachBusiness::companyKey('Example MSSP NV'));
    eq(OutreachBusiness::companyKey('Meet NV'), OutreachBusiness::companyKey('MEET'));
    [$store] = t_lem_synced();
    $dir = tmpdir();
    $client = new AtlasClient('https://mock.atlas.test', 'k');
    $client->transport = new MockAtlas(ListMap::load($dir, T_DEFAULT_MAP)['map']);
    $client->sleep = fn () => null;
    $n = OutreachBusiness::sync($client, $store, T_OUT_NOW);
    ok($n['job'] >= 1 && $n['deal'] >= 1 && $n['placement'] >= 1, 'mock Atlas has a job, a deal and a placement');
    eq(['skipped' => true], OutreachBusiness::sync($client, $store, T_OUT_NOW + 60), 'hourly');
    $m = t_out($store)->metrics([T_OUT_IT, T_OUT_HR], 0, 2_000_000_000);
    ok($m['tiles']['jobs'] >= 1, 'new job for a lead company');
    ok($m['tiles']['deals'] >= 1, 'deal');
    ok($m['tiles']['clients'] >= 1, 'new client (first placement)');
    $total = end($m['business']);
    eq('Total', $total['channel']);
    eq($m['tiles']['jobs'], $total['job']);
});

test('outreach: lead board filters, paging and timeline', function () {
    [$store] = t_lem_synced();
    $o = t_out($store);
    $b = $o->leads([T_OUT_IT, T_OUT_HR], [], 1);
    eq(10, $b['total']);
    eq(10, array_sum($b['byStatus']));
    eq(3, $o->leads([T_OUT_IT, T_OUT_HR], ['campaign' => T_OUT_HR], 1)['total']);
    eq(1, $o->leads([T_OUT_IT, T_OUT_HR], ['q' => 'replier'], 1)['total']);
    eq(0, $o->leads([T_OUT_HR], ['campaign' => T_OUT_IT], 1)['total'], 'campaign filter stays inside the selection');
    $bounced = $o->leads([T_OUT_IT], ['channel' => 'email:bounced'], 1);
    eq(['lea_it03'], array_column($bounced['rows'], 'id'));
    $t = $o->lead('lea_it02');
    ok(count($t['timeline']) >= 2, 'timeline has events');
    ok(str_starts_with($t['lemlistUrl'], 'https://app.lemlist.com/'));
    $row = $b['rows'][0];
    foreach (['email', 'phone'] as $k) ok(!array_key_exists($k, $row), "no $k in board rows");
});

test('outreach: status chips keep their counts while one status is filtered', function () {
    [$store] = t_lem_synced();
    $o = t_out($store);
    $all = $o->leads([T_OUT_IT, T_OUT_HR], [], 1);
    $one = $o->leads([T_OUT_IT, T_OUT_HR], ['status' => 'Bounced'], 1);
    eq($all['byStatus'], $one['byStatus'], 'counts per status ignore the status filter');
    eq($all['byStatus']['Bounced'], $one['total']);
    eq(['Bounced'], array_values(array_unique(array_column($one['rows'], 'status'))));
    $card = $all['board'][array_search('Bounced', array_column($all['board'], 'status'), true)]['cards'][0];
    eq('Email bounced', $card['last'], 'board cards carry the last activity');
    ok($card['at'] !== null);
});

test('outreach design: mockup tokens, a colour for every status and channel, no inline styles (CSP)', function () {
    $css = (string) file_get_contents(__DIR__ . '/../public/assets/extra.css');
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    foreach (['--sd-page: #140c22', '--sd-card: #22163a', '--sd-card-border: #3a2a58', '--sd-divider: #31234c', '--sd-muted: #c3b4dc', '--sd-text: #f4eefb', '--sd-accent: #e23fa6',
        '--sd-email: #7cb4ff', '--sd-invite: #5fd4c4', '--sd-message: #c9a2ff', '--sd-call: #ffb35c',
        '--sd-seq: #5fd4c4', '--sd-con: #7cb4ff', '--sd-rep: #c9a2ff', '--sd-int: #6fe0a0', '--sd-meet: #ffb35c', '--sd-win: #ffd166', '--sd-fin: #b9aecb', '--sd-bnc: #ff8f8f'] as $token)
        ok(str_contains($css, $token), "token $token");
    foreach (\SignalDesk\LeadLogic::STATUSES as $s) {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
        ok(str_contains($css, ".sd-st-$slug {"), "colour class for status \"$s\" (.sd-st-$slug)");
    }
    foreach (['email', 'invite', 'message', 'call'] as $c) ok(str_contains($css, ".sd-c-$c {"), "channel colour $c");
    for ($i = 0; $i < 9; $i++) ok(str_contains($css, ".sd-f-$i {"), "funnel step $i colour");
    foreach (['.sd-btn {', '.sd-btn-on', '.sd-card {', 'border-radius: 16px', 'border-top: 3px solid var(--c)', 'border-top: 4px solid var(--c)', 'min-height: 44px'] as $rule)
        ok(str_contains($css, $rule), "rule $rule");
    ok(!preg_match('/\bstyle\s*:/', $js), 'no style: attributes passed to h()');
    ok(!preg_match('/setAttribute\(\s*["\']style/', $js), 'no style attributes set');
    ok(!preg_match('/\.style\.(?!width\b)[a-zA-Z]/', $js), 'only el.style.width (CSSOM) is used');
    ok(!preg_match('/innerHTML\s*=/', $js), 'no innerHTML');
    $page = substr($js, strpos($js, 'function outreachPage'), 40000);
    foreach (['Outreach dashboard', 'By channel', 'From reach to business', 'Needs action', 'Funnel: signal to new client', 'Running campaigns', 'Live lead board', 'Board by stage',
        'Meetings from it', 'Channel chips: filled = responded'] as $text) ok(str_contains($page, $text), "mockup wording: $text");
    $render = substr($page, strpos($page, 'function renderBody'), 8000);
    $order = array_map(fn ($t) => strpos($render, $t), ['"Reach and results"', 'head("By channel"', 'head("From reach to business"', 'needsCard(d.needs)', 'funnelCard(d.funnel', 'head("Running campaigns"']);
    ok(!in_array(false, $order, true), 'every section is rendered');
    ok(str_contains($page, 'top, body, boardBox, panel'), 'lead board, then the timeline panel, after the sections');
    $sorted = $order;
    sort($sorted);
    eq($sorted, $order, 'sections in the mockup order');
});
