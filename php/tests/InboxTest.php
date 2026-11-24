<?php
// The Inbox mirrors the lemlist inbox (25 Nov 2026). Fixtures shaped like the real API (GET /inbox rows with lastReplied* / lastSent*;
// GET /inbox/{contactId} activities newest first, paged with skip + limit, campaignId + leadId on each; LinkedIn text in `message`
// or `text`), names fictional. Checks: list order and paging, "You:" previews, search, label filters, the unread badge, read / unread,
// full threads for every channel with "Load older", email trimming + full text, subjects and attachments, the campaign of a conversation.
declare(strict_types=1);

use SignalDesk\CallDesk;
use SignalDesk\MockLemlist;
use SignalDesk\ValidationError;

/** One GET /inbox row as the real API returns it. */
function t_ibx(string $ctc, string $name, ?string $repliedAt, ?string $replyPreview, ?string $sentAt, ?string $sentPreview, string $channel = 'email', bool $read = true, array $labels = []): array
{
    return ['id' => 'ibx_' . substr($ctc, 4), 'contactId' => $ctc, 'contactName' => $name, 'contactEmail' => strtolower(str_replace(' ', '.', $name)) . '@fixture.example',
        'contactLinkedinUrl' => 'https://www.linkedin.com/in/' . strtolower(str_replace(' ', '-', $name)),
        'lastActivityAt' => max((string) $repliedAt, (string) $sentAt), 'lastRepliedAt' => $repliedAt, 'lastRepliedChannel' => $repliedAt ? $channel : null,
        'lastRepliedSubject' => null, 'lastRepliedMessagePreview' => $replyPreview, 'lastSentAt' => $sentAt, 'lastSentSubject' => null, 'lastSentMessagePreview' => $sentPreview,
        'channels' => ['email', 'linkedin'], 'isYourTurn' => (string) $repliedAt > (string) $sentAt, 'users' => [['userId' => MockLemlist::USER, 'read' => $read]], 'labels' => $labels];
}

/** One raw activity of GET /inbox/{contactId}. */
function t_act(string $id, string $type, string $at, string $text, string $field = 'message', array $extra = []): array
{
    return $extra + ['_id' => $id, 'type' => $type, 'createdAt' => $at, $field => $text, 'campaignId' => 'cam_it000001', 'leadId' => 'lea_fx01', 'contactId' => 'ctc_x',
        'sendUserId' => MockLemlist::USER, 'sendUserName' => 'Jonathan Perkins', 'hasAttachments' => false, 'attachments' => []];
}

function t_inbox_fixture(MockLemlist $m): void
{
    $m->labels[] = ['_id' => 'lbl_notint001', 'name' => 'Not interested'];
    $m->labels[] = ['_id' => 'lbl_ooo000001', 'name' => 'Out of office'];
    $m->rawInbox = [
        t_ibx('ctc_fxOurLast01', 'Chris Fixture', '2026-11-21T06:56:29.034Z', 'Hi Jonathan, there is no need to send more profiles', '2026-11-23T15:47:27.895Z', "Hi Chris,\n\nThanks for getting back to me!", 'linkedin'),
        t_ibx('ctc_fxSentOnly1', 'Sam Fixture', null, null, '2026-11-22T16:00:00.000Z', 'Hi Sam,'),
        t_ibx('ctc_fxTheirTurn1', 'Carla Fixture', '2026-11-19T13:32:44.000Z', 'Jonathan,', '2026-11-19T08:11:45.263Z', 'Hi Carla,', 'email', false),
        t_ibx('ctc_fxTheirTurn2', 'Bart Fixture', '2026-11-17T08:39:15.245Z', 'for now my team is complete so no openings in the foreseeable future',
            '2026-11-17T08:37:34.345Z', 'Hey Bart - No problem, how do the headcount plans look for 2027?', 'linkedin', true, ['lbl_notint001']),
        t_ibx('ctc_fxOoo000001', 'Otto Fixture', '2026-11-10T09:00:00.000Z', 'I am out of the office until Monday', '2026-11-09T09:00:00.000Z', 'Hi Otto,', 'email', false, ['lbl_ooo000001']),
    ];
}

