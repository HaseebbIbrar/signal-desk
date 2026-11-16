<?php
// Fake lemlist API (same JSON shapes as the real one) for LEMLIST_BACKEND=mock and the tests. All people are fictional.
declare(strict_types=1);

namespace SignalDesk;

final class MockLemlist
{
    public const USER = 'usr_ppJJtGkzzXRXK7sxi';
    public array $calls = [];
    public bool $callsApi = true;
    public array $hooks = [];
    public array $activities = [];
    public array $tasks = [];
    public array $leads = [];
    /** @var array<int,array{status:int,headers:array,body:string}> responses to return before normal handling */
    public array $queue = [];
    private array $sequences = [];
    /** Campaign list returned by GET /campaigns (tests change statuses or add campaigns). */
    public array $campaigns = [
        ['_id' => 'cam_it000001', 'name' => 'IT decision makers – Belgium', 'status' => 'running', 'sequenceId' => 'seq_it_main', 'createdAt' => '2026-10-01T08:00:00Z'],
        ['_id' => 'cam_hr000001', 'name' => 'HR decision makers – Belgium', 'status' => 'running', 'sequenceId' => 'seq_hr_main', 'createdAt' => '2026-10-02T08:00:00Z'],
        ['_id' => 'cam_old00001', 'name' => 'Old test', 'status' => 'paused', 'sequenceId' => 'seq_old', 'createdAt' => '2026-01-02T08:00:00Z'],
    ];

    /** Test helper: a copy of a campaign's sequences and leads under a new id. */
    public function cloneCampaign(string $from, string $id, string $name, string $status = 'running'): void
    {
        $this->campaigns[] = ['_id' => $id, 'name' => $name, 'status' => $status, 'sequenceId' => 'seq_' . $id, 'createdAt' => '2026-11-01T08:00:00Z'];
        $this->sequences[$id] = $this->sequences[$from];
        $this->leads[$id] = array_map(fn ($l) => ['_id' => $l['_id'] . 'x', 'campaignId' => $id, 'contactId' => $l['contactId'] . 'x'] + $l, $this->leads[$from]);
    }

