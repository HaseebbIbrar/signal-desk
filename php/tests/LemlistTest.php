<?php
// lemlist Phase 1: guard (reads + the explicit write list), client (auth, 429, 402, audit without bodies), webhook
// (secret, dedupe, no personal data in events/logs), sync (full, resumable, 402 calls API), linking to approved DMs,
// lead level / status / channels, 12-month pruning, who may send, config and health.
declare(strict_types=1);

use SignalDesk\AppError;
use SignalDesk\Config;
use SignalDesk\ConfigError;
use SignalDesk\ForbiddenWriteError;
use SignalDesk\Health;
use SignalDesk\LeadLogic;
use SignalDesk\LemlistClient;
use SignalDesk\LemlistGuard;
use SignalDesk\LemlistLinker;
use SignalDesk\LemlistRateLimited;
use SignalDesk\LemlistSender;
use SignalDesk\LemlistStore;
use SignalDesk\LemlistSync;
use SignalDesk\LemlistWebhook;
use SignalDesk\LinkedIn;
use SignalDesk\MockLemlist;
use SignalDesk\ReadPacer;
use SignalDesk\Sheet;

const T_LEM_SECRET = 'test-webhook-secret-0123456789abcdef';

function t_lem(?MockLemlist $mock = null): array
{
    $mock ??= new MockLemlist();
    $c = new LemlistClient('mock-key');
    $c->transport = $mock;
    $c->sleep = function (int $ms) { $GLOBALS['__lemSleeps'][] = $ms; };
    $c->pacer = new ReadPacer(1_000_000);
    $GLOBALS['__lemSleeps'] = [];
    $dir = tmpdir();
    $store = new LemlistStore("$dir/lemlist.sqlite");
    return [$c, $store, $mock, $dir];
}

/** Sync everything, return [store, mock, dir, client]. */
function t_lem_synced(?MockLemlist $mock = null): array
{
    [$c, $store, $mock, $dir] = t_lem($mock);
    $s = new LemlistSync($c, $store);
    $s->now = fn () => 1_794_500_000;
    eq('done', $s->run(600)['result']);
    return [$store, $mock, $dir, $c];
}

/** Level, channels and status of one stored lead. */
function t_lead_view(LemlistStore $store, string $leadId): array
{
    $lead = $store->all('SELECT * FROM leads WHERE lead_id = ?', [$leadId])[0];
    $acts = $store->all('SELECT * FROM activities WHERE lead_id = ?', [$leadId]);
    $steps = $store->all('SELECT * FROM steps WHERE campaign_id = ?', [$lead['campaign_id']]);
    $level = LeadLogic::level($acts, $steps);
    $ch = LeadLogic::channels($acts);
    return ['level' => $level['label'], 'channels' => $ch, 'status' => LeadLogic::status($acts, $lead, $level, $ch)];
}

function t_lem_config(array $extra = []): array
{
    return Config::validate($extra + ['APP_ENV' => 'development', 'WORKBOOK_BACKEND' => 'mock', 'APP_ORIGIN' => 'https://signals.example', 'TIMEZONE' => '',
        'AZURE_TENANT_ID' => '', 'AZURE_CLIENT_ID' => '', 'AZURE_CLIENT_SECRET' => '', 'GRAPH_DRIVE_ID' => '', 'GRAPH_MASTER_ITEM_ID' => '', 'GRAPH_DEV_ITEM_ID' => '',
        'AUTH_USERS' => 'a@x.test|A|' . base64_encode(password_hash('long enough pass', PASSWORD_BCRYPT, ['cost' => 4])), 'STORAGE_DIR' => tmpdir(),
        'LEMLIST_BACKEND' => 'mock', 'LEMLIST_WEBHOOK_SECRET' => T_LEM_SECRET], tmpdir());
}