test('inbox list (real API shape): all conversations in lemlist\'s order, latest activity first, sent-only too; the last message\'s preview with "You:" when ours', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    [$d] = t_desk([], 'jp@x.test', $m);
    $i = $d->inbox();
    eq(['ctc_fxOurLast01', 'ctc_fxSentOnly1', 'ctc_fxTheirTurn1', 'ctc_fxTheirTurn2', 'ctc_fxOoo000001'], array_column($i['conversations'], 'contactId'));
    $by = array_column($i['conversations'], null, 'contactId');
    eq(['Hi Chris, Thanks for getting back to me!', true, '2026-11-23T15:47:27.895Z'], [$by['ctc_fxOurLast01']['snippet'], $by['ctc_fxOurLast01']['ours'], $by['ctc_fxOurLast01']['at']], 'our message is the last one');
    eq(['Hi Sam,', true], [$by['ctc_fxSentOnly1']['snippet'], $by['ctc_fxSentOnly1']['ours']], 'sent-only, as in lemlist');
    eq(['for now my team is complete so no openings in the foreseeable future', false, 'linkedin'], [$by['ctc_fxTheirTurn2']['snippet'], $by['ctc_fxTheirTurn2']['ours'], $by['ctc_fxTheirTurn2']['channel']]);
    eq([['Not interested'], 'Not interested'], [$by['ctc_fxTheirTurn2']['labels'], $by['ctc_fxTheirTurn2']['label']]);
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    ok(str_contains($js, 'c.ours ? h("span", { class: "sd-k-you", text: "You: " })') && !str_contains($js, '"Needs reply"') && !str_contains($js, '"Your turn"') && !str_contains($js, 'Done, no reply needed'),
        'no Needs reply / Your turn / Done any more');
});

test('inbox filters, search and paging: All · Unread · Interested · Meeting · Not interested · Out of office; name, email or company; 25 at a time with Load more', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    [$d, , $mock] = t_desk([], 'jp@x.test', $m);
    eq(['ctc_fxTheirTurn1', 'ctc_fxOoo000001'], array_column($d->inbox('unread')['conversations'], 'contactId'));
    eq(['ctc_fxTheirTurn2'], array_column($d->inbox('Not interested')['conversations'], 'contactId'));
    eq(['ctc_fxOoo000001'], array_column($d->inbox('Out of office')['conversations'], 'contactId'));
    eq([], $d->inbox('Meeting')['conversations']);
    eq('all', $d->inbox('needs')['filter'], 'the old filters are gone');
    eq(['ctc_fxTheirTurn1'], array_column($d->inbox('all', 'carla')['conversations'], 'contactId'), 'by name');
    eq(['ctc_fxTheirTurn2'], array_column($d->inbox('all', 'bart.fixture@')['conversations'], 'contactId'), 'by email');
    $p1 = $d->inbox('all', '', 1, 2);
    eq([['ctc_fxOurLast01', 'ctc_fxSentOnly1'], 5, true], [array_column($p1['conversations'], 'contactId'), $p1['total'], $p1['hasMore']]);
    $p3 = $d->inbox('all', '', 3, 2);
    eq([['ctc_fxOoo000001'], false], [array_column($p3['conversations'], 'contactId'), $p3['hasMore']]);
    // 120 conversations: every lemlist page of 50 is read
    $mock->rawInbox = [];
    for ($i = 0; $i < 120; $i++) $mock->rawInbox[] = t_ibx(sprintf('ctc_fxMany%05d', $i), "Lead $i", null, null, sprintf('2026-11-%02dT%02d:00:00.000Z', 20 - intdiv($i, 24), 23 - $i % 24), 'Hi,');
    $from = count($mock->calls);
    $all = $d->inbox('all', '', 5, 25);
    eq([120, false, 20, 'ctc_fxMany00100'], [$all['total'], $all['hasMore'], count($all['conversations']), $all['conversations'][0]['contactId']]);
    eq([1, 2, 3], array_map('intval', array_column(array_column(array_values(array_filter(array_slice($mock->calls, $from), fn ($c) => $c[1] === '/inbox')), 2), 'page')));
});

