<?php
// lemlist Phase 3 (Calls tab / SDR desk): status mapping, call queue, idempotent call logging (phone task, manual call task,
// call-back), 409s, manual tasks (send after confirm, done, skip), inbox (labels kept, replies), the LEMLIST_WRITES kill switch,
// view-only users, rate limits, CSRF/Origin on the routes, webhook updates, and the feed into the Outreach numbers.
declare(strict_types=1);

use SignalDesk\App;
use SignalDesk\AppError;
use SignalDesk\Auth;
use SignalDesk\CallDesk;
use SignalDesk\CallDeskLimiter;
use SignalDesk\Config;
use SignalDesk\HookBuffer;
use SignalDesk\LemlistStore;
use SignalDesk\LemlistSync;
use SignalDesk\LemlistWebhook;
use SignalDesk\MockLemlist;
use SignalDesk\Outreach;
use SignalDesk\ValidationError;

const T_CALL_NOW = 1_794_500_000; // 2026-11-12 (mock tasks are due 10-13 Nov)

/** A synced mock store and a CallDesk for $user. */
function t_desk(array $cfg = [], string $user = 'jp@x.test', ?MockLemlist $mock = null): array
{
    [$store, $mock, $dir, $client] = t_lem_synced($mock);
    $config = $cfg + ['LEMLIST_SHARED_SENDER' => MockLemlist::USER, 'LEMLIST_SENDER_ALLOWED' => 'jp@x.test,haseeb@x.test', 'LEMLIST_WRITES' => 'on', 'LEMLIST_USER_MAP' => ''];
    $limiter = new CallDeskLimiter("$dir/limits.json");
    $limiter->now = fn () => T_CALL_NOW;
    $d = new CallDesk($store, $client, $config, $user, $limiter);
    $d->now = fn () => T_CALL_NOW;
    $GLOBALS['__deskSleeps'] = [];
    $d->sleep = function (int $ms) { $GLOBALS['__deskSleeps'][] = $ms; };
    return [$d, $store, $mock, $dir, $client];
}

/** Write requests the mock received since $from (method + path). */
function t_lem_writes(MockLemlist $mock, int $from = 0): array
{
    return array_values(array_map(fn ($c) => [$c[0], $c[1], $c[3]], array_filter(array_slice($mock->calls, $from), fn ($c) => $c[0] !== 'GET')));
}

function t_attempt(string $seed = 'a'): string
{
    return substr(hash('sha256', $seed), 0, 16);
}

test('calls: live call statuses split by "did they answer?" (Connected positive = positive + pauses, Connected negative = negative)', function () {
    $rows = CallDesk::statusRows([
        ['key' => 'connected-positive', 'label' => 'Connected positive', 'callAnswered' => true, 'callPositive' => true, 'callNegative' => false, 'pausesCampaigns' => true],
        ['key' => 'connected-negative', 'label' => 'Connected negative', 'callAnswered' => true, 'callPositive' => false, 'callNegative' => true, 'pausesCampaigns' => false],
        ['key' => 'no-answer', 'label' => 'No answer', 'callAnswered' => false],
    ]);
    eq(['key' => 'connected-positive', 'label' => 'Connected positive', 'answered' => 1, 'positive' => 1, 'negative' => 0, 'pauses' => 1], $rows[0]);
    eq(['answered' => 1, 'positive' => 0, 'negative' => 1, 'pauses' => 0], array_intersect_key($rows[1], ['answered' => 1, 'positive' => 1, 'negative' => 1, 'pauses' => 1]));
    [$d, $store, $mock] = t_desk();
    $s = $d->statuses(true);
    eq(true, $s['callsApi']);
    eq(['connected-positive', 'connected-neutral', 'connected-negative', 'voicemail', 'gatekeeper', 'busy'], array_column($s['answered'], 'key'), 'step 2 after "Answered"');
    eq(['no-answer', 'wrong-number', 'not-in-service'], array_column($s['notAnswered'], 'key'), 'step 2 after "Not answered"');
    eq([true, false], [$s['answered'][0]['positive'], $s['answered'][0]['negative']]);
    eq(true, $s['answered'][0]['pauses']);
    eq(true, $s['answered'][2]['negative']);
    ok(in_array(['GET', '/call-statuses'], array_map(fn ($c) => [$c[0], $c[1]], $mock->calls), true), 'read live from lemlist');
});

test('calls: queue = phone tasks + manual tasks titled Call / Calling again / Final call, overdue first; manual list with types and an approximate preview', function () {
    [$d] = t_desk();
    $v = $d->view();
    eq(['opp_call0002', 'opp_fin00001', 'opp_call0001', 'opp_man00001', 'opp_vml00001', 'opp_call0003'], array_column($v['queue'], 'id'), 'overdue first, then by due date');
    eq(['phone', 'manualCall', 'phone', 'manualCall', 'manualCall', 'phone'], array_column($v['queue'], 'kind'), 'Voice mail tasks are call tasks too');
    eq([true, true, true, false, false, false], array_column($v['queue'], 'overdue'));
    $q = $v['queue'][2];
    eq('Ciso Example', $q['name']);
    eq('IT decision makers – Belgium', $q['campaign']);
    ok(str_starts_with((string) $q['context']['tel'], 'tel:+32'), 'tel: link');
    eq(['LinkedIn message sent', 'LinkedIn invite accepted', 'LinkedIn invite sent'], array_column($q['context']['events'], 'label'), 'last email/LinkedIn events');
    eq(4, $v['counts']['calls'], 'due call tasks');
    eq(4, $v['counts']['manual'], 'due manual tasks (incl. the 2 call-type ones)');
    eq(6, $v['counts']['badge'], 'top-bar badge counts each open task once');
    $types = array_column($v['manual'], 'manualType', 'id');
    eq(['opp_fin00001' => 'call', 'opp_ooo00001' => 'ooo', 'opp_eml00001' => 'email', 'opp_man00001' => 'call', 'opp_lis00001' => 'li', 'opp_vml00001' => 'call', 'opp_vis00001' => 'visit'], $types);
    $byId = array_column($v['manual'], null, 'id');
    eq('Hi Cas, happy to help with your IT hiring.', $byId['opp_lis00001']['preview']['text'], 'LinkedIn step of the HR campaign, {{firstName}} filled in');
    eq('Re: Your CISO search', $byId['opp_eml00001']['preview']['subject'], 'email step subject with {{jobTitle}} filled in');
    eq('Last note from me, Ciso.', $byId['opp_eml00001']['preview']['text']);
    ok(!isset($byId['opp_man00001']['preview']), 'no preview for call tasks');
    eq('on', $v['writes']);
    eq('Sending as Jonathan Perkins (jonathan@swooscale.com)', $v['sender']['label']);
    eq(false, $v['viewOnly']);
});