test('lemlist guard: reads allowed; writes ONLY on the explicit list, with their body rules; everything else refused before any request', function () {
    foreach (['/team', '/campaigns', '/campaigns/cam_abc123/sequences', '/v2/campaigns/cam_abc123/stats', '/v2/campaigns/cam_abc123/export/leads', '/activities', '/v2/tasks',
        '/call-statuses', '/calls/sd-call-123', '/inbox', '/inbox/ctc_abc123', '/inbox/labels', '/hooks', '/users/usr_abc123', '/user/channels'] as $p) LemlistGuard::assert('GET', $p);
    eq('POST /calls', LemlistGuard::assert('POST', '/calls', ['externalId' => 'x']));
    eq('PATCH /tasks', LemlistGuard::assert('PATCH', '/tasks', ['id' => 'opp_1', 'done' => true]));
    eq('PATCH /tasks', LemlistGuard::assert('PATCH', '/tasks', ['id' => 'opp_1', 'dueDate' => '2026-11-20T09:00:00Z']));
    LemlistGuard::assert('POST', '/tasks/ignore', ['ids' => ['opp_1']]);
    LemlistGuard::assert('POST', '/inbox/email', ['message' => 'x']);
    LemlistGuard::assert('POST', '/inbox/linkedin', ['message' => 'x']);
    LemlistGuard::assert('POST', '/inbox/conversations/labels/ctc_abc123', ['labelIds' => ['l1'], 'appendLabels' => true]);
    foreach (LemlistGuard::LABELS as $l) LemlistGuard::assert('POST', '/inbox/labels', ['labelName' => $l]);
    LemlistGuard::assert('POST', '/hooks', ['targetUrl' => 'https://x'], true);
    LemlistGuard::assert('DELETE', '/hooks/hok_abc123', [], true);
    $refused = [
        ['PATCH', '/tasks', ['id' => 'opp_1', 'title' => 'x']], ['PATCH', '/tasks', ['id' => 'opp_1', 'done' => false]], ['PATCH', '/tasks', ['id' => 'opp_1', 'assignedTo' => 'usr_x']],
        ['PATCH', '/tasks', ['id' => 'opp_1', 'done' => true, 'message' => 'x']], ['POST', '/tasks/ignore', ['ids' => []]], ['POST', '/inbox/labels', ['labelName' => 'VIP']],
        ['POST', '/hooks', ['targetUrl' => 'https://x']], ['DELETE', '/hooks/hok_abc123', []], ['DELETE', '/leads/lea_abc123', []], ['POST', '/campaigns/cam_abc/leads/x@y.z', []],
        ['POST', '/campaigns/cam_abc123/pause', []], ['PATCH', '/calls/act_abc123', []], ['POST', '/tasks', []], ['POST', '/inbox/sms', []], ['PUT', '/tasks', []],
        ['GET', '/team/credits', null], ['GET', '/campaigns/../hooks', null], ['DELETE', '/activities/act_abc123/recording-transcript', []],
    ];
    foreach ($refused as [$m, $p, $b]) throws(fn () => LemlistGuard::assert($m, $p, $b), ForbiddenWriteError::class);
    [$c, , $mock] = t_lem();
    throws(fn () => $c->write('DELETE', '/leads/lea_abc123', []), ForbiddenWriteError::class);
    throws(fn () => $c->write('POST', '/hooks', ['targetUrl' => 'https://x']), ForbiddenWriteError::class, 'bin/lemlist-webhook.php');
    eq([], $mock->calls, 'refused before any request');
});

