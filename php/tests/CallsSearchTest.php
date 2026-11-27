<?php
// Calls tab (27 Nov 2026): the "Job advert" button (lemlist variable "Job URL" / "Source URL", else the matching signal) and the lead search
// over every synced lead (all campaigns, ended ones included), the lead card, and "Log a call" from the card (POST /calls {leadId}, no task).
declare(strict_types=1);

use SignalDesk\AtlasClient;
use SignalDesk\CallDesk;
use SignalDesk\LeadSearch;
use SignalDesk\LemlistGuard;
use SignalDesk\LemlistSync;
use SignalDesk\MockAtlas;
use SignalDesk\MockLemlist;
use SignalDesk\Sheet;

/** A desk whose database also holds an ENDED campaign ("Spring outreach"): synced while it ran, then ended in lemlist and synced again. */
function t_search_desk(array $cfg = [], string $user = 'jp@x.test'): array
{
    $mock = new MockLemlist();
    $mock->addCampaign('cam_end00001', 'Spring outreach', 'running', [
        ['_id' => 'lea_en01', 'firstName' => 'Søren', 'lastName' => 'Sørensen', 'companyName' => 'Nordisk Data ApS', 'jobTitle' => 'CTO', 'phone' => '+32 470 12 34 56',
            'email' => 's.sorensen@nordisk.example', 'Position' => 'Data Engineer', 'Touch 1 Call' => 'Hi Søren, Jonathan here.'],
        ['_id' => 'lea_en02', 'firstName' => 'Élodie', 'lastName' => 'Dupré', 'companyName' => 'Café Tech SRL', 'jobTitle' => 'IT Manager', 'phone' => '(0)4 71-98.76.54',
            'email' => 'elodie@cafetech.example'],
    ]);
    [$d, $store, , $dir, $client] = t_desk($cfg, $user, $mock);
    $mock->setCampaignStatus('cam_end00001', 'ended');
    $s = new LemlistSync($client, $store);
    $s->now = fn () => 1_794_600_000;
    eq('done', $s->run(600)['result']);
    return [$d, $store, $mock, $client];
}

function t_ids(array $r): array
{
    return array_column($r['results'], 'id');
}

test('lead search: phone formats — +32 470 12 34 56, 0032470123456, 0470123456 all find the same lead (last 9 digits; spaces, dashes, brackets, prefix ignored)', function () {
    [$d] = t_search_desk();
    foreach (['+32 470 12 34 56', '0032470123456', '0470123456', '470123456', '+32 (0)470-12.34.56', '0470/12 34 56'] as $q) eq(['lea_en01'], t_ids($d->searchLeads($q)), $q);
    eq(['lea_en02'], t_ids($d->searchLeads('0471 98 76 54')), 'a stored "(0)4 71-98.76.54"');
    eq(['lea_en02'], t_ids($d->searchLeads('+32471987654')));
    ok(in_array('lea_en01', t_ids($d->searchLeads('12 34 56')), true), 'part of a number');
    eq(['lea_it01'], t_ids($d->searchLeads('02 555 01 01')), 'a second phone (phone1) is searched too; an 8-digit landline');
    eq([], t_ids($d->searchLeads('0470123457')), 'one digit off: no match');
    eq(['full' => true, 'key' => '470123456'], LeadSearch::phoneQuery('0032 470 12 34 56'));
    eq(null, LeadSearch::phoneQuery('Søren'), 'text is not a phone query');
});

test('lead search: name search ignores accents and case (Sørensen = sorensen, Dupré = DUPRE); words can mix name, company, email; 2 characters minimum', function () {
    [$d] = t_search_desk();
    foreach (['sorensen', 'SØRENSEN', 'Sørensen', 'soren sorensen', 'nordisk', 's.sorensen@nordisk'] as $q) eq(['lea_en01'], t_ids($d->searchLeads($q)), $q);
    foreach (['dupre', 'DUPRÉ', 'elodie cafe', 'Élodie', 'cafetech.example'] as $q) eq(['lea_en02'], t_ids($d->searchLeads($q)), $q);
    eq('sorensen elodie dupre', LeadSearch::fold('Sørensen ÉLODIE Dupré'));
    eq(['q' => 'x', 'results' => [], 'total' => 0], $d->searchLeads('x'), 'one character: nothing');
    $r = $d->searchLeads('robin');
    eq([['id' => 'lea_it02', 'name' => 'Robin Replier', 'title' => 'Head of IT', 'company' => 'Replier BV', 'campaign' => 'IT decision makers – Belgium', 'campaignStatus' => 'running',
        'phone' => $r['results'][0]['phone']]], $r['results'], 'name · title · company · campaign · phone');
    ok(str_starts_with((string) $r['results'][0]['phone'], '+32 470 00'));
});