test('calls: phone task = POST /calls {taskId} (lemlist completes it); the same attempt is never logged twice', function () {
    [$d, $store, $mock] = t_desk();
    $d->view();
    $from = count($mock->calls);
    $r = $d->logCall(['taskId' => 'opp_call0001', 'attempt' => t_attempt(), 'answered' => true, 'status' => 'connected-neutral', 'note' => 'Hiring 2 SOC analysts', 'callbackDate' => '']);
    eq(true, $r['ok']);
    $w = t_lem_writes($mock, $from);
    eq(1, count($w), 'one write: the call (lemlist completes the phone task itself)');
    eq(['POST', '/calls'], [$w[0][0], $w[0][1]]);
    eq('opp_call0001', $w[0][2]['taskId']);
    ok(!isset($w[0][2]['leadId']));
    eq(MockLemlist::USER, $w[0][2]['userId'], 'logged as the shared sender (Jonathan)');
    eq('sd-call0001-' . t_attempt(), $w[0][2]['externalId']);
    eq('done', $mock->tasks[0]['status']);
    eq('closed', $store->one("SELECT status FROM tasks WHERE id = 'opp_call0001'"));
    eq(['done' => 1, 'answered' => 1, 'positive' => 0], $d->today());
    eq('jp@x.test', $store->one("SELECT by_user FROM calls WHERE task_id = 'opp_call0001'"), 'the real Signal Desk user is recorded');
    // a retry after a lost answer: lemlist already stored the call under this externalId -> 200 replay, nothing new
    $ext = 'sd-man00001-' . t_attempt('lost');
    $mock->callLog[$ext] = ['_id' => 'act_stored1', 'externalId' => $ext];
    $r2 = $d->logCall(['taskId' => 'opp_man00001', 'attempt' => t_attempt('lost'), 'answered' => false, 'status' => 'no-answer']);
    eq(true, $r2['ok']);
    eq(2, count($mock->callLog), 'replayed, not logged twice');
    eq('act_stored1', $store->one('SELECT lemlist_id FROM calls WHERE external_id = ?', [$ext]));
});

test('calls: manual call task = POST /calls {leadId} then PATCH /tasks {id, done:true}; a failed task update is resumed without a second call', function () {
    [$d, $store, $mock] = t_desk();
    $d->view();
    $from = count($mock->calls);
    $mock->queue = [];
    // make the PATCH fail once: the call is logged, the task update errors
    $orig = $mock;
    $fail = true;
    $client = (fn () => $this->client)->call($d);
    $client->transport = function ($m, $u, $h, $b) use ($orig, &$fail) {
        if ($m === 'PATCH' && $fail) { $fail = false; $orig->calls[] = [$m, '/tasks', [], json_decode((string) $b, true)]; return ['status' => 500, 'headers' => [], 'body' => '{"error":"boom"}']; }
        return $orig($m, $u, $h, $b);
    };
    $in = ['taskId' => 'opp_man00001', 'attempt' => t_attempt('m'), 'answered' => true, 'status' => 'voicemail', 'note' => ''];
    $e = throws(fn () => $d->logCall($in), AppError::class, 'the call will not be logged twice');
    ok(str_contains($e->getMessage(), 'The call is logged in lemlist'));
    eq('due', $store->one("SELECT status FROM tasks WHERE id = 'opp_man00001'"), 'still open until the task update succeeds');
    $r = $d->logCall($in); // Save again
    eq(true, $r['ok']);
    $w = t_lem_writes($mock, $from);
    eq([['POST', '/calls'], ['PATCH', '/tasks'], ['PATCH', '/tasks']], array_map(fn ($x) => [$x[0], $x[1]], $w), 'Save again: the call is known here, only the task update is repeated');
    eq('lea_it02', $w[0][2]['leadId']);
    ok(!isset($w[0][2]['taskId']), 'a manual task is not a call task for lemlist');
    eq(['id' => 'opp_man00001', 'done' => true], $w[2][2]);
    eq(1, count(array_filter($mock->callLog, fn ($c) => str_contains($c['externalId'], 'man00001'))), 'one call in lemlist');
    eq('done', $mock->tasks[1]['status']);
    eq('closed', $store->one("SELECT status FROM tasks WHERE id = 'opp_man00001'"));
});

test('calls: call-back = POST /calls {leadId} without taskId + PATCH /tasks {id, dueDate}; the task stays open', function () {
    [$d, $store, $mock] = t_desk();
    $d->view();
    $from = count($mock->calls);
    $r = $d->logCall(['taskId' => 'opp_call0001', 'attempt' => t_attempt('cb'), 'answered' => true, 'status' => 'busy', 'callbackDate' => '2026-11-16']);
    eq(true, $r['ok']);
    ok(str_contains($r['message'], '2026-11-16'));
    $w = t_lem_writes($mock, $from);
    eq(['POST', '/calls'], [$w[0][0], $w[0][1]]);
    ok(!isset($w[0][2]['taskId']) && $w[0][2]['leadId'] === 'lea_it01', 'logged on the lead, task not completed');
    eq(['PATCH', '/tasks', ['id' => 'opp_call0001', 'dueDate' => '2026-11-16T08:00:00.000Z']], $w[1], '09:00 Brussels');
    eq('upcoming', $mock->tasks[0]['status']);
    eq('upcoming', $store->one("SELECT status FROM tasks WHERE id = 'opp_call0001'"));
    foreach (['2026-11-12', '2026-11-11', '2028-01-01', 'next monday'] as $bad) {
        throws(fn () => $d->logCall(['taskId' => 'opp_call0002', 'attempt' => t_attempt($bad), 'answered' => true, 'status' => 'busy', 'callbackDate' => $bad]), ValidationError::class, 'call-back date');
    }
});

