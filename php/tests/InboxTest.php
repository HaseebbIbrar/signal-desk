<?php
// Inbox with fixtures shaped like the real lemlist API (GET /inbox rows: lastReplied* and lastSent* fields, isYourTurn;
// GET /inbox/{contactId}: activities by type). Names are fictional. Checks: snippet = the lead's reply, sort by reply time,
// the "Needs reply" filter (default), sent-only hidden, the badge, thread directions, and leaving "Needs reply" after a reply here.
declare(strict_types=1);

use SignalDesk\CallDesk;
use SignalDesk\MockLemlist;

/** One GET /inbox row as the real API returns it. */
function t_ibx(string $ctc, string $name, ?string $repliedAt, ?string $replyPreview, ?string $sentAt, ?string $sentPreview, bool $yourTurn, string $channel = 'email', bool $read = true): array
{
    return ['id' => 'ibx_' . substr($ctc, 4), 'contactId' => $ctc, 'contactName' => $name, 'contactEmail' => strtolower(str_replace(' ', '.', $name)) . '@fixture.example',
        'contactLinkedinUrl' => 'https://www.linkedin.com/in/' . strtolower(str_replace(' ', '-', $name)),
        'lastActivityAt' => max((string) $repliedAt, (string) $sentAt), 'lastRepliedAt' => $repliedAt, 'lastRepliedChannel' => $repliedAt ? $channel : null,
        'lastRepliedSubject' => $repliedAt && $channel === 'email' ? 'Re: A permanent Data Engineer' : null, 'lastRepliedMessagePreview' => $replyPreview,
        'lastSentAt' => $sentAt, 'lastSentSubject' => $sentAt ? 'Re: A permanent Data Engineer' : null, 'lastSentMessagePreview' => $sentPreview,
        'channels' => ['email', 'linkedin'], 'isYourTurn' => $yourTurn, 'users' => [['userId' => MockLemlist::USER, 'read' => $read]], 'labels' => []];
}

function t_inbox_fixture(MockLemlist $m): void
{
    $m->rawInbox = [
        // newest activity is OUR message, their reply is older: not our turn, "You: …" second line
        t_ibx('ctc_fxOurLast01', 'Chris Fixture', '2026-11-21T06:56:29.034Z', 'Hi Jonathan, thanks for following up. There\'s no need to send additional profiles',
            '2026-11-23T15:47:27.895Z', "Hi Chris,\n\nThanks for getting back to me, appreciate it!", false, 'linkedin'),
        // they replied last: our turn
        t_ibx('ctc_fxTheirTurn1', 'Carla Fixture', '2026-11-19T13:32:44.000Z', 'Jonathan,', '2026-11-19T08:11:45.263Z', 'Hi Carla,', true, 'email', false),
        t_ibx('ctc_fxTheirTurn2', 'Bart Fixture', '2026-11-17T08:39:15.245Z', 'for now my team is complete so no openings in the foreseeable future',
            '2026-11-17T08:37:34.345Z', 'Hey Bart - No problem, how do the headcount plans look for 2027?', true, 'linkedin'),
        // sent only: no reply at all (lemlist list "sentOnly") — newest activity of all, still not in the Inbox
        t_ibx('ctc_fxSentOnly1', 'Sam Fixture', null, null, '2026-11-23T16:00:00.000Z', 'Hi Sam,', false),
        // not our turn but unread: counts in the badge
        t_ibx('ctc_fxUnread001', 'Uma Fixture', '2026-11-10T09:00:00.000Z', 'Thanks, will forward internally.', '2026-11-12T09:00:00.000Z', 'Great, speak soon.', false, 'email', false),
    ];
}