test('lead search: every lead in the local database — an ENDED campaign, a done lead, a lead with no open task — not only today\'s queue', function () {
    [$d, $store] = t_search_desk();
    eq('0', (string) $store->one("SELECT running FROM campaigns WHERE id = 'cam_end00001'"), 'the campaign ended in lemlist');
    eq(2, (int) $store->one("SELECT COUNT(*) FROM leads WHERE campaign_id = 'cam_end00001'"), 'its leads stay in the database');
    $r = $d->searchLeads('sorensen')['results'][0];
    eq(['Spring outreach', 'ended'], [$r['campaign'], $r['campaignStatus']]);
    eq(0, (int) $store->one("SELECT COUNT(*) FROM tasks WHERE lead_id = 'lea_en01'"), 'no task at all');
    eq(['lea_hr03'], t_ids($d->searchLeads('Fin Ished')), 'a done lead');
    $queue = array_column($d->view()['queue'], 'leadId');
    ok(!in_array('lea_en01', $queue, true) && !in_array('lea_hr03', $queue, true), 'neither is in the call queue');
});

test('lead card: name, title, company, email, phone, LinkedIn, campaign + status + step, ALL lead variables (Copy), the job advert, call history, signal', function () {
    [$d] = t_search_desk();
    $c = $d->leadCard('lea_it01');
    eq(['Ciso Example', 'CISO', 'Example MSSP', 'ciso.example@mail.example'], [$c['name'], $c['title'], $c['company'], $c['email']]);
    ok(str_starts_with((string) $c['tel'], 'tel:+32470'), 'Call button');
    eq('https://be.linkedin.com/in/Example-CISO/', $c['linkedin']);
    eq(['+32 2 555 01 01'], $c['otherPhones']);
    eq(['IT decision makers – Belgium', 'running'], [$c['campaign']['name'], $c['campaign']['status']]);
    ok($c['leadStatus'] !== '' && $c['step']['total'] > 0, 'lead status and current step');
    $names = array_column($c['fields'], 'name');
    foreach (['Touch 1 Call', 'Call-again', 'Touch 2 mail', 'Final call', 'Internal note', 'Job URL', 'Position', 'location', 'phone1'] as $n) ok(in_array($n, $names, true), "variable $n");
    foreach (['firstName', 'email', 'phone', 'companyName', 'jobTitle', 'linkedinUrl', '_id', 'status'] as $n) ok(!in_array($n, $names, true), "$n is shown as a field, not a variable");
    eq(['url' => 'https://www.linkedin.com/jobs/view/4400000001/', 'from' => 'lemlist variable "Job URL"'], $c['jobUrl']);
    eq([['Call ended', 'lemlist', null]], array_map(fn ($x) => [$x['outcome'], $x['source'], $x['note']], $c['calls']), 'a call made in lemlist (dialer) shows too');
    // the ended campaign's lead: no Job URL / Source URL variable and no signal = no job link (the button is greyed out)
    $e = $d->leadCard('lea_en01');
    eq([null, null, 'Spring outreach', 'ended'], [$e['jobUrl'], $e['signal'], $e['campaign']['name'], $e['campaign']['status']]);
    eq(['Touch 1 Call' => 'Hi Søren, Jonathan here.', 'Position' => 'Data Engineer'], array_column($e['fields'], 'value', 'name'));
    throws(fn () => $d->leadCard('lea_nothere1'), \SignalDesk\NotFoundError::class);
    throws(fn () => $d->leadCard('../etc'), \SignalDesk\ValidationError::class);
});

