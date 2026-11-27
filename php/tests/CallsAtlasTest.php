<?php
// Calls panel -> Atlas (27 Nov 2026): the note becomes an Atlas person note, a call-back date an Atlas task (and a new lemlist phone task).
// Only these two Atlas writes exist (AtlasGuard), behind ATLAS_WRITES; the person is matched by email, then LinkedIn URL (read-only);
// no match / several matches = lemlist only; one side's failure never blocks or undoes the other; Save again never sends twice.
declare(strict_types=1);

use SignalDesk\AtlasClient;
use SignalDesk\AtlasGuard;
use SignalDesk\CallDesk;
use SignalDesk\ForbiddenWriteError;
use SignalDesk\LemlistGuard;
use SignalDesk\MockAtlas;
use SignalDesk\MockLemlist;

const T_ATLAS_JP = '11111111-2222-4333-8444-555555555555';
const T_ATLAS_CISO = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

/** A call desk with lemlist (mock) and Atlas (mock): JP is an Atlas user; Ciso Example (lead lea_it01, task opp_call0001) is an Atlas person. */
function t_atlas_desk(array $cfg = [], bool $person = true): array
{
    [$d, $store, $mock] = t_desk($cfg + ['ATLAS_WRITES' => 'on', 'ATLAS_BACKEND' => 'mock']);
    $atlas = new MockAtlas([]);
    $atlas->users = [['id' => T_ATLAS_JP, 'name' => 'JP', 'email' => 'jp@x.test']];
    if ($person) $atlas->addPerson(T_ATLAS_CISO, 'Ciso', 'Example', 'ciso.example@mail.example');
    $client = new AtlasClient('https://mock.atlas.test', 'mock');
    $client->transport = $atlas;
    $d->atlas = $client;
    $d->view();
    return [$d, $store, $mock, $atlas];
}

function t_save(CallDesk $d, string $attempt, array $in = []): array
{
    return $d->logCall($in + ['taskId' => 'opp_call0001', 'attempt' => t_attempt($attempt), 'answered' => true, 'status' => 'connected-neutral',
        'note' => "Hiring 2 SOC analysts in Q1\ncall back Monday", 'callbackDate' => '2026-11-16']);
}

test('call panel -> Atlas: a person note "Call {date time} · Answered · {outcome} · {campaign}" + the note, and a task "Call back {name} ({company})" due on the call-back date', function () {
    [$d, $store, $mock, $atlas] = t_atlas_desk();
    $r = t_save($d, 'a1');
    eq(true, $r['ok']);
    $when = (new DateTimeImmutable('@' . T_CALL_NOW))->setTimezone(new DateTimeZone('Europe/Brussels'))->format('Y-m-d H:i');
    eq([['PUT', '/api/v1/people/notes', ['personId' => T_ATLAS_CISO, 'note' => "Call $when · Answered · Connected neutral · IT decision makers – Belgium\nHiring 2 SOC analysts in Q1\ncall back Monday", 'ownerEmail' => 'jp@x.test']],
        ['POST', '/api/v1/tasks', ['content' => "Call back Ciso Example (Example MSSP)\nHiring 2 SOC analysts in Q1\ncall back Monday", 'dueDate' => '2026-11-16', 'ownerId' => T_ATLAS_JP, 'personId' => T_ATLAS_CISO]]],
        $atlas->writes, 'the only two Atlas writes, on the matched person, assigned to the logged-in user\'s Atlas user');
    eq(['lemlist ✓ call logged with your note, task moved to 2026-11-16 with your note (still open)', 'Atlas note ✓', 'Atlas task ✓ (due 2026-11-16)'], array_column($r['results'], 'text'));
    eq([true, true, true], array_column($r['results'], 'ok'));
    $lem = array_map(fn ($w) => $w[0] . ' ' . $w[1], t_lem_writes($mock, 0));
    ok(in_array('PATCH /tasks', $lem, true) && !in_array('POST /tasks', $lem, true), 'lemlist: the task moves, no new task');
    // Save clicked twice (same attempt): nothing is sent twice, anywhere
    $nLem = count($mock->calls);
    $r2 = t_save($d, 'a1');
    eq(2, count($atlas->writes), 'no second Atlas note or task');
    eq([], array_values(array_filter(t_lem_writes($mock, $nLem), fn ($w) => $w[1] === '/calls')), 'no second lemlist call');
    eq(true, $r2['ok']);
    eq(['Atlas note ✓ (already added)', 'Atlas task ✓ (already added)'], array_slice(array_column($r2['results'], 'text'), 1), 'the second click sends nothing new to Atlas');
    // the audit log: ids and endpoints only, never the note text
    $log = json_encode($GLOBALS['__events']);
    ok(str_contains($log, '"endpoint":"PUT \/api\/v1\/people\/notes"') && !str_contains($log, 'SOC analysts'), 'no note text in the audit log');
});