test('calls: 409 TASK_ALREADY_CLOSED refreshes the queue; 409 LOG_IN_PROGRESS is retried once, then answered with "Save again"', function () {
    [$d, $store, $mock] = t_desk();
    $d->view();
    $mock->tasks[0]['status'] = 'done'; // someone completed it in lemlist meanwhile
    $r = $d->logCall(['taskId' => 'opp_call0001', 'attempt' => t_attempt('x'), 'answered' => false, 'status' => 'no-answer']);
    eq(false, $r['ok']);
    eq(true, $r['refreshed']);
    ok(str_contains($r['message'], 'already completed'));
    eq('closed', $store->one("SELECT status FROM tasks WHERE id = 'opp_call0001'"));
    $busy = ['status' => 409, 'headers' => [], 'body' => '{"success":false,"error":{"code":"CALLS_API_LOG_IN_PROGRESS","message":"in progress"}}'];
    $mock->queue = [$busy];
    $r = $d->logCall(['taskId' => 'opp_call0002', 'attempt' => t_attempt('y'), 'answered' => false, 'status' => 'no-answer']);
    eq(true, $r['ok'], 'one retry after a second');
    eq([1000], $GLOBALS['__deskSleeps']);
    $mock->queue = [$busy, $busy];
    $r = $d->logCall(['taskId' => 'opp_call0003', 'attempt' => t_attempt('z'), 'answered' => false, 'status' => 'no-answer']);
    eq(false, $r['ok']);
    eq(true, $r['retry']);
    ok(str_contains($r['message'], 'will not be logged twice'));
    throws(fn () => $d->logCall(['taskId' => 'opp_call0003', 'attempt' => t_attempt('w'), 'answered' => true, 'status' => 'no-answer']), ValidationError::class, 'does not match');
    throws(fn () => $d->logCall(['taskId' => 'opp_call0003', 'attempt' => t_attempt('v'), 'answered' => true, 'status' => 'made-up']), ValidationError::class, 'Pick an outcome');
    throws(fn () => $d->logCall(['taskId' => 'opp_lis00001', 'attempt' => t_attempt('u'), 'answered' => true, 'status' => 'busy']), ValidationError::class, 'not a call task');
});

test('calls: manual tasks: Send needs the confirm step (lemlist then sends the step), Done for other tasks, Skip = POST /tasks/ignore', function () {
    [$d, $store, $mock] = t_desk();
    $d->view();
    throws(fn () => $d->taskAction(['taskId' => 'opp_eml00001', 'action' => 'send']), ValidationError::class, 'Confirm before sending');
    throws(fn () => $d->taskAction(['taskId' => 'opp_eml00001', 'action' => 'done']), ValidationError::class, 'Send');
    throws(fn () => $d->taskAction(['taskId' => 'opp_man00001', 'action' => 'done']), ValidationError::class, 'Log the call');
    $from = count($mock->calls);
    eq(true, $d->taskAction(['taskId' => 'opp_eml00001', 'action' => 'send', 'confirm' => true])['ok']);
    eq(true, $d->taskAction(['taskId' => 'opp_ooo00001', 'action' => 'done'])['ok']);
    eq(true, $d->taskAction(['taskId' => 'opp_vis00001', 'action' => 'skip'])['ok']);
    eq([['PATCH', '/tasks', ['id' => 'opp_eml00001', 'done' => true]], ['PATCH', '/tasks', ['id' => 'opp_ooo00001', 'done' => true]], ['POST', '/tasks/ignore', ['ids' => ['opp_vis00001']]]], t_lem_writes($mock, $from));
    eq(0, (int) $store->one("SELECT COUNT(*) FROM tasks WHERE id IN ('opp_eml00001','opp_ooo00001','opp_vis00001') AND status IN ('due','upcoming')"));
    $r = $d->taskAction(['taskId' => 'opp_eml00001', 'action' => 'send', 'confirm' => true]);
    eq(true, $r['refreshed'], 'already closed: nothing sent twice');
});

test('calls: the inbox mirrors lemlist: every conversation (sent-only too), latest activity first, unread, labels; opening marks it read; LinkedIn needs a lead', function () {
    [$d, $store, $mock] = t_desk();
    $i = $d->inbox();
    eq(['ctc_sent0001', 'ctc_hr02', 'ctc_ext01', 'ctc_it05', 'ctc_it02'], array_column($i['conversations'], 'contactId'), 'latest activity first; the sent-only conversation is there, as in lemlist');
    eq([2, 5, false], [$i['unread'], $i['total'], $i['hasMore']]);
    $by = array_column($i['conversations'], null, 'contactId');
    eq(['Interested', ['Interested']], [$by['ctc_it05']['label'], $by['ctc_it05']['labels']]);
    eq([null, ['VIP']], [$by['ctc_it02']['label'], $by['ctc_it02']['labels']], 'other labels are shown, not ours to set');
    eq(['linkedin', 'Link BV'], [$by['ctc_hr02']['channel'], $by['ctc_hr02']['company']]);
    eq([true, 'Hi Ciso, last note from me.'], [$by['ctc_sent0001']['ours'], $by['ctc_sent0001']['snippet']], 'our message last: "You:" in front');
    eq([false, 'Hi Jonathan, timing is good.'], [$by['ctc_it02']['ours'], $by['ctc_it02']['snippet']]);
    eq(['ctc_hr02', 'ctc_it02'], array_column($d->inbox('unread')['conversations'], 'contactId'));
    eq(['ctc_it05'], array_column($d->inbox('Interested')['conversations'], 'contactId'));
    $t = $d->thread('ctc_it02');
    eq([true, false], array_column($t['messages'], 'out'));
    eq("Hi Jonathan, timing is good.\nCan you send two profiles first?", $t['messages'][1]['text']);
    eq(true, $t['markedRead']);
    eq(true, $mock->inbox['ctc_it02']['users'][0]['read']);
    $ext = $d->thread('ctc_ext01');
    eq([false, false], [$ext['canLinkedin'], $ext['inCampaign']], 'a direct contact: no activity has a campaign');
    throws(fn () => $d->reply(['contactId' => 'ctc_ext01', 'channel' => 'linkedin', 'message' => 'hi', 'confirm' => true]), ValidationError::class, 'lead of a campaign');
});