test('inbox (real API shape): snippet = the lead\'s last reply with its channel and time; "You: …" only when we wrote after it; sorted by reply time; sent-only hidden', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    [$d] = t_desk([], 'jp@x.test', $m);
    $all = $d->inbox('all');
    eq(['ctc_fxOurLast01', 'ctc_fxTheirTurn1', 'ctc_fxTheirTurn2', 'ctc_fxUnread001'], array_column($all['conversations'], 'contactId'), 'newest reply first, not newest activity; no sent-only');
    $by = array_column($all['conversations'], null, 'contactId');
    $chris = $by['ctc_fxOurLast01'];
    eq(['Hi Jonathan, thanks for following up. There\'s no need to send additional profiles', 'linkedin', '2026-11-21T06:56:29.034Z', false],
        [$chris['snippet'], $chris['channel'], $chris['at'], $chris['yourTurn']], 'their reply, not our later message');
    eq('Hi Chris, Thanks for getting back to me, appreciate it!', $chris['youWrote'], 'our later message as the quiet second line');
    $bart = $by['ctc_fxTheirTurn2'];
    eq(['for now my team is complete so no openings in the foreseeable future', null, true], [$bart['snippet'], $bart['youWrote'], $bart['yourTurn']], 'they wrote last: no "You:" line');
    ok(!in_array('ctc_fxSentOnly1', array_column($all['conversations'], 'contactId'), true), 'sent-only conversations are left out');
    foreach ($all['conversations'] as $c) ok(!str_starts_with((string) $c['snippet'], 'Hi Chris') && !str_starts_with((string) $c['snippet'], 'Hi Carla'), 'never our own message as the snippet');
});

test('inbox (real API shape): "Needs reply" (isYourTurn) is the default view; All / Unread / Interested / Meeting kept; badge = our turn + unread', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    [$d, $store] = t_desk([], 'jp@x.test', $m);
    $def = $d->inbox();
    eq(['needs', ['ctc_fxTheirTurn1', 'ctc_fxTheirTurn2']], [$def['filter'], array_column($def['conversations'], 'contactId')], 'default: our turn only, newest reply first');
    eq([2, 2, 3], [$def['needs'], $def['unread'], $def['badge']], 'badge: Carla + Bart (our turn) + Uma (unread); Carla is both and counts once');
    eq(['ctc_fxTheirTurn1', 'ctc_fxUnread001'], array_column($d->inbox('unread')['conversations'], 'contactId'));
    eq(4, count($d->inbox('all')['conversations']));
    eq([], $d->inbox('Interested')['conversations']);
    eq('needs', $d->inbox('bogus')['filter'], 'unknown filter: the default');
    eq(3, CallDesk::cachedUnread($store->dir), 'the top-bar Inbox badge');
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    ok(str_contains($js, 'inbox: { filter: "needs"') && str_contains($js, '[["needs", "Needs reply"], ["all", "All"], ["unread", "Unread"], ["Interested", "Interested"], ["Meeting", "Meeting"]]'), 'Needs reply first and default');
    ok(str_contains($js, '"Your turn"') && str_contains($js, 'text: "↩ "') && str_contains($js, '"You: " + c.youWrote'), 'list row: ↩ reply, Your turn badge, You: line');
});