test('lemlist client: Basic auth with an empty user, waits on Retry-After for 429, long waits and 402 are reported; the log has no key or body', function () {
    [$c, , $mock] = t_lem();
    $seen = [];
    $c->transport = function ($m, $u, $h, $b) use ($mock, &$seen) { $seen[] = $h; return $mock($m, $u, $h, $b); };
    $c->get('/team', ['version' => 'v2']);
    ok(in_array('Authorization: Basic ' . base64_encode(':mock-key'), $seen[0], true), 'Basic base64(":" + key)');
    $c->transport = $mock;
    $mock->queue = [['status' => 429, 'headers' => ['Retry-After' => '2'], 'body' => '{}']];
    eq('SwooScale', $c->get('/team')['name']);
    eq([2000], $GLOBALS['__lemSleeps']);
    $mock->queue = [['status' => 429, 'headers' => ['Retry-After' => '60'], 'body' => '{}']];
    eq(60, throws(fn () => $c->get('/team'), LemlistRateLimited::class)->retryAfter);
    $mock->callsApi = false;
    eq('LEMLIST_PLAN', throws(fn () => $c->get('/call-statuses'), AppError::class, 'Calls API needs Email Pro+')->errCode);
    $mock->queue = [['status' => 409, 'headers' => [], 'body' => '{"success":false,"error":{"code":"CALLS_API_TASK_ALREADY_CLOSED","message":"taskId is already done"}}']];
    eq('CALLS_API_TASK_ALREADY_CLOSED', throws(fn () => $c->write('POST', '/calls', ['externalId' => 'sd-1', 'note' => 'secret note text']), AppError::class)->errCode);
    $log = json_encode($GLOBALS['__events']);
    ok(!str_contains($log, 'mock-key') && !str_contains($log, 'secret note text'), 'no key and no body in the audit log');
    ok(str_contains($log, '"endpoint":"POST \/calls"'));
});

test('lemlist webhook: POST only, secret compared in constant time, events stored once (ids and types only), answered fast', function () {
    $cfg = t_lem_config();
    [, $store] = t_lem();
    $body = ['_id' => 'act_hook0001', 'type' => 'emailsReplied', 'secret' => T_LEM_SECRET, 'leadId' => 'lea_it02', 'campaignId' => 'cam_it000001', 'contactId' => 'ctc_it02',
        'stepId' => 'stp_it_0', 'sequenceStep' => 0, 'createdAt' => '2026-11-13T09:00:00Z', 'leadEmail' => 'robin.replier@mail.example', 'email' => 'robin.replier@mail.example',
        'phone' => '+32 470 99 88 77', 'firstName' => 'Robin', 'subject' => 'Re: secret subject'];
    eq([405, ['error' => 'POST only']], LemlistWebhook::handle($cfg, 'GET', '', $store));
    eq(401, LemlistWebhook::handle($cfg, 'POST', json_encode(['secret' => 'wrong'] + $body), $store)[0]);
    eq(401, LemlistWebhook::handle($cfg, 'POST', json_encode(array_diff_key($body, ['secret' => 1])), $store)[0], 'missing secret');
    eq(401, LemlistWebhook::handle($cfg, 'POST', 'not json', $store)[0]);
    eq(503, LemlistWebhook::handle(['LEMLIST_WEBHOOK_SECRET' => ''] + $cfg, 'POST', json_encode($body), $store)[0], 'no secret configured: refuse everything');
    eq([200, ['ok' => true, 'duplicate' => false]], LemlistWebhook::handle($cfg, 'POST', json_encode($body), $store, fn () => 1_794_500_000));
    eq(1_794_500_000, $store->meta('last_event_at'));
    eq([200, ['ok' => true, 'duplicate' => true]], LemlistWebhook::handle($cfg, 'POST', json_encode($body), $store), 'redelivery is ignored');
    eq(1, (int) $store->one('SELECT COUNT(*) FROM events'));
    eq(['emailsReplied', 'lea_it02'], array_values($store->all('SELECT type, lead_id FROM activities WHERE id = ?', ['act_hook0001'])[0]), 'campaign events also feed the lead logic at once');
    LemlistWebhook::handle($cfg, 'POST', json_encode(['type' => 'sendLimitReached', 'secret' => T_LEM_SECRET]), $store);
    eq('sendLimitReached', $store->meta('alerts')[0]['type'], 'lemlist alerts are kept for "Needs action"');
    $dump = json_encode($store->all('SELECT * FROM events')) . json_encode($store->all('SELECT * FROM activities')) . json_encode($store->all('SELECT * FROM meta'));
    foreach (['mail.example', '+32 470', 'Robin', 'secret subject', T_LEM_SECRET] as $pii) ok(!str_contains($dump, $pii), "stored: $pii");
    ok(!str_contains(json_encode($GLOBALS['__events']), 'mail.example') && !str_contains(json_encode($GLOBALS['__events']), T_LEM_SECRET), 'audit log');
    eq(404, LemlistWebhook::handle(['LEMLIST_BACKEND' => 'off', 'LEMLIST_API_KEY' => ''] + $cfg, 'POST', json_encode($body), $store)[0]);
});