test('call panel -> Atlas: person by email first, then by LinkedIn URL; no note and no date = nothing in Atlas', function () {
    [$d, $store, $mock, $atlas] = t_atlas_desk([], false);
    $atlas->addPerson(T_ATLAS_CISO, 'Ciso', 'Example', null, 'https://www.linkedin.com/in/example-ciso');
    $r = t_save($d, 'li', ['callbackDate' => '']);
    eq([['PUT', '/api/v1/people/notes']], array_map(fn ($w) => [$w[0], $w[1]], $atlas->writes), 'found by LinkedIn URL; a note but no task without a date');
    eq(['emails:1'], array_slice($atlas->searches, 0, 1), 'email looked up first');
    eq('Atlas note ✓', $r['results'][1]['text']);
    [$d2, , , $atlas2] = t_atlas_desk();
    $r = $d2->logCall(['taskId' => 'opp_call0002', 'attempt' => t_attempt('none'), 'answered' => false, 'status' => 'left-voicemail', 'note' => '']);
    eq([[], 1], [$atlas2->writes, count($r['results'])], 'empty note, no date: lemlist only, no Atlas line');
});

test('call panel -> Atlas: no match or several matches = lemlist only, "Not found in Atlas (note saved in lemlist only)"; never creates people', function () {
    [$d, $store, $mock, $atlas] = t_atlas_desk([], false);
    $r = t_save($d, 'nm');
    eq(true, $r['ok']);
    eq([], $atlas->writes);
    eq(['lemlist', 'atlas'], array_column($r['results'], 'dest'));
    eq('Not found in Atlas (note saved in lemlist only)', $r['results'][1]['text']);
    [$d2, , , $atlas2] = t_atlas_desk();
    $atlas2->addPerson('aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff', 'Ciso', 'Twin', 'ciso.example@mail.example');
    $r = t_save($d2, 'two');
    eq([], $atlas2->writes, 'two people with this email: none is guessed');
    eq('Not found in Atlas (note saved in lemlist only) (2 people match)', $r['results'][1]['text']);
    eq(true, $r['results'][0]['ok'], 'lemlist still saved');
});

test('call panel -> Atlas: ATLAS_WRITES off = Atlas stays read-only (no write, no lookup); lemlist saves as usual', function () {
    [$d, $store, $mock, $atlas] = t_atlas_desk(['ATLAS_WRITES' => 'off']);
    $calls = $atlas->calls;
    $r = t_save($d, 'off');
    eq([true, [], $calls], [$r['ok'], $atlas->writes, $atlas->calls]);
    eq('Atlas: not sent (ATLAS_WRITES is off in config.php)', $r['results'][1]['text']);
    // lemlist preview mode: nothing anywhere, the lines say what would happen
    [$d2, , $mock2, $atlas2] = t_atlas_desk(['LEMLIST_WRITES' => 'off']);
    $from = count($mock2->calls);
    $p = t_save($d2, 'prev');
    eq([true, [], []], [$p['preview'], $atlas2->writes, t_lem_writes($mock2, $from)]);
    eq(['Atlas note: would be added (preview)', 'Atlas task: would be added for 2026-11-16 (preview)'], array_column($p['results'], 'text'));
    eq(['POST /calls', 'PATCH /tasks'], array_column($p['wouldSend'], 'request'), 'the preview: the call, and the task moving to the call-back date');
});

test('call panel -> Atlas: an Atlas error does not block lemlist; a read-only key says so; a lemlist error does not block Atlas, and Save again finishes lemlist only', function () {
    [$d, $store, $mock, $atlas] = t_atlas_desk();
    $atlas->failWrites = 500;
    $r = t_save($d, 'ae');
    eq(true, $r['ok'], 'lemlist saved');
    eq([true, false, false], array_column($r['results'], 'ok'));
    ok(str_starts_with($r['results'][1]['text'], 'Atlas note: Atlas error 500'), $r['results'][1]['text']);
    eq(['upcoming', '2026-11-16T08:00:00.000Z'], [$store->one("SELECT status FROM tasks WHERE id = 'opp_call0001'"), $store->one("SELECT due_date FROM tasks WHERE id = 'opp_call0001'")], 'lemlist side not undone');
    [$d2, , , $atlas2] = t_atlas_desk();
    $atlas2->readOnly = true;
    $r = t_save($d2, 'ro');
    ok(str_contains($r['results'][1]['text'], 'cannot write') && str_contains($r['results'][1]['text'], 'read-write scope'), 'a read-only Atlas key is reported, not worked around');
    // lemlist fails (POST /calls 500), Atlas still gets the note and task
    [$d3, $store3, $mock3, $atlas3] = t_atlas_desk();
    $client = (fn () => $this->client)->call($d3);
    $fail = true;
    $client->transport = function ($m, $u, $h, $b) use ($mock3, &$fail) {
        if ($m === 'POST' && str_ends_with(parse_url($u, PHP_URL_PATH), '/calls') && $fail) return ['status' => 500, 'headers' => [], 'body' => '{"error":"boom"}'];
        return $mock3($m, $u, $h, $b);
    };
    $r = t_save($d3, 'le');
    eq(false, $r['ok']);
    eq([false, true, true], array_column($r['results'], 'ok'), 'lemlist error, Atlas note + task saved');
    ok(str_contains($r['message'], 'Save again'));
    eq(2, count($atlas3->writes));
    $fail = false;
    $r = t_save($d3, 'le'); // Save again: lemlist now works; Atlas is not written twice
    eq(true, $r['ok']);
    eq(2, count($atlas3->writes));
    eq(['Atlas note ✓ (already added)', 'Atlas task ✓ (already added)'], array_slice(array_column($r['results'], 'text'), 1));
    eq(1, count(array_filter($mock3->callLog, fn ($c) => str_contains($c['externalId'], 'call0001'))), 'one call in lemlist');
    eq('upcoming', $store3->one("SELECT status FROM tasks WHERE id = 'opp_call0001'"), 'the task waits on the call-back');
});