test('inbox campaign: from the campaignId + leadId on the conversation\'s activities (synced or read once from the thread), names of every status; "Not in a campaign" only without any', function () {
    $m = new MockLemlist();
    $m->addDemoContacts();
    [$d, $store, $mock] = t_desk([], 'jp@x.test', $m);
    eq(4, (int) $store->one('SELECT COUNT(*) FROM campaign_names'), 'the sync keeps the names of all campaigns, paused ones too');
    $by = array_column($d->inbox()['conversations'], null, 'contactId');
    eq(['lea_it02', 'Replier BV', 'IT decision makers – Belgium', 'running', true, true], [$by['ctc_it02']['leadId'], $by['ctc_it02']['company'], $by['ctc_it02']['campaign'], $by['ctc_it02']['campaignStatus'], $by['ctc_it02']['inCampaign'], $by['ctc_it02']['canLinkedin']], 'from synced activities');
    eq(['Old test', 'paused', 'lea_old00001', true, 'Kuiper Logistics'], [$by['ctc_bram00001']['campaign'], $by['ctc_bram00001']['campaignStatus'], $by['ctc_bram00001']['leadId'], $by['ctc_bram00001']['canLinkedin'], $by['ctc_bram00001']['company']],
        'a paused campaign and the company, from the thread activities');
    eq(['HR decision makers – Belgium', 'lea_hr01', 'Sample MSSP BV'], [$by['ctc_kimli0001']['campaign'], $by['ctc_kimli0001']['leadId'], $by['ctc_kimli0001']['company']]);
    eq([false, '', null], [$by['ctc_ext01']['inCampaign'], $by['ctc_ext01']['campaign'], $by['ctc_ext01']['leadId']], 'no activity with a campaign: "Not in a campaign"');
    eq('', $store->one("SELECT campaign_id FROM contact_links WHERE contact_id = 'ctc_ext01'"), 'remembered');
    eq(['lea_it28', 'Scope Cooling', 'Thursday MPC 2'], [$by['ctc_scope0001']['leadId'], $by['ctc_scope0001']['company'], $by['ctc_scope0001']['campaign']], 'no campaign on its activities, but a known lead (same email)');
    $reads = count(array_filter($mock->calls, fn ($c) => str_starts_with($c[1], '/inbox/ctc_')));
    $d->inbox();
    eq($reads, count(array_filter($mock->calls, fn ($c) => str_starts_with($c[1], '/inbox/ctc_'))), 'threads are read once to find the campaign, then remembered');
    // LinkedIn reply works whenever the lead is known (also in a paused campaign)
    $from = count($mock->calls);
    eq(true, $d->reply(['contactId' => 'ctc_bram00001', 'channel' => 'linkedin', 'message' => 'Thanks Bram', 'confirm' => true])['ok']);
    eq([['POST', '/inbox/linkedin', ['sendUserId' => MockLemlist::USER, 'leadId' => 'lea_old00001', 'contactId' => 'ctc_bram00001', 'message' => 'Thanks Bram']]], t_lem_writes($mock, $from));
    $store->db->exec("UPDATE leads SET signal_track = 'HR', signal_job_title = 'Talent Acquisition Lead' WHERE lead_id = 'lea_hr01'");
    eq('HR · Talent Acquisition Lead', array_column($d->inbox()['conversations'], null, 'contactId')['ctc_kimli0001']['signal'], 'job signal of the lead');
});

test('inbox row: the last message whoever wrote it ("You:" when ours), plain text, 90 characters; message text never in the audit log', function () {
    $m = new MockLemlist();
    $m->addDemoContacts();
    [$d, $store, $mock] = t_desk([], 'jp@x.test', $m);
    $c = fn () => array_column($d->inbox()['conversations'], null, 'contactId');
    $sam = $c()['ctc_scope0001'];
    eq(['Hi Sam,', true, '2026-11-16T09:18:56.000Z'], [$sam['snippet'], $sam['ours'], $sam['at']], 'our message is the last one');
    $GLOBALS['__events'] = [];
    $d->thread('ctc_scope0001');
    $mock->inbox['ctc_scope0001']['thread'][] = ['_id' => 'act_new', 'type' => 'emailsReplied', 'createdAt' => '2026-11-17T12:00:00.000Z', 'message' => '<p>Great,</p><p>thanks! Tuesday 10:00 works for me.</p>'];
    $mock->inbox['ctc_scope0001']['lastActivityAt'] = '2026-11-17T12:00:00.000Z';
    $sam = $c()['ctc_scope0001'];
    eq(['Great,', false, '2026-11-17T12:00:00.000Z'], [$sam['snippet'], $sam['ours'], $sam['at']], 'their reply is the last one');
    eq('ctc_scope0001', $d->inbox()['conversations'][0]['contactId'], 'and the conversation moves to the top');
    $long = '<p>' . str_repeat('Interesting profile, please send it over. ', 5) . '</p>';
    eq(90, mb_strlen(CallDesk::snippet($long)));
    ok(str_ends_with(CallDesk::snippet($long), '…'));
    $log = json_encode($GLOBALS['__events']);
    ok(!str_contains($log, 'Tuesday 10:00') && !str_contains($log, 'hybrid role'), 'message text never in the audit log');
});