test('lemlist sync: team, call statuses, running campaigns only, steps incl. condition branches, leads (with email/phone), stats, activities (paged, deduped), open tasks', function () {
    [$store, $mock] = t_lem_synced();
    eq(['cam_hr000001', 'cam_it000001'], array_column($store->all('SELECT id FROM campaigns ORDER BY id'), 'id'), 'the paused campaign is skipped');
    eq(6, (int) $store->one("SELECT total_steps FROM campaigns WHERE id = 'cam_it000001'"));
    eq(8, (int) $store->one("SELECT COUNT(*) FROM steps WHERE campaign_id = 'cam_it000001'"), '6 main steps + 2 branch steps');
    eq('Accepted / Else', $store->one("SELECT label FROM steps WHERE step_id = 'stp_it_2'"));
    eq(10, (int) $store->one('SELECT COUNT(*) FROM leads'));
    $l = $store->all("SELECT * FROM leads WHERE lead_id = 'lea_it01'")[0];
    eq(['in/example-ciso', 'ciso.example@mail.example', 'Example MSSP'], [$l['linkedin_key'], $l['email'], $l['company']]);
    eq(count($mock->activities), (int) $store->one('SELECT COUNT(*) FROM activities'));
    ok(count(array_filter($mock->calls, fn ($c) => $c[1] === '/activities')) >= 3, 'activities read in pages of 100');
    eq(['opp_call0001', 'opp_lis00001', 'opp_man00001'], array_column($store->all("SELECT id FROM tasks WHERE status IN ('due','upcoming','paused') ORDER BY id"), 'id'), 'open tasks only');
    eq('+32 470 00 0' . substr(md5('lea_it01'), 0, 3), $store->one("SELECT phone FROM leads WHERE lead_id = 'lea_it01'"), 'the lead phone from the export is kept');
    eq(9, (int) $store->one('SELECT COUNT(*) FROM call_statuses'));
    eq(['yes', 'Email Pro'], [$store->meta('calls_api'), $store->meta('plan')]);
    eq(2, json_decode((string) $store->one("SELECT data FROM stats WHERE campaign_id = 'cam_it000001'"), true)['replied']);
    $before = count($mock->calls);
    $s = new LemlistSync(...[t_lem($mock)[0], $store]);
    $s->now = fn () => 1_794_500_100;
    $s->run(600);
    eq(count($mock->activities), (int) $store->one('SELECT COUNT(*) FROM activities'), 'a second run re-reads the overlap without duplicates');
    ok(count($mock->calls) > $before);
    unset($mock->tasks[2]); // the LinkedIn task was done in lemlist
    $mock->tasks = array_values($mock->tasks);
    $s->now = fn () => 1_794_500_200;
    $s->run(600);
    eq('closed', $store->one("SELECT status FROM tasks WHERE id = 'opp_lis00001'"), 'tasks no longer open in lemlist are closed here');
});