    public function __construct()
    {
        $this->sequences = [
            'cam_it000001' => [
                'seq_it_main' => ['_id' => 'seq_it_main', 'steps' => [
                    ['_id' => 'stp_it_0', 'type' => 'email', 'index' => 1, 'sequenceStep' => 0, 'delay' => 0, 'subject' => 'Your {{jobTitle}} search', 'message' => '<p>Hi {{firstName}}, we place IT people in Belgium.</p>'],
                    ['_id' => 'stp_it_1', 'type' => 'linkedinInvite', 'index' => 2, 'sequenceStep' => 1, 'delay' => 1, 'message' => ''],
                    ['_id' => 'stp_it_2', 'type' => 'conditional', 'index' => 3, 'sequenceStep' => 2, 'delay' => 2, 'conditions' => [
                        ['sequenceId' => 'seq_it_acc', 'label' => 'If LinkedIn invite accepted', 'name' => 'Accepted'], ['sequenceId' => 'seq_it_else', 'fallback' => true, 'label' => 'Else']]],
                    ['_id' => 'stp_it_3', 'type' => 'phone', 'index' => 4, 'sequenceStep' => 3, 'delay' => 2, 'title' => 'Call {{firstName}}'],
                    ['_id' => 'stp_it_4', 'type' => 'manual', 'index' => 5, 'sequenceStep' => 4, 'delay' => 3, 'title' => 'Calling again'],
                    ['_id' => 'stp_it_5', 'type' => 'email', 'index' => 6, 'sequenceStep' => 5, 'delay' => 4, 'subject' => 'Re: Your {{jobTitle}} search', 'message' => '<p>Last note from me, {{firstName}}.</p>'],
                ]],
                'seq_it_acc' => ['_id' => 'seq_it_acc', 'steps' => [['_id' => 'stp_it_acc_0', 'type' => 'linkedinSend', 'index' => 1, 'sequenceStep' => 0, 'delay' => 0, 'message' => 'Thanks for connecting, {{firstName}}!']]],
                'seq_it_else' => ['_id' => 'seq_it_else', 'steps' => [['_id' => 'stp_it_else_0', 'type' => 'email', 'index' => 1, 'sequenceStep' => 0, 'delay' => 0, 'subject' => 'Quick follow-up', 'message' => '<p>Following up.</p>']]],
            ],
            'cam_hr000001' => [
                'seq_hr_main' => ['_id' => 'seq_hr_main', 'steps' => [
                    ['_id' => 'stp_hr_0', 'type' => 'email', 'index' => 1, 'sequenceStep' => 0, 'delay' => 0, 'subject' => 'Hiring IT people?', 'message' => '<p>Hello {{firstName}}</p>'],
                    ['_id' => 'stp_hr_1', 'type' => 'linkedinInvite', 'index' => 2, 'sequenceStep' => 1, 'delay' => 1],
                    ['_id' => 'stp_hr_2', 'type' => 'linkedinSend', 'index' => 3, 'sequenceStep' => 2, 'delay' => 2, 'message' => 'Hi {{firstName}}, happy to help with your IT hiring.'],
                    ['_id' => 'stp_hr_3', 'type' => 'phone', 'index' => 4, 'sequenceStep' => 3, 'delay' => 2, 'title' => 'Call {{firstName}}'],
                ]],
            ],
        ];
        $lead = fn (string $id, string $cid, string $first, string $last, string $company, string $title, string $li, string $status = 'review', string $lastState = 'contacted') => [
            '_id' => $id, 'campaignId' => $cid, 'contactId' => 'ctc_' . substr($id, 4), 'firstName' => $first, 'lastName' => $last, 'companyName' => $company, 'jobTitle' => $title,
            'linkedinUrl' => $li, 'email' => strtolower("$first.$last@mail.example"), 'phone' => '+32 470 00 0' . substr(md5($id), 0, 3), 'lastState' => $lastState, 'status' => $status,
            'createdAt' => '2026-10-20T09:00:00.000Z'];
        $this->leads = [
            'cam_it000001' => [
                $lead('lea_it01', 'cam_it000001', 'Ciso', 'Example', 'Example MSSP', 'CISO', 'https://be.linkedin.com/in/Example-CISO/'),
                $lead('lea_it02', 'cam_it000001', 'Robin', 'Replier', 'Replier BV', 'Head of IT', 'linkedin.com/in/robin-replier'),
                $lead('lea_it03', 'cam_it000001', 'Bo', 'Bounce', 'Bounce NV', 'IT Manager', 'https://www.linkedin.com/in/bo-bounce'),
                $lead('lea_it04', 'cam_it000001', 'Nel', 'New', 'Fresh BV', 'CTO', 'https://www.linkedin.com/in/nel-new', 'review', 'imported'),
                $lead('lea_it05', 'cam_it000001', 'Mia', 'Meeting', 'Meet NV', 'IT Director', 'https://www.linkedin.com/in/mia-meeting'),
                $lead('lea_it06', 'cam_it000001', 'Uma', 'Unsub', 'Stop BV', 'CIO', 'https://www.linkedin.com/in/uma-unsub'),
                $lead('lea_it07', 'cam_it000001', 'Pat', 'Paused', 'Hold NV', 'Security Manager', 'https://www.linkedin.com/in/pat-paused', 'paused', 'paused'),
            ],
            'cam_hr000001' => [
                $lead('lea_hr01', 'cam_hr000001', 'Kim', 'Example', 'Sample MSSP BV', 'HR Director', 'https://www.linkedin.com/in/example-hr-director'),
                $lead('lea_hr02', 'cam_hr000001', 'Cas', 'Connected', 'Link BV', 'HR Business Partner', 'https://www.linkedin.com/in/cas-connected'),
                $lead('lea_hr03', 'cam_hr000001', 'Fin', 'Ished', 'Done NV', 'Talent Lead', 'https://www.linkedin.com/in/fin-ished', 'done', 'contacted'),
            ],
        ];
        $n = 0;
        $act = function (string $type, string $lead, string $cid, ?string $step, int $seqStep, int $total, string $at) use (&$n) {
            $n++;
            $this->activities[] = ['_id' => sprintf('act_mock%05d', $n), 'type' => $type, 'leadId' => $lead, 'campaignId' => $cid, 'contactId' => 'ctc_' . substr($lead, 4),
                'stepId' => $step, 'sequenceStep' => $seqStep, 'totalSequenceStep' => $total, 'createdAt' => $at, 'sendUserId' => self::USER,
                'leadEmail' => 'hidden@mail.example', 'leadFirstName' => 'Hidden'];
        };
        $it = 'cam_it000001';
        $act('emailsSent', 'lea_it01', $it, 'stp_it_0', 0, 0, '2026-11-02T08:00:00Z');
        $act('linkedinInviteDone', 'lea_it01', $it, 'stp_it_1', 1, 1, '2026-11-03T08:00:00Z');
        $act('linkedinInviteAccepted', 'lea_it01', $it, 'stp_it_1', 1, 1, '2026-11-03T15:00:00Z');
        $act('conditionChosen', 'lea_it01', $it, 'stp_it_2', 2, 2, '2026-11-05T08:00:00Z');
        $act('linkedinSent', 'lea_it01', $it, 'stp_it_acc_0', 0, 2, '2026-11-05T08:01:00Z');
        $act('aircallEnded', 'lea_it01', $it, 'stp_it_3', 3, 3, '2026-11-07T10:00:00Z');
        $act('emailsSent', 'lea_it02', $it, 'stp_it_0', 0, 0, '2026-11-02T08:05:00Z');
        $act('emailsReplied', 'lea_it02', $it, 'stp_it_0', 0, 0, '2026-11-02T12:00:00Z');
        $act('emailsSent', 'lea_it03', $it, 'stp_it_0', 0, 0, '2026-11-02T08:10:00Z');
        $act('emailsBounced', 'lea_it03', $it, 'stp_it_0', 0, 0, '2026-11-02T08:11:00Z');
        $act('emailsSent', 'lea_it05', $it, 'stp_it_0', 0, 0, '2026-11-02T08:15:00Z');
        $act('emailsReplied', 'lea_it05', $it, 'stp_it_0', 0, 0, '2026-11-03T09:00:00Z');
        $act('meetingBooked', 'lea_it05', $it, null, 0, 0, '2026-11-04T09:00:00Z');
        $act('emailsSent', 'lea_it06', $it, 'stp_it_0', 0, 0, '2026-11-02T08:20:00Z');
        $act('emailsUnsubscribed', 'lea_it06', $it, 'stp_it_0', 0, 0, '2026-11-02T10:00:00Z');
        $act('emailsSent', 'lea_it07', $it, 'stp_it_0', 0, 0, '2026-11-02T08:25:00Z');
        $act('paused', 'lea_it07', $it, null, 0, 0, '2026-11-03T08:25:00Z');
        $hr = 'cam_hr000001';
        $act('emailsSent', 'lea_hr01', $hr, 'stp_hr_0', 0, 0, '2026-11-06T08:00:00Z');
        $act('linkedinInviteDone', 'lea_hr01', $hr, 'stp_hr_1', 1, 1, '2026-11-07T08:00:00Z');
        $act('emailsSent', 'lea_hr02', $hr, 'stp_hr_0', 0, 0, '2026-11-06T08:05:00Z');
        $act('linkedinInviteDone', 'lea_hr02', $hr, 'stp_hr_1', 1, 1, '2026-11-07T08:05:00Z');
        $act('linkedinInviteAccepted', 'lea_hr02', $hr, 'stp_hr_1', 1, 1, '2026-11-07T18:00:00Z');
        foreach (['stp_hr_0' => 0, 'stp_hr_1' => 1, 'stp_hr_2' => 2, 'stp_hr_3' => 3] as $sid => $i) {
            $act(['emailsSent', 'linkedinInviteDone', 'linkedinSent', 'aircallDone'][$i], 'lea_hr03', $hr, $sid, $i, $i, sprintf('2026-10-%02dT08:00:00Z', 21 + 2 * $i));
        }
        for ($i = 0; $i < 230; $i++) $act('emailsOpened', 'lea_it02', $it, 'stp_it_0', 0, 0, sprintf('2026-11-08T%02d:%02d:00Z', intdiv($i, 60) % 24, $i % 60)); // paging
        $this->tasks = [
            ['_id' => 'opp_call0001', 'type' => 'phone', 'channel' => 'aircall', 'status' => 'due', 'title' => 'Call Ciso', 'message' => '', 'leadId' => 'lea_it01', 'contactId' => 'ctc_it01',
                'campaignId' => $it, 'assignedTo' => self::USER, 'dueDate' => '2026-11-12T09:00:00.000Z', 'manual' => false, 'leadFirstName' => 'Ciso', 'leadLastName' => 'Example',
                'leadCompanyName' => 'Example MSSP', 'contact' => ['_id' => 'ctc_it01', 'phone' => '+32 470 11 22 33']],
            ['_id' => 'opp_man00001', 'type' => 'manual', 'channel' => 'manual', 'status' => 'due', 'title' => 'Calling again', 'message' => 'Second call attempt', 'leadId' => 'lea_it02',
                'contactId' => 'ctc_it02', 'campaignId' => $it, 'assignedTo' => self::USER, 'dueDate' => '2026-11-13T10:00:00.000Z', 'manual' => true, 'leadFirstName' => 'Robin',
                'leadLastName' => 'Replier', 'leadCompanyName' => 'Replier BV'],
            ['_id' => 'opp_lis00001', 'type' => 'linkedinSend', 'channel' => 'linkedin', 'status' => 'upcoming', 'title' => 'LinkedIn message', 'message' => '', 'leadId' => 'lea_hr02',
                'contactId' => 'ctc_hr02', 'campaignId' => $hr, 'assignedTo' => self::USER, 'dueDate' => '2026-11-14T09:00:00.000Z', 'manual' => true, 'leadFirstName' => 'Cas',
                'leadLastName' => 'Connected', 'leadCompanyName' => 'Link BV'],
            ['_id' => 'opp_done0001', 'type' => 'email', 'channel' => 'email', 'status' => 'done', 'title' => 'Done task', 'leadId' => 'lea_it03', 'campaignId' => $it, 'assignedTo' => self::USER, 'dueDate' => '2026-11-01T09:00:00.000Z'],
        ];
    }

    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $u = parse_url($url);
        $path = preg_replace('~^/api~', '', (string) ($u['path'] ?? ''));
        parse_str($u['query'] ?? '', $q);
        $b = json_decode((string) $body, true) ?: [];
        $this->calls[] = [$method, $path, $q, $b];
        if ($this->queue) return array_shift($this->queue);
        $ok = fn ($d, int $s = 200) => ['status' => $s, 'headers' => ['X-RateLimit-Remaining' => '19', 'Retry-After' => '2'], 'body' => json_encode($d)];
        if (!in_array('Authorization: Basic ' . base64_encode(':mock-key'), $headers, true) && !preg_grep('/^Authorization: Basic /', $headers)) return $ok(['error' => 'No API key provided'], 401);
        if ($method === 'GET' && $path === '/team') {
            return $ok(['_id' => 'tea_mock', 'name' => 'SwooScale', 'users' => [['userId' => self::USER, 'name' => 'Jonathan Perkins', 'email' => 'jonathan@swooscale.com', 'role' => 'admin']],
                'hooks' => array_values($this->hooks)]);
        }
        if ($method === 'GET' && $path === '/user/channels') return $ok(['plan' => 'Email Pro', 'email' => ['connected' => true, 'available' => true], 'linkedin' => ['connected' => true, 'available' => true]]);
        if ($method === 'GET' && $path === '/users/' . self::USER) {
            return $ok(['_id' => self::USER, 'email' => 'jonathan@swooscale.com', 'role' => 'admin', 'mailboxes' => [['_id' => 'usm_mockmailbox1', 'email' => 'jonathan@swooscale.com', 'provider' => 'microsoft', 'status' => 'CONNECTED']]]);
        }
        if ($method === 'GET' && $path === '/call-statuses') {
            if (!$this->callsApi) return $ok(['success' => false, 'error' => ['code' => 'PLAN', 'message' => 'The team plan does not include the calls API']], 402);
            $s = fn ($k, $l, $a, $p = false, $n = false) => ['key' => $k, 'label' => $l, 'callAnswered' => $a, 'callPositive' => $p, 'callNegative' => $n, 'pausesCampaigns' => $p || $n];
            return $ok(['success' => true, 'data' => [$s('connected-positive', 'Positive', true, true), $s('connected-neutral', 'Neutral', true), $s('connected-negative', 'Negative', true, false, true),
                $s('voicemail', 'Left voicemail', true), $s('gatekeeper', 'Gatekeeper', true), $s('busy', 'Busy', true),
                $s('no-answer', 'No answer', false), $s('wrong-number', 'Wrong number', false), $s('not-in-service', 'Not in service', false)]]);
        }
        if ($method === 'GET' && $path === '/campaigns') {
            $all = $this->campaigns;
            $f = array_values(array_filter($all, fn ($c) => !isset($q['status']) || $c['status'] === $q['status']));
            return $ok(['campaigns' => array_slice($f, (int) ($q['offset'] ?? 0), (int) ($q['limit'] ?? 100)), 'pagination' => ['totalRecords' => count($f)]]);
        }
        if ($method === 'GET' && preg_match('~^/campaigns/([^/]+)/sequences$~', $path, $m)) return isset($this->sequences[$m[1]]) ? $ok($this->sequences[$m[1]]) : $ok(['error' => 'Campaign not found'], 404);
        if ($method === 'GET' && preg_match('~^/v2/campaigns/([^/]+)/export/leads$~', $path, $m)) return $ok($this->leads[$m[1]] ?? []);
        if ($method === 'GET' && preg_match('~^/v2/campaigns/([^/]+)/stats$~', $path, $m)) {
            $it = $m[1] === 'cam_it000001';
            return $ok(['nbLeads' => $it ? 7 : 3, 'nbLeadsLaunched' => $it ? 6 : 3, 'nbLeadsReached' => $it ? 6 : 3, 'messagesSent' => $it ? 8 : 5, 'delivered' => $it ? 5 : 2,
                'replied' => $it ? 2 : 0, 'messagesBounced' => $it ? 1 : 0, 'invitationAccepted' => $it ? 1 : 1, 'meetingBooked' => $it ? 1 : 0,
                'perChannel' => ['email' => ['sent' => $it ? 6 : 3, 'delivered' => $it ? 5 : 2, 'replied' => $it ? 2 : 0], 'linkedin' => ['sent' => 1, 'replied' => 0]], 'steps' => []]);
        }
        if ($method === 'GET' && $path === '/activities') {
            $min = isset($q['minDate']) ? (is_numeric($q['minDate']) ? (int) $q['minDate'] : strtotime((string) $q['minDate'])) : 0;
            $f = array_values(array_filter($this->activities, fn ($a) => strtotime($a['createdAt']) >= $min && (!isset($q['leadId']) || $a['leadId'] === $q['leadId']) && (!isset($q['type']) || $a['type'] === $q['type'])));
            return $ok(array_slice($f, (int) ($q['offset'] ?? 0), min(100, (int) ($q['limit'] ?? 100))));
        }
        if ($method === 'GET' && $path === '/v2/tasks') {
            $want = ($q['status'] ?? 'pending') === 'pending' ? ['due', 'upcoming', 'paused'] : explode(',', (string) $q['status']);
            $f = array_values(array_filter($this->tasks, fn ($t) => in_array($t['status'], $want, true)));
            $limit = (int) ($q['limit'] ?? 50);
            $page = max(1, (int) ($q['page'] ?? 1));
            $items = array_map(function ($t) use ($q) { if (!str_contains((string) ($q['include'] ?? ''), 'contact')) unset($t['contact']); return $t; }, array_slice($f, ($page - 1) * $limit, $limit));
            return $ok(['results' => $items, 'total' => count($f), 'limit' => $limit, 'page' => $page]);
        }
        if ($method === 'GET' && $path === '/hooks') return $ok(array_values($this->hooks));
        if ($method === 'POST' && $path === '/hooks') {
            foreach ($this->hooks as $h) if ($h['targetUrl'] === ($b['targetUrl'] ?? '')) return $ok(['error' => 'A webhook with this targetUrl already exists'], 409);
            $id = 'hok_' . substr(md5((string) ($b['targetUrl'] ?? '')), 0, 12);
            $this->hooks[$id] = ['_id' => $id, 'targetUrl' => (string) ($b['targetUrl'] ?? ''), 'createdAt' => '2026-11-13T10:00:00Z', 'disabled' => false];
            return $ok($this->hooks[$id]);
        }
        if ($method === 'DELETE' && preg_match('~^/hooks/([^/]+)$~', $path, $m)) {
            $h = $this->hooks[$m[1]] ?? null;
            unset($this->hooks[$m[1]]);
            return $h ? $ok($h) : $ok(['error' => 'Webhook not found'], 404);
        }
        return $ok(['error' => 'Route not found'], 404);
    }
}