test('inbox badge = unread conversations (as lemlist); opening marks read; "Mark as unread" is kept here (lemlist has no API for it) until opened again', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    [$d, $store, $mock] = t_desk([], 'jp@x.test', $m);
    eq(2, $d->inbox()['unread']);
    eq(2, CallDesk::cachedUnread($store->dir));
    eq(2, $d->unread()['badge']);
    $writes = count(array_filter($mock->calls, fn ($c) => $c[0] !== 'GET'));
    $GLOBALS['__events'] = [];
    eq(true, $d->markUnread(['contactId' => 'ctc_fxOurLast01'])['unread']);
    eq($writes, count(array_filter($mock->calls, fn ($c) => $c[0] !== 'GET')), 'nothing sent to lemlist');
    ok(str_contains(json_encode($GLOBALS['__events']), '"action":"inbox_unread","contactId":"ctc_fxOurLast01"'), 'audited (ids only)');
    $i = $d->inbox();
    eq([3, true], [$i['unread'], array_column($i['conversations'], null, 'contactId')['ctc_fxOurLast01']['unread']]);
    eq(3, CallDesk::cachedUnread($store->dir), 'the top-bar badge follows');
    $mock->rawThreads['ctc_fxOurLast01'] = [t_act('act_c1', 'linkedinReplied', '2026-11-21T06:56:29.034Z', 'Hi Jonathan', 'text')];
    $t = $d->thread('ctc_fxOurLast01');
    eq(true, $t['markedRead'], 'opening marks it read in lemlist (markAsRead)');
    eq('true', end($mock->calls)[2]['markAsRead'] ?? null);
    eq(2, $d->inbox()['unread'], 'opened again: our "unread" mark is gone');
    throws(fn () => $d->markUnread(['contactId' => 'x']), ValidationError::class);
    // writes off (preview mode): reading does not change lemlist
    $m2 = new MockLemlist();
    t_inbox_fixture($m2);
    $m2->rawThreads['ctc_fxTheirTurn1'] = [t_act('act_x', 'emailsReplied', '2026-11-19T13:32:44.000Z', '<p>Jonathan,</p>')];
    [$d2] = t_desk(['LEMLIST_WRITES' => 'off'], 'jp@x.test', $m2);
    eq(false, $d2->thread('ctc_fxTheirTurn1')['markedRead']);
});

test('inbox thread: every message of every channel with its text (LinkedIn in `message` or `text`, WhatsApp, SMS), oldest at the top, line breaks kept', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    $m->rawThreads['ctc_fxTheirTurn2'] = [ // newest first, as lemlist returns them
        t_act('act_bcoHPcCYHMdC1', 'linkedinReplied', '2026-11-17T08:39:15.245Z', 'for now my team is complete so no openings in the foreseeable future'),
        t_act('act_irSXLYFskrop1', 'linkedinSent', '2026-11-17T08:37:34.345Z', 'Hey Bart - No problem, how do the headcount plans look for the rest of the year and 2027?', 'text'),
        t_act('act_nLN9ddDiEzT1', 'linkedinReplied', '2026-11-17T08:35:47.316Z', "Hi Jonathan, thanks for reaching out.\nThe position has been filled in the meantime"),
        t_act('act_wa00000001', 'whatsappReplied', '2026-11-14T10:00:00.000Z', 'ok, call me', 'body'),
        t_act('act_sms0000001', 'smsSent', '2026-11-13T10:00:00.000Z', 'Tried to reach you'),
        t_act('act_zmA9XdejoX81', 'emailsSent', '2026-11-12T13:59:24.252Z', '<p>Hi Bart,</p><p>Just following up on the Data Engineer.</p><p>Best, JP</p>', 'message', ['subject' => 'Re: A permanent Data Engineer']),
    ];
    [$d] = t_desk([], 'jp@x.test', $m);
    $t = $d->thread('ctc_fxTheirTurn2');
    eq(['emailsSent', 'smsSent', 'whatsappReplied', 'linkedinReplied', 'linkedinSent', 'linkedinReplied'], array_column($t['messages'], 'type'), 'oldest at the top');
    eq([true, true, false, false, true, false], array_column($t['messages'], 'out'));
    eq(['Email', 'SMS', 'WhatsApp', 'LinkedIn', 'LinkedIn', 'LinkedIn'], array_column($t['messages'], 'channel'));
    eq("Hi Jonathan, thanks for reaching out.\nThe position has been filled in the meantime", $t['messages'][3]['text'], 'LinkedIn text from `message`, line break kept');
    eq('Hey Bart - No problem, how do the headcount plans look for the rest of the year and 2027?', $t['messages'][4]['text'], 'LinkedIn text from `text`');
    eq(['ok, call me', 'Tried to reach you'], [$t['messages'][2]['text'], $t['messages'][1]['text']]);
    eq(["Hi Bart,\nJust following up on the Data Engineer.\nBest, JP", 'Re: A permanent Data Engineer'], [$t['messages'][0]['text'], $t['messages'][0]['subject']]);
    eq([null, null], [$t['messages'][3]['subject'], $t['messages'][3]['full']], 'subjects on emails only');
    foreach ($t['messages'] as $msg) ok($msg['text'] !== '', 'no empty bubble: ' . $msg['type']);
    eq('Q&A <3 tomorrow', CallDesk::messageText(['type' => 'linkedinSent', 'text' => 'Q&amp;A <3 tomorrow']), 'plain text is not run through the HTML stripper');
    eq("x\ny", CallDesk::messageText(['type' => 'linkedinReplied', 'message' => '', 'text' => "x\r\ny"]), 'an empty `message` falls back to `text`');
});