test('lemlist sync is resumable and paced: a short budget carries on next run with the same result; a 429 pauses', function () {
    [$full] = t_lem_synced();
    $mock = new MockLemlist();
    [$c, $store] = t_lem($mock);
    $clock = 1_794_500_000;
    $c->transport = function ($m, $u, $h, $b) use ($mock, &$clock) { $clock += 1; return $mock($m, $u, $h, $b); };
    $s = new LemlistSync($c, $store);
    $s->now = function () use (&$clock) { return $clock; };
    $runs = 0;
    do { $r = $s->run(2); $runs++; } while ($r['result'] !== 'done' && $runs < 100);
    ok($runs > 3, "several runs ($runs)");
    foreach (['campaigns', 'steps', 'leads', 'activities', 'tasks', 'call_statuses'] as $t) eq((int) $full->one("SELECT COUNT(*) FROM $t"), (int) $store->one("SELECT COUNT(*) FROM $t"), $t);
    [$c2, $store2, $mock2] = t_lem();
    $mock2->queue = [['status' => 429, 'headers' => ['Retry-After' => '30'], 'body' => '{}']];
    eq('paused', (new LemlistSync($c2, $store2))->run(60)['result']);
    eq('done', (new LemlistSync($c2, $store2))->run(600)['result'], 'and resumes');
    $bad = new MockLemlist();
    $bad->callsApi = false;
    [$store3] = t_lem_synced($bad);
    eq(['no', 0], [$store3->meta('calls_api'), (int) $store3->one('SELECT COUNT(*) FROM call_statuses')], 'calls API 402: noted, the rest still syncs');
    eq(10, (int) $store3->one('SELECT COUNT(*) FROM leads'));
});

test('Linking: lemlist leads matched to "Decision Makers" (IT) and "HR Decision Makers" (HR) by LinkedIn profile, with the signal and its Atlas count', function () {
    eq('in/example-ciso', LinkedIn::profileKey('https://be.linkedin.com/in/Example-CISO/?trk=x'));
    eq('in/example-ciso', LinkedIn::profileKey('linkedin.com/in/example-ciso'));
    eq('in/josé', LinkedIn::profileKey('https://www.linkedin.com/in/jos%C3%A9'));
    eq(null, LinkedIn::profileKey('https://www.linkedin.com/company/acme'));
    [$store] = t_lem_synced();
    $dm = fn (int $row, string $company, string $title, string $link, string $group, string $sub) => ['row' => $row, 'cells' => [46330, 'JP', 46320, $company, 'x.be', $title, $group, $sub, 'End user', 'Brussels', "https://jobs.test/$row", $link, 'Name', 'Title', 'Ready to push', 5, '']];
    $tabs = [
        Sheet::DECISION_MAKERS => [['row' => 1, 'cells' => Sheet::DM_TAB_HEADERS], $dm(2, 'Example MSSP', 'Security Architect', "https://www.linkedin.com/in/example-ciso\nhttps://www.linkedin.com/in/someone-else", 'A - Security', 'A2 - Security Architecture')],
        Sheet::HR_DECISION_MAKERS => [['row' => 1, 'cells' => Sheet::DM_TAB_HEADERS], $dm(2, 'Sample MSSP BV', 'Head of IT Infrastructure', 'linkedin.com/in/Example-HR-Director/', 'E - Infrastructure', '')],
    ];
    $atlasCalls = [];
    $r = LemlistLinker::run($store, fn ($tab) => $tabs[$tab] ?? [], function ($t, $s, $g) use (&$atlasCalls) { $atlasCalls[] = $t; return ['m' => 2573, 'n' => 42]; });
    eq(['linked' => 2, 'it' => 1, 'hr' => 1], $r);
    $it = $store->all("SELECT * FROM leads WHERE lead_id = 'lea_it01'")[0];
    eq(['IT', 'Security Architect', 'https://jobs.test/2', 'A - Security', 2573, 42, '2026-11-04'], [$it['signal_track'], $it['signal_job_title'], $it['signal_job_url'], $it['signal_role_group'], (int) $it['atlas_pool'], (int) $it['atlas_matching'], $it['signal_approved_at']]);
    eq(['HR', 'Sample MSSP BV'], array_values($store->all("SELECT signal_track, signal_company FROM leads WHERE lead_id = 'lea_hr01'")[0]));
    eq(8, (int) $store->one('SELECT COUNT(*) FROM leads WHERE signal_track IS NULL'), 'unmatched leads stay unlinked');
    eq(['Security Architect', 'Head of IT Infrastructure'], $atlasCalls, 'one Atlas lookup per distinct job');
    eq(3, $store->meta('approved_dms'), 'approved people (one DM row holds two links)');
    $r2 = LemlistLinker::run($store, fn ($tab) => [], null);
    eq(0, $r2['linked'], 'a removed approval unlinks the lead');
});