test('job advert: "Job URL" variable, else "Source URL", else the signal (decision-maker row, or same company + job title); none = greyed out', function () {
    [$d, $store] = t_search_desk();
    $lead = fn (string $id) => $store->all('SELECT * FROM leads WHERE lead_id = ?', [$id])[0];
    eq('lemlist variable "Job URL"', LeadSearch::jobUrl($store, $lead('lea_it01'))['from']);
    eq(['url' => 'https://careers.replier.example/jobs/head-of-it-123', 'from' => 'lemlist variable "Source URL"'], LeadSearch::jobUrl($store, $lead('lea_it02')));
    eq(null, LeadSearch::jobUrl($store, $lead('lea_it03')), 'no variable, no signal');
    // the Now calling panel carries it
    $q = array_column($d->view()['queue'], null, 'id');
    eq('https://www.linkedin.com/jobs/view/4400000001/', $q['opp_call0001']['context']['jobUrl']['url']);
    eq('https://careers.replier.example/jobs/head-of-it-123', $q['opp_man00001']['context']['jobUrl']['url']);
    // (b) the matching signal: same company + job title (the lead's "Position"); legal suffixes, accents, (m/f/x) and case ignored
    $dm = fn (int $row, string $co, string $title, string $url) => ['row' => $row, 'cells' => [46000, 'jp', 45990, $co, 'x.be', $title, 'Data', 'Data Eng', 'End user', 'Gent', $url, '', '', '', '', '', '']];
    $sig = fn (int $row, string $co, string $title, string $url) => ['row' => $row, 'cells' => [45990, 'Mon', $co, 'x.be', $title, 'kw', 'Data', 'End user', 'Gent', 'New', 'LinkedIn', $url, '', '', '', '', '', '']];
    $tabs = [Sheet::DECISION_MAKERS => [$dm(7, 'NORDISK DATA', 'Data Engineer (m/f/x)', 'https://jobs.example/nordisk-de')], Sheet::SIGNALS => [$sig(40, 'Café Tech', 'IT Manager', 'https://jobs.example/cafe-itm')]];
    eq(2, LeadSearch::indexSignals($store, fn ($t) => $tabs[$t] ?? []));
    eq(['url' => 'https://jobs.example/nordisk-de', 'from' => 'Signal Desk signal (Decision Makers row 7)'], LeadSearch::jobUrl($store, $lead('lea_en01')));
    $s = $d->leadCard('lea_en01')['signal'];
    eq(['NORDISK DATA', 'Data Engineer (m/f/x)', 'same company + job title'], [$s['company'], $s['jobTitle'], $s['match']], 'the original signal on the card');
    eq('https://jobs.example/cafe-itm', LeadSearch::jobUrl($store, $lead('lea_en02'))['url'], 'no Position: the lead\'s title, from the Signals tab');
    // two different adverts for the same company + title: not sure which one -> no link
    $tabs[Sheet::SIGNALS][] = $sig(41, 'Cafe Tech BV', 'IT manager', 'https://jobs.example/cafe-itm-2');
    LeadSearch::indexSignals($store, fn ($t) => $tabs[$t] ?? []);
    eq(null, LeadSearch::jobUrl($store, $lead('lea_en02')));
    // a non-URL value in "Job URL" is skipped
    $store->db->exec("UPDATE leads SET fields = '{\"Job URL\":\"see notes\"}' WHERE lead_id = 'lea_it03'");
    eq(null, LeadSearch::jobUrl($store, $lead('lea_it03')));
});

test('log a call from search, lead with NO open task: POST /calls {leadId} only (no taskId, no task completed, moved or created); history newest first with note', function () {
    [$d, $store, $mock] = t_search_desk();
    $from = count($mock->calls);
    $r = $d->logLeadCall(['leadId' => 'lea_en01', 'attempt' => t_attempt('in1'), 'answered' => true, 'status' => 'connected-neutral', 'note' => 'Called back about the Data Engineer']);
    eq(true, $r['ok']);
    $w = t_lem_writes($mock, $from);
    eq(1, count($w), 'one write');
    eq(['POST', '/calls'], [$w[0][0], $w[0][1]]);
    eq('lea_en01', $w[0][2]['leadId']);
    ok(!isset($w[0][2]['taskId']) && !isset($w[0][2]['contactId']), 'on the lead, no task');
    eq(['outbound', 'connected-neutral', 'Called back about the Data Engineer'], [$w[0][2]['direction'], $w[0][2]['callStatus'], $w[0][2]['note']]);
    ok(str_starts_with($w[0][2]['externalId'], 'sd-lead-en01-'), 'idempotency key');
    eq('lemlist ✓ call logged on the lead with your note (no task)', $r['results'][0]['text']);
    eq('POST /calls', LemlistGuard::assert('POST', '/calls', $w[0][2]), 'the existing guarded write');
    // Save again (same attempt) never logs twice
    $d->logLeadCall(['leadId' => 'lea_en01', 'attempt' => t_attempt('in1'), 'answered' => true, 'status' => 'connected-neutral', 'note' => 'Called back about the Data Engineer']);
    eq(1, count(array_filter($mock->callLog, fn ($c) => str_starts_with($c['externalId'], 'sd-lead-en01-'))), 'one call in lemlist');
    // a second call later: the card's history is newest first, with date, outcome and note
    $d->now = fn () => T_CALL_NOW + 3600;
    $r2 = $d->logLeadCall(['leadId' => 'lea_en01', 'attempt' => t_attempt('in2'), 'answered' => false, 'status' => 'left-voicemail', 'note' => '']);
    $h = $r2['card']['calls'];
    eq(['Left voicemail', 'Connected neutral'], array_column($h, 'outcome'));
    eq([null, 'Called back about the Data Engineer'], array_column($h, 'note'));
    eq([gmdate('Y-m-d\TH:i:s\Z', T_CALL_NOW + 3600), gmdate('Y-m-d\TH:i:s\Z', T_CALL_NOW)], array_column($h, 'at'));
    eq(['Spring outreach', 'Spring outreach'], array_column($h, 'campaign'));
    eq(0, (int) $store->one("SELECT COUNT(*) FROM tasks WHERE lead_id = 'lea_en01'"), 'still no task');
    // outcome must match answered; a view-only login cannot log
    throws(fn () => $d->logLeadCall(['leadId' => 'lea_en01', 'attempt' => t_attempt('in3'), 'answered' => true, 'status' => 'left-voicemail']), \SignalDesk\ValidationError::class);
    [$v] = t_search_desk([], 'someone@x.test');
    throws(fn () => $v->logLeadCall(['leadId' => 'lea_en01', 'attempt' => t_attempt('in4'), 'answered' => true, 'status' => 'busy']), \SignalDesk\AppError::class, 'View only');
});