test('call panel -> Atlas: no Atlas user with the login email = nothing in Atlas (said so); the guards allow exactly the two Atlas writes, and in lemlist only moving a task with a note', function () {
    [$d, $store, $mock, $atlas] = t_atlas_desk();
    $atlas->users = [['id' => T_ATLAS_JP, 'name' => 'Someone', 'email' => 'someone.else@x.test']];
    $r = t_save($d, 'nu');
    eq([true, [], false], [$r['ok'], $atlas->writes, $r['results'][1]['ok']]);
    ok(str_contains($r['results'][1]['text'], 'no Atlas user with your login email'));
    // Atlas guard: only PUT notes {personId, note, ownerEmail} and POST tasks {content, dueDate, ownerId, personId}
    $note = ['personId' => T_ATLAS_CISO, 'note' => 'x', 'ownerEmail' => 'jp@x.test'];
    $task = ['content' => 'x', 'dueDate' => '2026-11-16', 'ownerId' => T_ATLAS_JP, 'personId' => T_ATLAS_CISO];
    eq('PUT /api/v1/people/notes', AtlasGuard::assertWrite('PUT', '/api/v1/people/notes', $note));
    eq('POST /api/v1/tasks', AtlasGuard::assertWrite('POST', '/api/v1/tasks', $task));
    foreach ([['PATCH', '/api/v1/people/notes/' . T_ATLAS_CISO, ['note' => 'x']], ['DELETE', '/api/v1/people/notes/' . T_ATLAS_CISO, []], ['PATCH', '/api/v1/tasks/' . T_ATLAS_CISO, ['done' => true]],
        ['POST', '/api/v1/people', ['firstName' => 'x']], ['POST', '/api/v1/lists/' . T_ATLAS_CISO . '/members', ['personId' => T_ATLAS_CISO]], ['POST', '/api/v1/tasks/batch', [$task]],
        ['PUT', '/api/v1/people/notes', $note + ['projectId' => T_ATLAS_CISO]], ['PUT', '/api/v1/people/notes', ['email' => 'a@b.c', 'note' => 'x', 'ownerEmail' => 'jp@x.test']],
        ['POST', '/api/v1/tasks', ['dueDate' => '2026-11-16T09:00:00Z'] + $task], ['POST', '/api/v1/tasks', $task + ['tagIds' => []]]] as [$m, $path, $body]) {
        throws(fn () => AtlasGuard::assertWrite($m, $path, $body), ForbiddenWriteError::class);
    }
    throws(fn () => AtlasGuard::assert('PUT', '/api/v1/people/notes'), ForbiddenWriteError::class, 'read-only');
    // lemlist guard: no task creation; PATCH /tasks = done:true, or a due date with an optional note (nothing else)
    throws(fn () => LemlistGuard::assert('POST', '/tasks', ['recordId' => 'lea_it01', 'type' => 'phone', 'assignedTo' => 'usr_abc123', 'dueDate' => '2026-11-16T08:00:00.000Z']), ForbiddenWriteError::class);
    eq('PATCH /tasks', LemlistGuard::assert('PATCH', '/tasks', ['id' => 'opp_x123', 'dueDate' => '2026-11-16T08:00:00.000Z', 'message' => 'call Monday']));
    eq('PATCH /tasks', LemlistGuard::assert('PATCH', '/tasks', ['id' => 'opp_x123', 'dueDate' => '2026-11-16T08:00:00.000Z']));
    foreach ([['id' => 'opp_x123', 'message' => 'only a note'], ['id' => 'opp_x123', 'dueDate' => '2026-11-16T08:00:00.000Z', 'message' => ''], ['id' => 'opp_x123', 'dueDate' => '2026-11-16T08:00:00.000Z', 'title' => 'x'],
        ['id' => 'opp_x123', 'done' => true, 'message' => 'x'], ['id' => 'opp_x123', 'dueDate' => '2026-11-16T08:00:00.000Z', 'assignedTo' => 'usr_x']] as $bad) {
        throws(fn () => LemlistGuard::assert('PATCH', '/tasks', $bad), ForbiddenWriteError::class);
    }
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
    ok(str_contains($js, 'if (!st.res || st.busy || ro) save.disabled = true;') && str_contains($js, 'st.lastSave = { name: cur.name, results: r.results }'), 'Save disabled while saving; one line per destination');
});