test('Lead logic on fixture sequences: "Step N / total · type" incl. condition and call steps; status buckets; four separate channels', function () {
    [$store] = t_lem_synced();
    $want = [
        'lea_it01' => ['Step 4 / 6 · Call', 'Connected', ['email' => 'sent', 'invite' => 'accepted', 'message' => 'sent', 'call' => 'called']],
        'lea_it02' => ['Step 1 / 6 · Email', 'Replied', ['email' => 'replied', 'invite' => 'not sent', 'message' => 'not sent', 'call' => 'not called']],
        'lea_it03' => ['Step 1 / 6 · Email', 'Bounced', ['email' => 'bounced', 'invite' => 'not sent', 'message' => 'not sent', 'call' => 'not called']],
        'lea_it04' => ['Not started · 6 steps', 'Not started', ['email' => 'not sent', 'invite' => 'not sent', 'message' => 'not sent', 'call' => 'not called']],
        'lea_it05' => ['Step 1 / 6 · Email', 'Meeting', ['email' => 'replied', 'invite' => 'not sent', 'message' => 'not sent', 'call' => 'not called']],
        'lea_it06' => ['Step 1 / 6 · Email', 'Unsubscribed', ['email' => 'sent', 'invite' => 'not sent', 'message' => 'not sent', 'call' => 'not called']],
        'lea_it07' => ['Step 1 / 6 · Email', 'Paused', ['email' => 'sent', 'invite' => 'not sent', 'message' => 'not sent', 'call' => 'not called']],
        'lea_hr01' => ['Step 2 / 4 · LinkedIn invite', 'In sequence', ['email' => 'sent', 'invite' => 'pending', 'message' => 'not sent', 'call' => 'not called']],
        'lea_hr02' => ['Step 2 / 4 · LinkedIn invite', 'Connected', ['email' => 'sent', 'invite' => 'accepted', 'message' => 'not sent', 'call' => 'not called']],
        'lea_hr03' => ['Step 4 / 4 · Call', 'Finished – no reply', ['email' => 'sent', 'invite' => 'pending', 'message' => 'sent', 'call' => 'called']],
    ];
    foreach ($want as $lead => [$level, $status, $channels]) {
        $v = t_lead_view($store, $lead);
        eq([$level, $status, $channels], [$v['level'], $v['status'], $v['channels']], $lead);
    }
    // a lead on a condition branch: position on its own path ("Step 3 / 6 · LinkedIn message")
    $steps = $store->all("SELECT * FROM steps WHERE campaign_id = 'cam_it000001'");
    $acts = array_filter($store->all("SELECT * FROM activities WHERE lead_id = 'lea_it01'"), fn ($a) => $a['type'] !== 'aircallEnded');
    eq('Step 3 / 6 · LinkedIn message', LeadLogic::level($acts, $steps)['label']);
    // calls logged here, by call status
    $st = ['connected-positive' => ['answered' => 1, 'positive' => 1, 'negative' => 0], 'no-answer' => ['answered' => 0, 'positive' => 0, 'negative' => 0], 'voicemail' => ['answered' => 1, 'positive' => 0, 'negative' => 0]];
    eq('no answer', LeadLogic::channels([], [['call_status' => 'no-answer']], $st)['call']);
    eq('answered', LeadLogic::channels([], [['call_status' => 'no-answer'], ['call_status' => 'voicemail']], $st)['call']);
    eq('positive', LeadLogic::channels([], [['call_status' => 'voicemail'], ['call_status' => 'connected-positive']], $st)['call']);
    eq('Interested', LeadLogic::status([], [], ['n' => 4, 'total' => 6], LeadLogic::channels([], [['call_status' => 'connected-positive']], $st)));
    eq('In sequence', LeadLogic::status([['type' => 'paused', 'created_at' => '2026-11-01'], ['type' => 'resumed', 'created_at' => '2026-11-02']], [], ['n' => 2, 'total' => 6], LeadLogic::channels([])), 'resumed after a pause');
    eq('New job/deal', LeadLogic::status([], [], ['n' => 1, 'total' => 6], LeadLogic::channels([]), 'Deal'));
});