test('log a call from search: LEMLIST_WRITES=off = preview (nothing sent); a call-back date goes to the Atlas task (lemlist has no task to move); Atlas note as in the panel', function () {
    [$d, , $mock] = t_search_desk(['LEMLIST_WRITES' => 'off']);
    $from = count($mock->calls);
    $p = $d->logLeadCall(['leadId' => 'lea_en01', 'attempt' => t_attempt('p1'), 'answered' => true, 'status' => 'busy', 'note' => 'n', 'callbackDate' => '2026-11-16']);
    eq([true, true], [$p['ok'], $p['preview']]);
    eq(['POST /calls'], array_column($p['wouldSend'], 'request'), 'only the call');
    eq([], t_lem_writes($mock, $from), 'nothing sent');
    // with Atlas writes on: the note -> Atlas person note, the call-back date -> Atlas task; lemlist gets the call only
    [$d2, , $mock2] = t_search_desk(['ATLAS_WRITES' => 'on', 'ATLAS_BACKEND' => 'mock']);
    $atlas = new MockAtlas([]);
    $atlas->users = [['id' => T_ATLAS_JP, 'name' => 'JP', 'email' => 'jp@x.test']];
    $atlas->addPerson(T_ATLAS_CISO, 'Ciso', 'Example', 'ciso.example@mail.example');
    $client = new AtlasClient('https://mock.atlas.test', 'mock');
    $client->transport = $atlas;
    $d2->atlas = $client;
    $from = count($mock2->calls);
    $r = $d2->logLeadCall(['leadId' => 'lea_it01', 'attempt' => t_attempt('cb1'), 'answered' => true, 'status' => 'busy', 'note' => 'Inbound: call back Monday', 'callbackDate' => '2026-11-16']);
    eq(['lemlist ✓ call logged on the lead with your note (no task); no lemlist task to move, the call-back date goes to Atlas', 'Atlas note ✓', 'Atlas task ✓ (due 2026-11-16)'],
        array_column($r['results'], 'text'));
    eq([['POST', '/calls']], array_map(fn ($w) => [$w[0], $w[1]], t_lem_writes($mock2, $from)), 'no PATCH /tasks, no task created');
    eq(['PUT /api/v1/people/notes', 'POST /api/v1/tasks'], array_map(fn ($x) => $x[0] . ' ' . $x[1], $atlas->writes), 'the same two Atlas writes as the panel');
    eq(T_ATLAS_CISO, $atlas->writes[1][2]['personId']);
    eq('2026-11-16', $atlas->writes[1][2]['dueDate']);
});

test('"Search lemlist" (leads not synced yet): live, read-only — an email -> GET /leads?email= with every campaign and variable; a name -> GET /v2/contacts?search=', function () {
    [$d, , $mock] = t_search_desk();
    $from = count($mock->calls);
    $r = $d->searchLemlist('s.sorensen@nordisk.example');
    eq('leads', $r['source']);
    $l = $r['results'][0];
    eq(['lea_en01', true, 'Søren Sørensen', 'Spring outreach', 'ended'], [$l['id'], $l['inDb'], $l['name'], $l['campaign']['name'], $l['campaign']['status']]);
    ok(in_array('Position', array_column($l['fields'], 'name'), true), 'variables');
    $c = $d->searchLemlist('Robin');
    eq(['contacts', 'Robin Replier', 'Head of IT'], [$c['source'], $c['results'][0]['name'], $c['results'][0]['title']]);
    eq([], t_lem_writes($mock, $from), 'reads only');
    $paths = array_map(fn ($x) => $x[1], array_slice($mock->calls, $from));
    eq(['/leads', '/v2/contacts'], $paths);
    eq('/v2/contacts', LemlistGuard::assert('GET', '/v2/contacts'), 'on the read allowlist');
    eq([], $d->searchLemlist('nobody@nowhere.example')['results'], 'not in lemlist: empty');
    eq(['lea_en01'], array_column($d->lemlistLeads('s.sorensen@nordisk.example')['results'], 'id'), 'opening a live contact: its leads by email');
    throws(fn () => $d->searchLemlist('a'), \SignalDesk\ValidationError::class);
});