test('inbox thread: "Load older" pages of 10 (skip), merged oldest first', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    $acts = [];
    for ($i = 0; $i < 13; $i++) $acts[] = t_act(sprintf('act_pg%05d', $i), $i % 2 ? 'linkedinReplied' : 'linkedinSent', sprintf('2026-11-%02dT10:00:00.000Z', 1 + $i), "Message $i");
    $m->rawThreads['ctc_fxTheirTurn1'] = array_reverse($acts);
    [$d, , $mock] = t_desk([], 'jp@x.test', $m);
    $t = $d->thread('ctc_fxTheirTurn1');
    eq([10, true, 'Message 3', 'Message 12'], [count($t['messages']), $t['hasOlder'], $t['messages'][0]['text'], $t['messages'][9]['text']], 'the newest 10');
    $o = $d->thread('ctc_fxTheirTurn1', 10);
    eq([['Message 0', 'Message 1', 'Message 2'], false], [array_column($o['messages'], 'text'), $o['hasOlder']]);
    eq(10, (int) end($mock->calls)[2]['skip']);
    ok(!isset(end($mock->calls)[2]['markAsRead']), '"Load older" does not mark read again');
});

test('inbox email: the reply text without the quoted history, the full email on demand; subject and attachment names', function () {
    $quoted = '<div>Thanks Jonathan, Tuesday works.</div><div><br></div><div>Kind regards,<br>Carla</div><div><br></div>'
        . '<div>On Mon, 17 Nov 2026 at 09:12, Jonathan Perkins &lt;jonathan@swooscale.com&gt; wrote:</div><blockquote><p>Hi Carla,</p><p>Would Tuesday work?</p></blockquote>';
    eq("Thanks Jonathan, Tuesday works.\n\nKind regards,\nCarla", CallDesk::trimQuoted(CallDesk::messageText(['message' => $quoted])));
    eq("Dag Jonathan,\nGraag.", CallDesk::trimQuoted("Dag Jonathan,\nGraag.\n\nOp ma 17 nov. 2026 om 09:12 schreef Jonathan Perkins <jonathan@swooscale.com>:\n> Hallo"));
    eq('Sounds good.', CallDesk::trimQuoted("Sounds good.\n\n-----Original Message-----\nFrom: Jonathan\nSent: Monday"));
    eq('Sounds good.', CallDesk::trimQuoted("Sounds good.\n\nFrom: Jonathan Perkins <jonathan@swooscale.com>\nSent: Monday, 17 November 2026 09:12\nTo: Carla"));
    eq('Just one line, nothing quoted', CallDesk::trimQuoted('Just one line, nothing quoted'));
    $m = new MockLemlist();
    t_inbox_fixture($m);
    $m->rawThreads['ctc_fxTheirTurn1'] = [
        t_act('act_e2', 'emailsReplied', '2026-11-19T13:32:44.000Z', $quoted, 'message', ['subject' => 'RE: A permanent OT Cybersecurity Program Manager', 'hasAttachments' => true,
            'attachments' => [['name' => 'job-description.pdf', 'size' => 1200], ['filename' => 'org-chart.png']]]),
        t_act('act_e1', 'emailsSent', '2026-11-17T08:12:00.000Z', '<p>Hi Carla,</p><p>Would Tuesday work?</p>', 'message', ['subject' => 'A permanent OT Cybersecurity Program Manager']),
    ];
    [$d] = t_desk([], 'jp@x.test', $m);
    $t = $d->thread('ctc_fxTheirTurn1');
    $reply = $t['messages'][1];
    eq("Thanks Jonathan, Tuesday works.\n\nKind regards,\nCarla", $reply['text']);
    ok(str_contains((string) $reply['full'], 'Would Tuesday work?'), 'the full email, for "Show full email"');
    eq(['RE: A permanent OT Cybersecurity Program Manager', ['job-description.pdf', 'org-chart.png'], true], [$reply['subject'], $reply['attachments'], $reply['hasAttachments']]);
    eq(null, $t['messages'][0]['full'], 'nothing quoted: no toggle');
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    ok(str_contains($js, '"Show full email"') && str_contains($js, '"Load older messages"') && str_contains($js, '"Mark as unread"') && str_contains($js, '"Load more"'), 'UI');
});