test('12-month retention: old activities, events, calls and stale leads are pruned; lead emails/phones exist only in the leads table', function () {
    [$store] = t_lem_synced();
    $now = 1_794_500_000;
    $store->addActivity(['id' => 'act_old', 'type' => 'emailsSent', 'lead_id' => 'lea_x', 'campaign_id' => 'c', 'contact_id' => null, 'step_id' => null, 'seq_step' => 0, 'total_seq_step' => 0,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 400 * 86400), 'send_user_id' => null, 'source' => 'sync']);
    $store->addEvent(['id' => 'act_oldev', 'type' => 'x', 'created_at' => '', 'received_at' => $now - 400 * 86400, 'lead_id' => null, 'campaign_id' => null, 'contact_id' => null]);
    $store->upsertLead(['lead_id' => 'lea_stale', 'campaign_id' => 'c', 'email' => 'stale@mail.example', 'updated_at' => $now - 400 * 86400]);
    $r = $store->prune($now);
    eq([1, 1, 1], [$r['activities'], $r['events'], $r['leads']]);
    eq(10, (int) $store->one('SELECT COUNT(*) FROM leads'));
    foreach (['activities', 'events', 'tasks', 'meta', 'steps', 'stats', 'campaigns'] as $t) {
        $dump = json_encode($store->all("SELECT * FROM $t"));
        ok(!str_contains($dump, '@mail.example') && !str_contains($dump, '+32 470 00'), "lead emails/phones found in $t");
    }
});

test('Who may send: LEMLIST_SENDER_ALLOWED users act as the shared sender (Jonathan); LEMLIST_USER_MAP overrides; others view only', function () {
    $team = [['userId' => MockLemlist::USER, 'name' => 'Jonathan Perkins', 'email' => 'jonathan@swooscale.com', 'role' => 'admin']];
    $cfg = ['LEMLIST_SHARED_SENDER' => MockLemlist::USER, 'LEMLIST_SENDER_ALLOWED' => 'jonathan@swooscale.com, Haseeb@Swooscale.com', 'LEMLIST_USER_MAP' => ''];
    $s = LemlistSender::for($cfg, 'haseeb@swooscale.com', $team);
    eq(['userId' => MockLemlist::USER, 'name' => 'Jonathan Perkins', 'email' => 'jonathan@swooscale.com', 'mailboxId' => null, 'shared' => true], $s);
    eq('Sending as Jonathan Perkins (jonathan@swooscale.com)', LemlistSender::label($s));
    eq(null, LemlistSender::for($cfg, 'someone@swooscale.com', $team), 'not in the list: view only');
    eq(null, LemlistSender::for(['LEMLIST_SHARED_SENDER' => ''] + $cfg, 'haseeb@swooscale.com', $team), 'no shared sender: nobody sends');
    $map = ['LEMLIST_USER_MAP' => json_encode(['sdr@swooscale.com' => ['userId' => 'usr_sdr0000001', 'email' => 'sdr@swooscale.com', 'mailboxId' => 'usm_1']])] + $cfg;
    eq(['usr_sdr0000001', 'usm_1', false], array_values(array_intersect_key(LemlistSender::for($map, 'SDR@swooscale.com', $team), ['userId' => 1, 'shared' => 1, 'mailboxId' => 1])));
});