test('inbox labels feed the Dashboard numbers for the conversation\'s lead (set here, or already set in lemlist)', function () {
    $m = new MockLemlist();
    $m->addDemoContacts();
    [$d, $store, $mock] = t_desk([], 'jp@x.test', $m);
    $d->inbox();
    eq('Interested', $store->one("SELECT outcome FROM outcomes WHERE lead_id = 'lea_it05'"), 'Interested label already set in lemlist');
    eq(true, $d->label(['contactId' => 'ctc_scope0001', 'label' => 'Meeting'])['ok']);
    eq('Meeting', $store->one("SELECT outcome FROM outcomes WHERE lead_id = 'lea_it28'"), 'the lead of the conversation gets the outcome');
});

test('calls: replies need the confirm step; email in the latest thread from the sender mailbox, LinkedIn with lead + contact; no message text in the audit log', function () {
    [$d, $store, $mock] = t_desk();
    throws(fn () => $d->reply(['contactId' => 'ctc_it02', 'channel' => 'email', 'message' => 'secret reply text']), ValidationError::class, 'Confirm before sending');
    throws(fn () => $d->reply(['contactId' => 'ctc_it02', 'channel' => 'email', 'message' => 'x', 'confirm' => true, 'attachments' => ['a.pdf']]), ValidationError::class, 'Attachments');
    $from = count($mock->calls);
    eq(true, $d->reply(['contactId' => 'ctc_it02', 'channel' => 'email', 'message' => "secret reply text\nline 2", 'confirm' => true])['ok']);
    eq(true, $d->reply(['contactId' => 'ctc_hr02', 'channel' => 'linkedin', 'message' => 'secret linkedin text', 'confirm' => true])['ok']);
    $w = t_lem_writes($mock, $from);
    eq(['sendUserId' => MockLemlist::USER, 'sendUserEmail' => 'jonathan@swooscale.com', 'sendUserMailboxId' => 'usm_mockmailbox1', 'contactId' => 'ctc_it02',
        'message' => "secret reply text<br>\nline 2", 'replyToActivityId' => 'latest'], $w[0][2]);
    eq(['sendUserId' => MockLemlist::USER, 'leadId' => 'lea_hr02', 'contactId' => 'ctc_hr02', 'message' => 'secret linkedin text'], $w[1][2]);
    eq('usm_mockmailbox1', $store->meta('mailbox_' . MockLemlist::USER), 'mailbox looked up once (GET /users/{id})');
    $log = json_encode($GLOBALS['__events']);
    foreach (['secret reply text', 'secret linkedin text', 'mail.example'] as $pii) ok(!str_contains($log, $pii), "audit has no $pii");
    ok(str_contains($log, '"action":"reply"') && str_contains($log, '"user":"jp@x.test"') && str_contains($log, 'ctc_it02'), 'audit: real user, action, ids');
});

test('calls: labels set only our 4 and keep every other label (appendLabels:false); missing labels created (admin); outcomes feed Outreach', function () {
    [$d, $store, $mock] = t_desk();
    $from = count($mock->calls);
    eq(true, $d->label(['contactId' => 'ctc_it02', 'label' => 'Interested'])['ok']);
    eq(['lbl_vip000001', 'lbl_interest1'], $mock->inbox['ctc_it02']['labels'], 'VIP kept, Interested added');
    $d->label(['contactId' => 'ctc_it02', 'label' => 'Meeting']);
    $meeting = array_column($mock->labels, '_id', 'name')['Meeting'];
    eq(['lbl_vip000001', $meeting], $mock->inbox['ctc_it02']['labels'], 'Interested replaced by Meeting, VIP kept');
    ok(in_array(['POST', '/inbox/labels', ['labelName' => 'Meeting']], t_lem_writes($mock, $from), true), 'missing label created (the sender is a lemlist admin)');
    ok(!array_filter(t_lem_writes($mock, $from), fn ($w) => $w[1] === '/inbox/labels' && in_array($w[2]['labelName'], ['VIP'], true)));
    eq('Meeting', $store->one("SELECT outcome FROM outcomes WHERE lead_id = 'lea_it02'"), 'the Meeting label counts as a meeting in Outreach');
    $o = new Outreach($store, ['LEMLIST_ATTRIBUTION' => 'first_positive']);
    ok($o->metrics(['cam_it000001'], 0, 2_000_000_000)['tiles']['meetings'] >= 1);
    $d->label(['contactId' => 'ctc_it02', 'label' => null]);
    eq(['lbl_vip000001'], $mock->inbox['ctc_it02']['labels'], 'cleared: only VIP left');
    foreach ($mock->calls as $c) if ($c[1] === '/inbox/conversations/labels/ctc_it02') eq(false, $c[3]['appendLabels']);
    throws(fn () => $d->label(['contactId' => 'ctc_it02', 'label' => 'VIP']), ValidationError::class, 'Only the labels');
    // lemlist not returning the current labels: adding is still safe (append), removing is refused
    $mock->returnLabels = false;
    $d->label(['contactId' => 'ctc_it05', 'label' => 'Meeting']);
    eq(true, end($mock->calls)[3]['appendLabels']);
    throws(fn () => $d->label(['contactId' => 'ctc_it05', 'label' => null]), ValidationError::class, 'not safe');
    // a non-admin sender cannot create labels
    $store->setMeta('team_users', [['userId' => MockLemlist::USER, 'name' => 'Jonathan Perkins', 'email' => 'jonathan@swooscale.com', 'role' => 'user']]);
    [$d2, , $mock2] = t_desk();
    $mock2->labels = [];
    (fn () => $this->store)->call($d2)->setMeta('team_users', [['userId' => MockLemlist::USER, 'name' => 'Jonathan Perkins', 'email' => 'jonathan@swooscale.com', 'role' => 'user']]);
    eq('LABEL_NEEDS_ADMIN', throws(fn () => $d2->label(['contactId' => 'ctc_it02', 'label' => 'Interested']), AppError::class)->errCode);
});