test('inbox campaign (real API shape): the newest activity with a campaignId sets it; a paused one keeps its name; a direct contact is "Not in a campaign"; unknown until read', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    $m->campaigns[] = ['_id' => 'cam_bzYxH8vakqDC8WJgj', 'name' => 'Friday Campaign - MPC', 'status' => 'paused', 'sequenceId' => 'seq_x', 'createdAt' => '2026-10-01T08:00:00Z'];
    $m->rawThreads['ctc_fxOurLast01'] = [
        t_act('act_c3', 'linkedinSent', '2026-11-23T15:47:27.895Z', 'Thanks for getting back to me', 'text', ['campaignId' => 'cam_bzYxH8vakqDC8WJgj', 'leadId' => 'lea_fxChris01']),
        t_act('act_c2', 'linkedinReplied', '2026-11-21T06:56:29.034Z', 'No need to send more profiles', 'text', ['campaignId' => 'cam_bzYxH8vakqDC8WJgj', 'leadId' => 'lea_fxChris01']),
        t_act('act_c1', 'emailsSent', '2026-10-02T08:00:00.000Z', '<p>Hi</p>', 'message', ['campaignId' => 'cam_it000001', 'leadId' => 'lea_fxOld']),
    ];
    $m->rawThreads['ctc_fxSentOnly1'] = [t_act('act_s1', 'emailsSent', '2026-11-22T16:00:00.000Z', '<p>Hi Sam,</p>', 'message', ['campaignId' => null, 'leadId' => null])];
    $ten = [];
    for ($i = 9; $i >= 0; $i--) $ten[] = t_act("act_u$i", 'emailsReplied', sprintf('2026-11-1%dT10:00:00.000Z', $i), '<p>x</p>', 'message', ['campaignId' => null, 'leadId' => null]);
    $m->rawThreads['ctc_fxTheirTurn1'] = array_merge($ten, [t_act('act_u_old', 'emailsSent', '2026-10-01T10:00:00.000Z', '<p>first</p>', 'message', ['campaignId' => 'cam_hr000001', 'leadId' => 'lea_fxCarla'])]);
    [$d] = t_desk([], 'jp@x.test', $m);
    $by = array_column($d->inbox()['conversations'], null, 'contactId');
    eq(['Friday Campaign - MPC', 'paused', 'lea_fxChris01', true, true], [$by['ctc_fxOurLast01']['campaign'], $by['ctc_fxOurLast01']['campaignStatus'], $by['ctc_fxOurLast01']['leadId'], $by['ctc_fxOurLast01']['inCampaign'], $by['ctc_fxOurLast01']['canLinkedin']],
        'the newest activity with a campaign; a paused campaign keeps its name');
    eq([false, false], [$by['ctc_fxSentOnly1']['inCampaign'], $by['ctc_fxSentOnly1']['canLinkedin']], 'a direct contact (every activity read, none with a campaign)');
    eq([null, null], [$by['ctc_fxTheirTurn1']['inCampaign'], $by['ctc_fxTheirTurn1']['campaignId']], '10 activities without a campaign but older ones exist: not known yet, never "Not in a campaign"');
    $t = $d->thread('ctc_fxTheirTurn1', 10); // "Load older" reaches the activity with the campaign
    eq(['HR decision makers – Belgium', true], [$t['campaign'], $t['inCampaign']]);
    eq('HR decision makers – Belgium', array_column($d->inbox()['conversations'], null, 'contactId')['ctc_fxTheirTurn1']['campaign'], 'remembered for the list');
});