test('lemlist config: mock refused in production; secret length, user ids, map JSON, allowed emails and attribution validated', function () {
    eq('first_positive', t_lem_config()['LEMLIST_ATTRIBUTION']);
    throws(fn () => t_lem_config(['APP_ENV' => 'production', 'WORKBOOK_BACKEND' => 'graph']), ConfigError::class, 'LEMLIST_BACKEND=mock');
    throws(fn () => t_lem_config(['LEMLIST_WEBHOOK_SECRET' => 'short']), ConfigError::class, 'at least 24');
    throws(fn () => t_lem_config(['LEMLIST_SHARED_SENDER' => 'jonathan']), ConfigError::class, 'usr_');
    throws(fn () => t_lem_config(['LEMLIST_USER_MAP' => '["x"]']), ConfigError::class, 'JSON object');
    throws(fn () => t_lem_config(['LEMLIST_USER_MAP' => '{"a@b.c": {"userId": "x"}}']), ConfigError::class, 'userId');
    throws(fn () => t_lem_config(['LEMLIST_SENDER_ALLOWED' => 'jonathan']), ConfigError::class, 'not an email');
    throws(fn () => t_lem_config(['LEMLIST_ATTRIBUTION' => 'last']), ConfigError::class, 'first_positive');
});

test('lemlist health: reachable, plan + calls API, webhook registered / disabled, last event (24 h), last sync, data synced, users mapped', function () {
    $cfg = t_lem_config(['LEMLIST_SHARED_SENDER' => MockLemlist::USER, 'LEMLIST_SENDER_ALLOWED' => 'jonathan@swooscale.com,haseeb@swooscale.com']);
    [$store, $mock, , $c] = t_lem_synced();
    $now = 1_794_500_000;
    $rows = array_column(Health::lemlistChecks($cfg, $c, $store, $now + 60), null, 0);
    eq('OK', $rows['lemlist API reachable'][1]);
    eq(['OK', 'plan Email Pro, calls API available'], [$rows['lemlist plan / calls API'][1], $rows['lemlist plan / calls API'][2]]);
    eq('WARN', $rows['lemlist webhook'][1], 'not registered yet');
    eq(['WARN', 'none received yet'], [$rows['lemlist last webhook event'][1], $rows['lemlist last webhook event'][2]]);
    eq('OK', $rows['lemlist last sync'][1]);
    ok(str_contains($rows['lemlist data synced'][2], '2 running campaign(s), 10 leads'), $rows['lemlist data synced'][2]);
    eq('OK', $rows['lemlist users mapped'][1]);
    ok(str_contains($rows['lemlist users mapped'][2], 'haseeb@swooscale.com → Jonathan Perkins; everyone else: view only'));
    $c->setup = true;
    $h = $c->write('POST', '/hooks', ['targetUrl' => 'https://signals.example/hooks/lemlist', 'secret' => T_LEM_SECRET]);
    $c->setup = false;
    $store->setMeta('last_event_at', $now - 90000);
    $rows = array_column(Health::lemlistChecks($cfg, $c, $store, $now), null, 0);
    eq('OK', $rows['lemlist webhook'][1]);
    eq('WARN', $rows['lemlist last webhook event'][1]);
    ok(str_contains($rows['lemlist last webhook event'][2], 'nothing in 24 h'));
    $mock->hooks[$h['_id']]['disabled'] = true;
    $mock->hooks[$h['_id']]['disabledReason'] = 'deliveryError';
    $mock->hooks[$h['_id']]['lastErrorStatus'] = 404;
    eq('FAIL', array_column(Health::lemlistChecks($cfg, $c, $store, $now), null, 0)['lemlist webhook'][1], 'a disabled webhook is a FAIL');
    $mock->callsApi = false;
    ok(str_contains(array_column(Health::lemlistChecks($cfg, $c, $store, $now), null, 0)['lemlist plan / calls API'][2], 'Calls API needs Email Pro+'));
    ok(!str_contains(json_encode(Health::lemlistChecks($cfg, $c, $store, $now)), T_LEM_SECRET), 'the secret is never shown');
    eq('WARN', Health::lemlistChecks(['LEMLIST_BACKEND' => '', 'LEMLIST_API_KEY' => ''] + $cfg)[0][1], 'off without a key');
});