test('calls: kill switch LEMLIST_WRITES=off: every action is checked and previewed, nothing is sent, nothing changes', function () {
    [$d, $store, $mock] = t_desk(['LEMLIST_WRITES' => 'off']);
    $v = $d->view();
    eq('off', $v['writes']);
    $from = count($mock->calls);
    $r = $d->logCall(['taskId' => 'opp_man00001', 'attempt' => t_attempt('p'), 'answered' => true, 'status' => 'connected-positive', 'note' => 'n']);
    eq(true, $r['preview']);
    eq(['POST /calls', 'PATCH /tasks'], array_column($r['wouldSend'], 'request'));
    ok(in_array('leadId', $r['wouldSend'][0]['fields'], true));
    ok(str_contains($r['message'], 'nothing was sent'));
    eq(true, $d->taskAction(['taskId' => 'opp_eml00001', 'action' => 'send', 'confirm' => true])['preview']);
    eq(true, $d->taskAction(['taskId' => 'opp_vis00001', 'action' => 'skip'])['preview']);
    eq(true, $d->reply(['contactId' => 'ctc_it02', 'channel' => 'email', 'message' => 'x', 'confirm' => true])['preview']);
    eq(true, $d->label(['contactId' => 'ctc_it02', 'label' => 'Interested'])['preview']);
    eq(false, $d->thread('ctc_it02')['markedRead'], 'not even the read flag changes');
    eq([], t_lem_writes($mock, $from), 'no write reached lemlist');
    eq(false, $mock->inbox['ctc_it02']['users'][0]['read']);
    eq(0, (int) $store->one('SELECT COUNT(*) FROM calls'));
    eq(0, (int) $store->one('SELECT COUNT(*) FROM outcomes'));
    eq('due', $store->one("SELECT status FROM tasks WHERE id = 'opp_man00001'"));
    throws(fn () => $d->reply(['contactId' => 'ctc_it02', 'channel' => 'email', 'message' => 'x']), ValidationError::class, 'Confirm'); // the confirm step is checked in preview mode too
});

test('calls: users outside LEMLIST_SENDER_ALLOWED are view only', function () {
    [$d, , $mock] = t_desk([], 'viewer@x.test');
    $v = $d->view();
    eq(true, $v['viewOnly']);
    eq(null, $v['sender']);
    ok(count($v['queue']) > 0, 'they still see the queue');
    $from = count($mock->calls);
    foreach ([
        fn () => $d->logCall(['taskId' => 'opp_call0001', 'attempt' => t_attempt(), 'answered' => false, 'status' => 'no-answer']),
        fn () => $d->taskAction(['taskId' => 'opp_vis00001', 'action' => 'skip']),
        fn () => $d->reply(['contactId' => 'ctc_it02', 'channel' => 'email', 'message' => 'x', 'confirm' => true]),
        fn () => $d->label(['contactId' => 'ctc_it02', 'label' => 'Interested']),
    ] as $fn) eq('VIEW_ONLY', throws($fn, AppError::class)->errCode);
    eq([], t_lem_writes($mock, $from));
    eq(false, $d->thread('ctc_it02')['markedRead'], 'a viewer does not mark threads read');
});

test('calls: per-user hourly limits (30 sends, 100 call logs) and the routes sit behind CSRF + Origin', function () {
    $dir = tmpdir();
    $l = new CallDeskLimiter("$dir/limits.json");
    $t = 1_000_000;
    $l->now = function () use (&$t) { return $t; };
    for ($i = 0; $i < 30; $i++) $l->hit('jp@x.test', 'send');
    eq('RATE_LIMIT', throws(fn () => $l->hit('jp@x.test', 'send'), AppError::class)->errCode);
    $l->hit('haseeb@x.test', 'send'); // per user
    for ($i = 0; $i < 100; $i++) $l->hit('jp@x.test', 'call');
    throws(fn () => $l->hit('jp@x.test', 'call'), AppError::class, '100 calls logged per hour');
    $t += 3601;
    $l->hit('jp@x.test', 'send'); // a new hour
    ok(!str_contains((string) file_get_contents("$dir/limits.json"), 'note'), 'timestamps only');
    // App: every calls/* POST is refused without the CSRF token or from another origin, before the body is read
    $storage = tmpdir();
    $config = Config::validate(['APP_ENV' => 'development', 'WORKBOOK_BACKEND' => 'mock', 'APP_ORIGIN' => 'https://signals.test', 'TIMEZONE' => '', 'AZURE_TENANT_ID' => '',
        'AZURE_CLIENT_ID' => '', 'AZURE_CLIENT_SECRET' => '', 'GRAPH_DRIVE_ID' => '', 'GRAPH_MASTER_ITEM_ID' => '', 'GRAPH_DEV_ITEM_ID' => '',
        'AUTH_USERS' => 'jp@x.test|JP|' . base64_encode(password_hash('long enough pass', PASSWORD_BCRYPT, ['cost' => 4])), 'STORAGE_DIR' => $storage, 'LEMLIST_BACKEND' => 'mock'], '/p');
    $saved = [$_SERVER, $_SESSION];
    $run = function (string $route, array $server) use ($config): array {
        $_SESSION = ['uid' => 'jp@x.test', 'login_at' => time(), 'csrf' => 'good-token'];
        $_SERVER = $server + ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => "/api/$route", 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'signals.test'];
        ob_start();
        (new App($config))->run();
        return json_decode((string) ob_get_clean(), true) ?: [];
    };
    try {
        foreach (['calls/log', 'calls/task', 'calls/reply', 'calls/label'] as $route) {
            eq('BAD_CSRF', $run($route, ['HTTP_ORIGIN' => 'https://signals.test'])['code'] ?? null, "$route without token");
            eq('BAD_CSRF', $run($route, ['HTTP_ORIGIN' => 'https://signals.test', 'HTTP_X_CSRF_TOKEN' => 'wrong'])['code'] ?? null, "$route wrong token");
            eq('BAD_ORIGIN', $run($route, ['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_X_CSRF_TOKEN' => 'good-token'])['code'] ?? null, "$route other origin");
            eq('VALIDATION', $run($route, ['HTTP_ORIGIN' => 'https://signals.test', 'HTTP_X_CSRF_TOKEN' => 'good-token'])['code'] ?? null, "$route: checks passed, body read");
        }
    } finally {
        [$_SERVER, $_SESSION] = $saved;
    }
});

