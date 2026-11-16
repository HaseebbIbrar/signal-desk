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
    /** Calls tab: logged calls by externalId, inbox conversations by contactId, labels, sent replies. */
    public array $callLog = [];
    public array $inbox = [];
    public array $labels = [];
    public array $sent = [];
    public bool $returnLabels = true;   // whether GET /inbox includes each conversation's label ids
    private ?string $stateFile = null;
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
                    ['_id' => 'stp_it_3', 'type' => 'phone', 'index' => 4, 'sequenceStep' => 3, 'delay' => 2, 'title' => 'Call {{phone}}', 'message' => '{{Touch 1 Call}}'],
                    ['_id' => 'stp_it_4', 'type' => 'manual', 'index' => 5, 'sequenceStep' => 4, 'delay' => 3, 'title' => 'Calling Again {{phone}}', 'message' => '{{Call-again}}'],
                    ['_id' => 'stp_it_5', 'type' => 'email', 'index' => 6, 'sequenceStep' => 5, 'delay' => 4, 'subject' => 'Re: Your {{jobTitle}} search', 'message' => '<p>Last note from me, {{firstName}}.</p>'],
                ]],
                'seq_it_acc' => ['_id' => 'seq_it_acc', 'steps' => [['_id' => 'stp_it_acc_0', 'type' => 'linkedinSend', 'index' => 1, 'sequenceStep' => 0, 'delay' => 0, 'message' => 'Thanks for connecting, {{firstName}}!']]],
                'seq_it_else' => ['_id' => 'seq_it_else', 'steps' => [['_id' => 'stp_it_else_0', 'type' => 'email', 'index' => 1, 'sequenceStep' => 0, 'delay' => 0, 'subject' => 'Quick follow-up', 'message' => '<p>Following up.</p>'],
                    ['_id' => 'stp_it_else_1', 'type' => 'manual', 'index' => 2, 'sequenceStep' => 1, 'delay' => 1, 'title' => 'Voice mail{{phone}}', 'message' => '<p>{{Touch 2 mail}}</p>'],
                    ['_id' => 'stp_it_else_2', 'type' => 'manual', 'index' => 3, 'sequenceStep' => 2, 'delay' => 2, 'title' => 'Final Call {{phone}}', 'message' => '{{Final call}}']]],
            ],
            'cam_hr000001' => [
                'seq_hr_main' => ['_id' => 'seq_hr_main', 'steps' => [
                    ['_id' => 'stp_hr_0', 'type' => 'email', 'index' => 1, 'sequenceStep' => 0, 'delay' => 0, 'subject' => 'Hiring IT people?', 'message' => '<p>Hello {{firstName}}</p>'],
                    ['_id' => 'stp_hr_1', 'type' => 'linkedinInvite', 'index' => 2, 'sequenceStep' => 1, 'delay' => 1],
                    ['_id' => 'stp_hr_2', 'type' => 'linkedinSend', 'index' => 3, 'sequenceStep' => 2, 'delay' => 2, 'message' => 'Hi {{firstName}}, happy to help with your IT hiring.'],
                    ['_id' => 'stp_hr_3', 'type' => 'phone', 'index' => 4, 'sequenceStep' => 3, 'delay' => 2, 'title' => 'Call {{phone}}', 'message' => '{{Touch 1 Call}}'],
                ]],
            ],
        ];
        $lead = fn (string $id, string $cid, string $first, string $last, string $company, string $title, string $li, string $status = 'review', string $lastState = 'contacted') => [
            '_id' => $id, 'campaignId' => $cid, 'contactId' => 'ctc_' . substr($id, 4), 'firstName' => $first, 'lastName' => $last, 'companyName' => $company, 'jobTitle' => $title,
            'linkedinUrl' => $li, 'email' => strtolower("$first.$last@mail.example"), 'phone' => '+32 470 00 ' . sprintf('%02d %02d', crc32($id) % 100, crc32(strrev($id)) % 100), 'lastState' => $lastState, 'status' => $status,
            'createdAt' => '2026-10-20T09:00:00.000Z'];
        $scripts = [
            'lea_it01' => ['Touch 1 Call' => "Hi {{firstName}}, Jonathan from SwooScale. I saw Example MSSP is hiring a Business Analyst.\nWe have 3 analysts in Belgium available within a month. Worth a short chat?",
                'Call-again' => 'Hi Ciso, Jonathan again: following up on the Business Analyst role.', 'Touch 2 mail' => 'Hi Ciso, Jonathan from SwooScale here. Call me back on 0470 12 34 56.',
                'Final call' => 'Last try, Ciso: shall I send two profiles?', 'Internal note' => 'not used by any step'],
            'lea_it02' => ['Touch 1 Call' => 'Hi Robin, Jonathan here.', 'Call-again' => "Hi Robin, Jonathan from SwooScale calling again.\nYou mentioned the Head of IT search: any update?",
                'Touch 2 mail' => 'Robin, Jonathan from SwooScale. I will email you two profiles.'],
            'lea_it04' => ['Touch 1 Call' => '', 'Call-again' => ''],
            'lea_it05' => ['Final call' => 'Hi Mia, final call from Jonathan about the IT Director search.'],
            'lea_hr01' => ['Touch 1 Call' => 'Hi Kim, Jonathan from SwooScale. Your HR team is hiring: can we help with IT profiles?'],
        ];
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
        foreach ($this->leads as $cid => $rows) foreach ($rows as $i => $l) $this->leads[$cid][$i] += $scripts[$l['_id']] ?? [];
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
            ['_id' => 'opp_call0001', 'type' => 'phone', 'channel' => 'aircall', 'status' => 'due', 'title' => 'Call +32 470 00 10 49', 'message' => '', 'stepId' => 'stp_it_3', 'leadId' => 'lea_it01', 'contactId' => 'ctc_it01',
                'campaignId' => $it, 'assignedTo' => self::USER, 'dueDate' => '2026-11-12T09:00:00.000Z', 'manual' => false, 'leadFirstName' => 'Ciso', 'leadLastName' => 'Example',
                'leadCompanyName' => 'Example MSSP', 'contact' => ['_id' => 'ctc_it01', 'phone' => '+32 470 11 22 33']],
            ['_id' => 'opp_man00001', 'type' => 'manual', 'channel' => 'manual', 'status' => 'due', 'title' => 'Calling Again +32 470 00 21 87', 'message' => 'Second call attempt', 'leadId' => 'lea_it02',
                'contactId' => 'ctc_it02', 'campaignId' => $it, 'assignedTo' => self::USER, 'dueDate' => '2026-11-13T10:00:00.000Z', 'manual' => true, 'leadFirstName' => 'Robin',
                'leadLastName' => 'Replier', 'leadCompanyName' => 'Replier BV'],
            ['_id' => 'opp_lis00001', 'type' => 'linkedinSend', 'channel' => 'linkedin', 'status' => 'upcoming', 'title' => 'LinkedIn message', 'message' => '', 'leadId' => 'lea_hr02',
                'contactId' => 'ctc_hr02', 'campaignId' => $hr, 'assignedTo' => self::USER, 'dueDate' => '2026-11-14T09:00:00.000Z', 'manual' => true, 'leadFirstName' => 'Cas',
                'leadLastName' => 'Connected', 'leadCompanyName' => 'Link BV'],
            ['_id' => 'opp_done0001', 'type' => 'email', 'channel' => 'email', 'status' => 'done', 'title' => 'Done task', 'leadId' => 'lea_it03', 'campaignId' => $it, 'assignedTo' => self::USER, 'dueDate' => '2026-11-01T09:00:00.000Z'],
        ];
        $task = fn (string $id, string $type, string $status, string $title, string $lead, string $cid, string $due, bool $manual, string $first, string $last, string $co, string $msg = '') => [
            '_id' => $id, 'type' => $type, 'channel' => $type === 'phone' ? 'aircall' : ($type === 'manual' ? 'manual' : (str_starts_with($type, 'linkedin') ? 'linkedin' : 'email')),
            'status' => $status, 'title' => $title, 'message' => $msg, 'leadId' => $lead, 'contactId' => 'ctc_' . substr($lead, 4), 'campaignId' => $cid, 'assignedTo' => self::USER,
            'dueDate' => $due, 'manual' => $manual, 'leadFirstName' => $first, 'leadLastName' => $last, 'leadCompanyName' => $co];
        $this->tasks[] = $task('opp_call0002', 'phone', 'due', 'Call Kim', 'lea_hr01', $hr, '2026-11-10T09:00:00.000Z', false, 'Kim', 'Example', 'Sample MSSP BV');
        $this->tasks[] = $task('opp_call0003', 'phone', 'upcoming', 'Call Nel', 'lea_it04', $it, '2026-12-01T13:00:00.000Z', false, 'Nel', 'New', 'Fresh BV');
        $this->tasks[] = $task('opp_fin00001', 'manual', 'due', 'Final call: Mia Meeting', 'lea_it05', $it, '2026-11-11T09:00:00.000Z', true, 'Mia', 'Meeting', 'Meet NV');
        $this->tasks[] = $task('opp_vml00001', 'manual', 'upcoming', 'Voice mail +32 470 00 21 87', 'lea_it02', $it, '2026-11-20T09:00:00.000Z', true, 'Robin', 'Replier', 'Replier BV');
        $this->tasks[] = $task('opp_eml00001', 'email', 'due', 'Review & send email (step 6)', 'lea_it01', $it, '2026-11-12T15:00:00.000Z', true, 'Ciso', 'Example', 'Example MSSP');
        $this->tasks[] = $task('opp_ooo00001', 'manual', 'due', 'Out of office detected: check return date', 'lea_it07', $it, '2026-11-12T08:00:00.000Z', true, 'Pat', 'Paused', 'Hold NV');
        $this->tasks[] = $task('opp_vis00001', 'manual', 'upcoming', 'Visit LinkedIn profile', 'lea_hr03', $hr, '2026-12-02T09:00:00.000Z', true, 'Fin', 'Ished', 'Done NV');
        // inbox: label ids, conversations (contactId => thread)
        $this->labels = [['_id' => 'lbl_interest1', 'name' => 'Interested', 'color' => '#22c55e'], ['_id' => 'lbl_vip000001', 'name' => 'VIP', 'color' => '#f59e0b']];
        $msg = fn (string $type, string $at, string $text, ?string $subject = null) => ['_id' => 'act_m' . substr(md5($type . $at), 0, 10), 'type' => $type, 'createdAt' => $at,
            'message' => $text, 'subject' => $subject] + (str_ends_with($type, 'Replied') ? [] : ['sendUserId' => self::USER, 'sendUserName' => 'Jonathan Perkins']);
        $conv = function (string $ctc, string $lead, string $name, array $channels, bool $read, array $labels, array $msgs) {
            $last = end($msgs);
            $this->inbox[$ctc] = ['_id' => 'ibx_' . substr($ctc, 4), 'contactId' => $ctc, 'leadId' => $lead, 'channels' => $channels, 'lastActivityAt' => $last['createdAt'],
                'lastRepliedAt' => $last['createdAt'], 'lastRepliedChannel' => $channels[0], 'users' => [['userId' => self::USER, 'sender' => true, 'read' => $read]],
                'contact' => ['_id' => $ctc, 'fullName' => $name], 'labels' => $labels, 'thread' => $msgs];
        };
        $conv('ctc_it02', 'lea_it02', 'Robin Replier', ['email'], false, ['lbl_vip000001'], [
            $msg('emailsSent', '2026-11-02T08:05:00.000Z', '<p>Hi Robin, we place IT people in Belgium.</p>', 'Your Head of IT search'),
            $msg('emailsReplied', '2026-11-02T12:00:00.000Z', '<p>Hi Jonathan, timing is good.<br>Can you send two profiles first?</p>', 'Re: Your Head of IT search')]);
        $conv('ctc_it05', 'lea_it05', 'Mia Meeting', ['email'], true, ['lbl_interest1'], [
            $msg('emailsSent', '2026-11-02T08:15:00.000Z', '<p>Hi Mia, we place IT people in Belgium.</p>', 'Your IT Director search'),
            $msg('emailsReplied', '2026-11-03T09:00:00.000Z', '<p>Yes, let us talk next week.</p>')]);
        $conv('ctc_hr02', 'lea_hr02', 'Cas Connected', ['linkedin'], false, [], [
            $msg('linkedinSent', '2026-11-07T18:05:00.000Z', 'Thanks for connecting, Cas!'),
            $msg('linkedinReplied', '2026-11-08T08:30:00.000Z', 'Interesting. Call me this week, mornings are best.')]);
        $conv('ctc_ext01', '', 'Alex Outsider', ['email'], true, [], [
            $msg('emailsReplied', '2026-11-05T10:00:00.000Z', '<p>Saw your post, do you also do contract roles?</p>')]);
    }

    private function leadById(string $id): ?array
    {
        foreach ($this->leads as $rows) foreach ($rows as $l) if ($l['_id'] === $id) return $l;
        return null;
    }

    /**
     * Inbox cases seen live: a lead in two running campaigns with no task or activity carrying its contactId (found by email),
     * and a LinkedIn conversation with no email (found by LinkedIn URL). Local demo and tests only.
     */
    public function addDemoContacts(): void
    {
        $this->campaigns[] = ['_id' => 'cam_it000002', 'name' => 'Thursday MPC 2', 'status' => 'running', 'sequenceId' => 'seq_it_main', 'createdAt' => '2026-11-01T08:00:00Z'];
        $this->sequences['cam_it000002'] = $this->sequences['cam_it000001'];
        $sam = ['firstName' => 'Sam', 'lastName' => 'Scope', 'jobTitle' => 'Manager Data & Analytics', 'linkedinUrl' => 'https://www.linkedin.com/in/sam-scope-0042',
            'email' => 'sam.scope@scopecooling.example', 'phone' => '+32 470 00 42 42', 'lastState' => 'emailsReplied', 'status' => 'paused'];
        $this->leads['cam_it000001'][] = ['_id' => 'lea_it08', 'campaignId' => 'cam_it000001', 'contactId' => 'ctc_scope0001', 'companyName' => 'Scope Cooling Europe', 'createdAt' => '2026-10-05T18:00:00.000Z'] + $sam;
        $this->leads['cam_it000002'] = [['_id' => 'lea_it28', 'campaignId' => 'cam_it000002', 'contactId' => 'ctc_scope0001', 'companyName' => 'Scope Cooling', 'createdAt' => '2026-11-02T05:00:00.000Z'] + $sam];
        $sent = ['_id' => 'act_mscope001', 'type' => 'emailsSent', 'createdAt' => '2026-11-16T09:18:56.000Z', 'subject' => 'Re: A permanent Data Architect for Scope Cooling',
            'message' => '<p>Hi Sam,</p><p>Thanks for coming back to me. I will send his CV this afternoon.</p>', 'sendUserId' => self::USER, 'sendUserName' => 'Jonathan Perkins'];
        $this->inbox['ctc_scope0001'] = ['_id' => 'ibx_scope0001', 'contactId' => 'ctc_scope0001', 'leadId' => '', 'email' => $sam['email'], 'linkedinUrl' => $sam['linkedinUrl'],
            'channels' => ['email', 'linkedin'], 'lastActivityAt' => $sent['createdAt'], 'lastRepliedChannel' => 'email', 'users' => [['userId' => self::USER, 'sender' => true, 'read' => false]],
            'contact' => ['_id' => 'ctc_scope0001', 'fullName' => 'Sam Scope'], 'labels' => [], 'thread' => [
                ['_id' => 'act_mscope000', 'type' => 'emailsReplied', 'createdAt' => '2026-11-13T10:56:58.000Z', 'subject' => 'Re: A permanent Data Architect for Scope Cooling',
                    'message' => '<p>Hi Jonathan,</p><p>Interesting profile. Is he open to a hybrid role in Oostkamp? Happy to see his CV.</p>'], $sent]];
        $this->inbox['ctc_kimli0001'] = ['_id' => 'ibx_kimli0001', 'contactId' => 'ctc_kimli0001', 'leadId' => '', 'email' => null, 'linkedinUrl' => 'https://www.linkedin.com/in/example-hr-director/',
            'channels' => ['linkedin'], 'lastActivityAt' => '2026-11-15T16:40:00.000Z', 'lastRepliedChannel' => 'linkedin', 'users' => [['userId' => self::USER, 'sender' => true, 'read' => true]],
            'contact' => ['_id' => 'ctc_kimli0001', 'fullName' => 'Kim Example'], 'labels' => [], 'thread' => [
                ['_id' => 'act_mkimli001', 'type' => 'linkedinReplied', 'createdAt' => '2026-11-15T16:40:00.000Z', 'message' => 'Thanks Jonathan. We are hiring two IT profiles in Q1, can we talk on Tuesday?']]];
    }

    /** Local mock mode only: keep tasks, calls, inbox and labels across requests in a JSON file (tests stay in memory). */
    public function persist(string $file): void
    {
        $this->addDemoContacts();
        $this->stateFile = $file;
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($d)) foreach (['tasks', 'callLog', 'inbox', 'labels', 'sent'] as $k) if (isset($d[$k]) && is_array($d[$k])) $this->$k = $d[$k];
    }

    private function save(): void
    {
        if ($this->stateFile === null) return;
        @file_put_contents($this->stateFile, json_encode(['tasks' => $this->tasks, 'callLog' => $this->callLog, 'inbox' => $this->inbox, 'labels' => $this->labels, 'sent' => array_slice($this->sent, -50)]), LOCK_EX);
    }

    private function taskIndex(string $id): ?int
    {
        foreach ($this->tasks as $i => $t) if ($t['_id'] === $id) return $i;
        return null;
    }

    /** POST /calls, PATCH /tasks, POST /tasks/ignore and the inbox, with lemlist's error codes. */
    private function callsTab(string $method, string $path, array $q, array $b, callable $ok): ?array
    {
        $err = fn (int $s, string $code, string $m) => $ok(['success' => false, 'error' => ['code' => $code, 'message' => $m]], $s);
        if ($method === 'POST' && $path === '/calls') {
            if (!$this->callsApi) return $err(402, 'PLAN', 'The team plan does not include the calls API');
            $ext = (string) ($b['externalId'] ?? '');
            if (isset($this->callLog[$ext])) return $ok(['success' => true, 'data' => $this->callLog[$ext]], 200); // replay: never twice
            if (($b['userId'] ?? '') !== self::USER) return $err(400, 'CALLS_API_USER_NOT_IN_TEAM', 'userId is not a member of the team');
            $keys = ['connected-positive', 'connected-neutral', 'connected-negative', 'voicemail', 'gatekeeper', 'busy', 'no-answer', 'wrong-number', 'not-in-service'];
            if (isset($b['callStatus']) && !in_array($b['callStatus'], $keys, true)) return $err(400, 'CALLS_UNKNOWN_CALL_STATUS', 'Unknown call status');
            $lead = $b['leadId'] ?? null;
            if (isset($b['taskId'])) {
                $i = $this->taskIndex((string) $b['taskId']);
                if ($i === null) return $err(404, 'CALLS_API_TASK_NOT_FOUND', 'Task not found');
                if ($this->tasks[$i]['type'] !== 'phone') return $err(400, 'CALLS_API_TASK_NOT_A_CALL', 'taskId is not a call task: only a call task can be completed by a call.');
                if (!in_array($this->tasks[$i]['status'], ['due', 'upcoming', 'paused'], true)) return $err(409, 'CALLS_API_TASK_ALREADY_CLOSED', 'taskId is already done');
                $this->tasks[$i]['status'] = 'done';
                $lead = $this->tasks[$i]['leadId'];
            }
            $id = 'act_call' . substr(md5($ext), 0, 10);
            $this->callLog[$ext] = ['_id' => $id, 'externalId' => $ext, 'leadId' => $lead, 'taskId' => $b['taskId'] ?? null, 'userId' => $b['userId'], 'direction' => 'outbound',
                'callStatus' => $b['callStatus'] ?? null, 'phoneProvider' => 'api', 'provider' => $b['provider'] ?? null, 'createdAt' => $b['startedAt'] ?? null];
            return $ok(['success' => true, 'data' => $this->callLog[$ext]], 201);
        }
        if ($method === 'PATCH' && $path === '/tasks') {
            $i = $this->taskIndex((string) ($b['id'] ?? ''));
            if ($i === null) return $err(404, 'NOT_FOUND', 'Task not found');
            if (!empty($b['done'])) $this->tasks[$i]['status'] = 'done';
            if (isset($b['dueDate'])) { $this->tasks[$i]['dueDate'] = $b['dueDate']; $this->tasks[$i]['status'] = 'upcoming'; }
            return $ok(['_id' => $this->tasks[$i]['_id'], 'type' => $this->tasks[$i]['type'], 'dueDate' => $this->tasks[$i]['dueDate']]);
        }
        if ($method === 'POST' && $path === '/tasks/ignore') {
            foreach ((array) ($b['ids'] ?? []) as $id) { $i = $this->taskIndex((string) $id); if ($i !== null) $this->tasks[$i]['status'] = 'ignored'; }
            return $ok(['ok' => true]);
        }
        if ($method === 'GET' && $path === '/inbox/labels') return $ok($this->labels);
        if ($method === 'POST' && $path === '/inbox/labels') {
            foreach ($this->labels as $l) if ($l['name'] === ($b['labelName'] ?? '')) return $ok(['error' => 'Label with this name already exists'], 409);
            $l = ['_id' => 'lbl_' . substr(md5((string) $b['labelName']), 0, 9), 'name' => (string) $b['labelName'], 'color' => '#888888', 'createdBy' => 'jonathan@swooscale.com'];
            $this->labels[] = $l;
            return $ok($l);
        }
        if ($method === 'GET' && $path === '/inbox') {
            if (($q['userId'] ?? '') === '') return $ok(['error' => 'User ID is required'], 400);
            $data = [];
            $first = fn (array $m) => strtok(trim(html_entity_decode(strip_tags((string) preg_replace('~<br\s*/?>|</p>~i', "\n", (string) $m['message'])))), "\n") ?: '';
            foreach ($this->inbox as $c) {
                $lead = $this->leadById((string) ($c['leadId'] ?? ''));
                $rep = array_values(array_filter($c['thread'], fn ($m) => str_ends_with($m['type'], 'Replied')));
                $snt = array_values(array_filter($c['thread'], fn ($m) => !str_ends_with($m['type'], 'Replied')));
                $row = ['_id' => $c['_id'], 'contactId' => $c['contactId'], 'contactName' => $c['contact']['fullName'] ?? '',
                    'contactEmail' => $c['email'] ?? ($lead['email'] ?? null), 'contactLinkedinUrl' => $c['linkedinUrl'] ?? ($lead['linkedinUrl'] ?? null),
                    'lastActivityAt' => $c['lastActivityAt'], 'lastRepliedChannel' => $c['lastRepliedChannel'], 'channels' => $c['channels'], 'users' => $c['users'], 'labels' => $c['labels']];
                if ($rep) $row += ['lastRepliedAt' => end($rep)['createdAt'], 'lastRepliedMessagePreview' => $first(end($rep))];
                if ($snt) $row += ['lastSentAt' => end($snt)['createdAt'], 'lastSentMessagePreview' => $first(end($snt))];
                if (!$this->returnLabels) unset($row['labels']);
                $data[] = $row;
            }
            return $ok(['data' => $data, 'pagination' => ['totalItems' => count($data), 'currentPage' => 1, 'totalPages' => 1]]);
        }
        if ($method === 'GET' && preg_match('~^/inbox/(ctc_[A-Za-z0-9]+)$~', $path, $m)) {
            if (!isset($this->inbox[$m[1]])) return $ok(['data' => [], 'pagination' => ['totalItems' => 0]]);
            if (($q['markAsRead'] ?? '') === 'true') {
                if (($q['userId'] ?? '') === '') return $ok(['error' => 'User ID is required to mark as read'], 400);
                $this->inbox[$m[1]]['users'][0]['read'] = true;
            }
            return $ok(['data' => $this->inbox[$m[1]]['thread'], 'pagination' => ['totalItems' => count($this->inbox[$m[1]]['thread'])]]);
        }
        if ($method === 'POST' && preg_match('~^/inbox/conversations/labels/(ctc_[A-Za-z0-9]+)$~', $path, $m)) {
            if (!isset($this->inbox[$m[1]])) return $ok(['error' => 'Inbox not found'], 404);
            $known = array_column($this->labels, '_id');
            foreach ((array) $b['labelIds'] as $id) if (!in_array($id, $known, true)) return $ok(['error' => 'Label not found'], 404);
            $this->inbox[$m[1]]['labels'] = $b['appendLabels'] ? array_values(array_unique(array_merge($this->inbox[$m[1]]['labels'], $b['labelIds']))) : array_values($b['labelIds']);
            return $ok(['ok' => true]);
        }
        if ($method === 'POST' && ($path === '/inbox/email' || $path === '/inbox/linkedin')) {
            $ctc = (string) ($b['contactId'] ?? '');
            if (!isset($this->inbox[$ctc])) return $ok(['error' => 'Lead not found'], 400);
            if ($path === '/inbox/email' && ($b['sendUserMailboxId'] ?? '') !== 'usm_mockmailbox1') return $ok(['error' => 'Invalid sendUserMailboxId'], 400);
            $this->sent[] = ['channel' => $path === '/inbox/email' ? 'email' : 'linkedin', 'contactId' => $ctc];
            $this->inbox[$ctc]['thread'][] = ['_id' => 'act_r' . substr(md5((string) count($this->sent)), 0, 10), 'type' => $path === '/inbox/email' ? 'emailsSent' : 'linkedinSent',
                'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'), 'message' => (string) $b['message'], 'sendUserId' => self::USER, 'sendUserName' => 'Jonathan Perkins'];
            return $ok(['ok' => true, 'contactId' => $ctc]);
        }
        return null;
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
        if ($method === 'GET' && preg_match('~^/v2/campaigns/([^/]+)/export/leads$~', $path, $m)) {
            return $ok(array_map(fn ($l) => array_diff_key($l, ['contactId' => 1]), $this->leads[$m[1]] ?? [])); // like live: no contactId in the export
        }
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
        $r = $this->callsTab($method, $path, $q, $b, $ok);
        if ($r !== null) { $this->save(); return $r; }
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