test('inbox thread (real API shape): *Replied = incoming (left), *Sent = outgoing (right), even when a reply carries the sender id', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    $m->rawThreads['ctc_fxTheirTurn2'] = [
        ['id' => 'act_fx6', 'type' => 'linkedinReplied', 'createdAt' => '2026-11-17T08:39:15.245Z', 'subject' => null, 'message' => 'for now my team is complete', 'leadId' => 'lea_fx01', 'sendUserId' => MockLemlist::USER],
        ['id' => 'act_fx5', 'type' => 'linkedinSent', 'createdAt' => '2026-11-17T08:37:34.345Z', 'subject' => null, 'message' => 'Hey Bart - No problem', 'leadId' => 'lea_fx01'],
        ['id' => 'act_fx4', 'type' => 'emailsReplied', 'createdAt' => '2026-11-14T10:00:00.000Z', 'subject' => 'Re: x', 'message' => '<p>Thanks for reaching out.</p>', 'leadId' => 'lea_fx01', 'sendUserEmail' => 'jonathan@swooscale.com'],
        ['id' => 'act_fx3', 'type' => 'whatsappReplied', 'createdAt' => '2026-11-13T10:00:00.000Z', 'message' => 'ok'],
        ['id' => 'act_fx2', 'type' => 'smsReplied', 'createdAt' => '2026-11-12T10:00:00.000Z', 'message' => 'call me'],
        ['id' => 'act_fx1', 'type' => 'emailsSent', 'createdAt' => '2026-11-11T08:10:59.304Z', 'subject' => 'A permanent Data Engineer', 'message' => '<p>Hi Bart,</p>', 'leadId' => 'lea_fx01'],
    ];
    [$d] = t_desk([], 'jp@x.test', $m);
    $t = $d->thread('ctc_fxTheirTurn2');
    eq(['emailsSent', 'smsReplied', 'whatsappReplied', 'emailsReplied', 'linkedinSent', 'linkedinReplied'], array_column($t['messages'], 'type'), 'oldest first');
    eq([true, false, false, false, true, false], array_column($t['messages'], 'out'));
    eq(['Email', 'SMS', 'WhatsApp', 'Email', 'LinkedIn', 'LinkedIn'], array_column($t['messages'], 'channel'));
    foreach (['emailsReplied', 'linkedinReplied', 'whatsappReplied', 'smsReplied'] as $type) eq(false, CallDesk::isOutgoing(['type' => $type, 'sendUserId' => 'usr_x']), "$type is incoming");
    foreach (['emailsSent', 'linkedinSent', 'linkedinInviteDone'] as $type) eq(true, CallDesk::isOutgoing(['type' => $type]), "$type is outgoing");
    // opening it caches the lead's last reply (not our message) for the list
    eq('for now my team is complete so no openings in the foreseeable future', array_column($d->inbox('all')['conversations'], null, 'contactId')['ctc_fxTheirTurn2']['snippet'],
        'lemlist\'s preview is longer here, and as new: kept');
});

test('inbox: a reply sent from Signal Desk takes the conversation out of "Needs reply" at once, until the lead writes again', function () {
    $m = new MockLemlist();
    t_inbox_fixture($m);
    [$d, $store, $mock] = t_desk([], 'jp@x.test', $m);
    $d->now = fn () => strtotime('2026-11-23T17:00:00Z');
    eq(true, $d->reply(['contactId' => 'ctc_fxTheirTurn1', 'channel' => 'email', 'message' => 'Thanks Carla, Tuesday works.', 'confirm' => true])['ok']);
    ok(!is_file($store->dir . '/' . CallDesk::UNREAD_FILE), 'the badge is counted again');
    $def = $d->inbox(); // lemlist still says isYourTurn: true (not caught up yet)
    eq(['ctc_fxTheirTurn2'], array_column($def['conversations'], 'contactId'));
    eq([1, 3], [$def['needs'], $def['badge']], 'Carla left Needs reply; she is still unread, so the badge stays 3 (Carla unread, Bart our turn, Uma unread)');
    $raw = (string) file_get_contents($store->dir . '/' . CallDesk::REPLIED_FILE);
    ok(str_contains($raw, 'ctc_fxTheirTurn1') && !str_contains($raw, 'Tuesday'), 'ids and times only');
    // Carla writes again: back in Needs reply
    $mock->rawInbox[1]['lastRepliedAt'] = '2026-11-23T18:00:00.000Z';
    $mock->rawInbox[1]['lastRepliedMessagePreview'] = 'One more question';
    $d->now = fn () => strtotime('2026-11-23T18:05:00Z');
    eq(['ctc_fxTheirTurn1', 'ctc_fxTheirTurn2'], array_column($d->inbox()['conversations'], 'contactId'));
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    ok(str_contains($js, 'x.yourTurn = false;') && str_contains($js, 'if (st.inbox.filter === "needs") st.inbox.list = (st.inbox.list || []).filter('), 'app.js drops it from the view right after sending');
});