test('calls: webhook updates: a reply refreshes the inbox, a task event closes the task here at once; the poll is local only', function () {
    [$d, $store, $mock] = t_desk();
    $d->view();
    $cfg = ['LEMLIST_BACKEND' => 'mock', 'LEMLIST_WEBHOOK_SECRET' => T_LEM_SECRET];
    $before = $d->updates();
    eq(null, $before['inboxAt']);
    $n = count($mock->calls);
    LemlistWebhook::handle($cfg, 'POST', json_encode(['_id' => 'act_hk_reply', 'type' => 'linkedinReplied', 'secret' => T_LEM_SECRET, 'contactId' => 'ctc_hr02', 'leadId' => 'lea_hr02']), $store, fn () => T_CALL_NOW + 5);
    eq(T_CALL_NOW + 5, $d->updates()['inboxAt']);
    LemlistWebhook::handle($cfg, 'POST', json_encode(['_id' => 'act_hk_task', 'type' => 'aircallDone', 'secret' => T_LEM_SECRET, 'taskId' => 'opp_call0002', 'leadId' => 'lea_hr01']), $store, fn () => T_CALL_NOW + 9);
    ok(!str_contains(json_encode($d->view()), 'opp_call0002'), 'done in the dialer: gone from the queue at once');
    eq('due', $store->one("SELECT status FROM tasks WHERE id = 'opp_call0002'"), 'the webhook itself does not write SQLite');
    $u = $d->updates();
    HookBuffer::import($store);
    eq('closed', $store->one("SELECT status FROM tasks WHERE id = 'opp_call0002'"), 'closed in SQLite by the cron import');
    eq(T_CALL_NOW + 9, $u['tasksAt']);
    eq(T_CALL_NOW + 9, $u['eventAt']);
    eq($n, count($mock->calls), 'polling updates makes no lemlist request');
    eq(CallDesk::dueCount($store), $u['badge']);
});

test('calls: a positive call counts in the Outreach numbers (call answered, Interested)', function () {
    [$d, $store] = t_desk();
    $d->view();
    $o = new Outreach($store, ['LEMLIST_ATTRIBUTION' => 'first_positive']);
    $o->now = fn () => T_CALL_NOW + 60;
    $before = $o->metrics(['cam_hr000001'], T_CALL_NOW - 3600, T_CALL_NOW + 3600)['channels']['call'];
    $d->logCall(['taskId' => 'opp_call0002', 'attempt' => t_attempt('pos'), 'answered' => true, 'status' => 'connected-positive', 'note' => 'great call']);
    eq('Interested', $store->one("SELECT outcome FROM outcomes WHERE lead_id = 'lea_hr01'"));
    $o2 = new Outreach($store, ['LEMLIST_ATTRIBUTION' => 'first_positive']);
    $after = $o2->metrics(['cam_hr000001'], T_CALL_NOW - 3600, T_CALL_NOW + 3600)['channels']['call'];
    eq($before['actions'] + 1, $after['actions'], 'a call made');
    eq($before['responses'] + 1, $after['responses'], 'a conversation (answered)');
    eq(1, $after['extra']['value'], 'a positive call');
    eq(['done' => 1, 'answered' => 1, 'positive' => 1], $d->today());
});

test('call scripts: {{variables}} filled from the lead (spaces and case ignored, standard fields, fallbacks, nested once); only used fields are stored', function () {
    $vals = \SignalDesk\LeadVars::values(['first_name' => 'Lena', 'last_name' => 'V', 'company' => 'Northbridge', 'title' => 'CISO', 'phone' => '+32 470', 'email' => '', 'linkedin' => '',
        'fields' => json_encode(['Touch 1 Call' => 'Hi {{firstName}}, Jonathan here.', 'Call-again' => 'Again, {{ companyName }}', 'Final call' => ''])]);
    eq(['Hi Lena, Jonathan here.', []], \SignalDesk\LeadVars::fill('{{Touch 1 Call}}', $vals), 'names with spaces; nested {{firstName}} filled');
    eq('Hi Lena, Jonathan here.', \SignalDesk\LeadVars::fill('{{ touch 1 call }}', $vals)[0], 'case and spacing ignored');
    eq('Again, Northbridge', \SignalDesk\LeadVars::fill('{{Call-again}}', $vals)[0]);
    eq(['{{Final call}}', ['Final call']], \SignalDesk\LeadVars::fill('{{Final call}}', $vals), 'empty field: stays as written, reported missing');
    eq('Hello there', \SignalDesk\LeadVars::fill('Hello {{nickname|there}}', $vals)[0], 'fallback after |');
    eq(['Touch 1 Call', 'firstName', 'Call-again'], \SignalDesk\LeadVars::names('{{Touch 1 Call}} {{firstName|you}} {{ Call-again }} {{firstName}}'));
    eq('{"Touch 1 Call":"x","Call-again":"y"}', \SignalDesk\LeadVars::pick(['touch 1 call' => 'x', 'Call-again' => 'y', 'Internal note' => 'z', 'firstName' => 'L'], ['Touch 1 Call', 'Call-again', 'firstName']),
        'only the custom fields steps use (standard ones live in their own columns)');
    [$store] = t_lem_synced();
    $f = json_decode((string) $store->one("SELECT fields FROM leads WHERE lead_id = 'lea_it01'"), true);
    eq(['Touch 1 Call', 'Call-again', 'Touch 2 mail', 'Final call'], array_keys($f), 'synced from the lead export');
    ok(!str_contains(json_encode($f), 'not used by any step'), 'unused custom fields are not stored');
    ok(!str_contains(json_encode($store->all('SELECT * FROM tasks')), 'Jonathan from SwooScale'), 'scripts only in the leads table');
});

test('call scripts: each call task is matched to its step (stepId, sequence position, title pattern, type) and filled', function () {
    $steps = [
        ['step_id' => 's_call', 'type' => 'phone', 'main' => 1, 'seq_step' => 3, 'title' => 'Call {{phone}}', 'message' => '{{Touch 1 Call}}'],
        ['step_id' => 's_again', 'type' => 'manual', 'main' => 1, 'seq_step' => 4, 'title' => 'Calling Again {{phone}}', 'message' => '{{Call-again}}'],
        ['step_id' => 's_vm', 'type' => 'manual', 'main' => 0, 'seq_step' => 1, 'title' => 'Voice mail{{phone}}', 'message' => '<p>{{Touch 2 mail}}</p>'],
        ['step_id' => 's_final', 'type' => 'manual', 'main' => 0, 'seq_step' => 2, 'title' => 'Final Call {{phone}}', 'message' => '{{Final call}}'],
    ];
    $t = fn (array $x) => $x + ['type' => 'manual', 'title' => '', 'step_id' => null, 'seq_step' => null];
    eq('s_vm', CallDesk::stepFor($t(['step_id' => 's_vm', 'title' => 'whatever']), $steps)['step_id'], 'the stepId of the task wins');
    eq('s_again', CallDesk::stepFor($t(['seq_step' => 4]), $steps)['step_id'], 'sequence position');
    eq('s_again', CallDesk::stepFor($t(['title' => 'Calling again (phone)']), $steps)['step_id'], 'title pattern, case ignored');
    eq('s_vm', CallDesk::stepFor($t(['title' => 'Voice mail (phone)']), $steps)['step_id']);
    eq('s_final', CallDesk::stepFor($t(['title' => 'Final call: Mia Meeting']), $steps)['step_id']);
    eq('s_call', CallDesk::stepFor($t(['type' => 'phone', 'title' => 'Ring them']), $steps)['step_id'], 'the only phone step');
    [$d] = t_desk();
    $q = array_column($d->view()['queue'], null, 'id');
    eq("Hi Ciso, Jonathan from SwooScale. I saw Example MSSP is hiring a Business Analyst.\nWe have 3 analysts in Belgium available within a month. Worth a short chat?",
        $q['opp_call0001']['context']['script']['text'], 'phone task (stepId) -> {{Touch 1 Call}} with {{firstName}} inside');
    eq(['Touch 1 Call'], $q['opp_call0001']['context']['script']['fields']);
    eq('Hi Ciso, Jonathan from SwooScale here. Call me back on 0470 12 34 56.', $q['opp_call0001']['context']['voicemail']['text'], 'voicemail script shown when the lead has it');
    ok(str_starts_with($q['opp_man00001']['context']['script']['text'], 'Hi Robin, Jonathan from SwooScale calling again.'), '"Calling Again" task (title match) -> {{Call-again}}');
    eq('Robin, Jonathan from SwooScale. I will email you two profiles.', $q['opp_vml00001']['context']['script']['text'], 'Voice mail task -> {{Touch 2 mail}}, HTML stripped');
    eq(null, $q['opp_vml00001']['context']['voicemail'], 'no separate voicemail box on the voicemail task itself');
    eq('Hi Mia, final call from Jonathan about the IT Director search.', $q['opp_fin00001']['context']['script']['text'], '"Final call" -> {{Final call}}');
    eq('Hi Kim, Jonathan from SwooScale. Your HR team is hiring: can we help with IT profiles?', $q['opp_call0002']['context']['script']['text'], 'HR campaign');
    $manual = array_column($d->view()['manual'], null, 'id');
    eq($q['opp_man00001']['context']['script'], $manual['opp_man00001']['script'], 'the same script on the manual task list');
    eq($q['opp_vml00001']['context']['script']['text'], $manual['opp_vml00001']['script']['text']);
});

test('call scripts: an empty field gives "no script" with the raw step text; a changed field in lemlist updates the script at the next sync; never in the audit log', function () {
    [$d, $store, $mock, , $client] = t_desk();
    $q = array_column($d->view()['queue'], null, 'id');
    $s = $q['opp_call0003']['context']['script'];
    eq(true, $s['empty'], 'Nel has an empty "Touch 1 Call"');
    eq(null, $s['text']);
    eq('{{Touch 1 Call}}', $s['raw'], 'the raw step text is shown instead');
    eq(null, $q['opp_call0003']['context']['voicemail']);
    foreach ($mock->leads['cam_it000001'] as $i => $l) if ($l['_id'] === 'lea_it04') $mock->leads['cam_it000001'][$i]['Touch 1 Call'] = 'Hi Nel, Jonathan from SwooScale: new script.';
    t_out_resync($store, $mock, $client);
    $q = array_column($d->view()['queue'], null, 'id');
    eq('Hi Nel, Jonathan from SwooScale: new script.', $q['opp_call0003']['context']['script']['text'], 'refreshed at the next sync');
    $d->logCall(['taskId' => 'opp_call0003', 'attempt' => t_attempt('scr'), 'answered' => true, 'status' => 'connected-neutral', 'note' => '']);
    $log = json_encode($GLOBALS['__events']);
    foreach (['new script', 'Jonathan from SwooScale', 'Touch 1 Call'] as $txt) ok(!str_contains($log, $txt), "audit has no $txt");
});
