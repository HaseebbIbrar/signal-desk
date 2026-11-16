<?php
// lemlist sync (cron, resumable), the webhook receiver, linking leads to approved decision makers, and the lead logic
// (current step, status bucket, four separate channel states).
declare(strict_types=1);

namespace SignalDesk;

/** Pure rules: from a lead's activities (+ the campaign's steps and calls logged here) to level, status and channels. */
final class LeadLogic
{
    public const STEP_TYPES = ['email' => 'Email', 'linkedinInvite' => 'LinkedIn invite', 'linkedinSend' => 'LinkedIn message', 'phone' => 'Call',
        'conditional' => 'Condition', 'manual' => 'Manual', 'linkedinVisit' => 'Visit', 'linkedinVoiceNote' => 'LinkedIn voice note', 'api' => 'API',
        'whatsappMessage' => 'WhatsApp', 'sms' => 'SMS'];
    public const STATUSES = ['Not started', 'In sequence', 'Connected', 'Replied', 'Interested', 'Meeting', 'New job / deal', 'Finished, no reply', 'Paused', 'Bounced', 'Unsubscribed'];

    private const STEP_ACTIVITY = '/^(emails(Sent|Done|Ignored|Failed|Bounced)|linkedin(InviteDone|InviteFailed|Sent|SendFailed|VisitDone|VisitFailed|VisitSkipped|VoiceNoteDone|InMailSent|Done|Ignored)|aircall(Done|Ignored|Ended)|manual(Done|Ignored)|api(Done|Failed)|conditionChosen|skipped|whatsappMessageSent|smsSent)$/';

    public static function stepType(string $type): string
    {
        return self::STEP_TYPES[$type] ?? ucfirst($type);
    }

    /**
     * Current level: the latest step activity of the lead -> "Step N / total · type".
     * @param array $acts activities (type, step_id, seq_step, total_seq_step, created_at), any order
     * @param array $steps the campaign's steps (step_id, main, idx, seq_step, type); main sequence = the denominator
     */
    public static function level(array $acts, array $steps): array
    {
        $main = array_values(array_filter($steps, fn ($s) => (int) $s['main'] === 1));
        usort($main, fn ($a, $b) => $a['seq_step'] <=> $b['seq_step']);
        $total = count($main);
        $byId = [];
        foreach ($steps as $s) $byId[$s['step_id']] = $s;
        usort($acts, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
        foreach ($acts as $a) {
            if (!preg_match(self::STEP_ACTIVITY, (string) $a['type'])) continue;
            $step = $byId[$a['step_id'] ?? ''] ?? null;
            if ($step && (int) $step['main'] === 1) {
                $n = (int) $step['seq_step'] + 1;
            } else { // a branch step (or an unknown step): position on the lead's own path
                $n = (int) ($a['total_seq_step'] ?? $a['seq_step'] ?? 0) + 1;
                $step ??= ['type' => self::typeFromActivity((string) $a['type'])];
            }
            $n = $total ? min($n, max($total, $n)) : $n;
            $type = self::stepType((string) $step['type']);
            return ['n' => $n, 'total' => max($total, $n), 'type' => $type, 'label' => "Step $n / " . max($total, $n) . " · $type", 'at' => $a['created_at']];
        }
        return ['n' => 0, 'total' => $total, 'type' => '', 'label' => $total ? "Not started · $total steps" : 'Not started', 'at' => null];
    }

    private static function typeFromActivity(string $t): string
    {
        return match (true) {
            str_starts_with($t, 'emails') => 'email',
            str_starts_with($t, 'linkedinInvite') => 'linkedinInvite',
            str_starts_with($t, 'linkedinVisit') => 'linkedinVisit',
            str_starts_with($t, 'linkedin') => 'linkedinSend',
            str_starts_with($t, 'aircall') => 'phone',
            str_starts_with($t, 'manual') => 'manual',
            $t === 'conditionChosen' => 'conditional',
            default => $t,
        };
    }

    /**
     * Four SEPARATE channels.
     *   email:      not sent | sent | replied | bounced
     *   invite:     not sent | pending | accepted
     *   message:    not sent | sent | replied
     *   call:       not called | called | no answer | answered | positive | negative   ("called" = a lemlist dialer call without a known outcome)
     * @param array $calls calls logged through Signal Desk: [{call_status}] ; $statuses key => {answered, positive, negative}
     */
    public static function channels(array $acts, array $calls = [], array $statuses = []): array
    {
        $has = [];
        foreach ($acts as $a) $has[(string) $a['type']] = true;
        $any = fn (array $types) => (bool) array_intersect_key($has, array_flip($types));
        $email = $any(['emailsBounced', 'emailsFailed']) && !$any(['emailsReplied']) ? 'bounced'
            : ($any(['emailsReplied']) ? 'replied' : ($any(['emailsSent', 'emailsDone']) ? 'sent' : 'not sent'));
        $invite = $any(['linkedinInviteAccepted']) ? 'accepted' : ($any(['linkedinInviteDone']) ? 'pending' : 'not sent');
        $message = $any(['linkedinReplied', 'linkedinVoiceNoteReplied']) ? 'replied' : ($any(['linkedinSent', 'linkedinInMailSent', 'linkedinVoiceNoteDone']) ? 'sent' : 'not sent');
        $call = 'not called';
        $rank = ['not called' => 0, 'called' => 1, 'no answer' => 2, 'answered' => 3, 'negative' => 4, 'positive' => 5];
        $up = function (string $v) use (&$call, $rank) { if ($rank[$v] > $rank[$call]) $call = $v; };
        if ($any(['aircallDone', 'aircallEnded', 'aircallCreated'])) $up('called');
        if ($any(['aircallNotInterested'])) $up('negative');
        if ($any(['aircallInterested'])) $up('positive');
        foreach ($calls as $c) {
            $s = $statuses[$c['call_status'] ?? ''] ?? null;
            if (!$s) { $up('called'); continue; }
            $up($s['positive'] ? 'positive' : ($s['negative'] ? 'negative' : ($s['answered'] ? 'answered' : 'no answer')));
        }
        return ['email' => $email, 'invite' => $invite, 'message' => $message, 'call' => $call];
    }

    /**
     * Status bucket, most important first. $outcome = the app's own outcome button (Phase 2), e.g. "Meeting", "New job".
     * @param array $lead {paused, status, last_state}
     */
    public static function status(array $acts, array $lead, array $level, array $channels, ?string $outcome = null): string
    {
        $has = [];
        $latestPause = null;
        $latestResume = null;
        foreach ($acts as $a) {
            $t = (string) $a['type'];
            $has[$t] = true;
            if (in_array($t, ['paused', 'stopped'], true) && ($latestPause === null || $a['created_at'] > $latestPause)) $latestPause = $a['created_at'];
            if ($t === 'resumed' && ($latestResume === null || $a['created_at'] > $latestResume)) $latestResume = $a['created_at'];
        }
        $any = fn (array $types) => (bool) array_intersect_key($has, array_flip($types));
        if (in_array($outcome, ['New job', 'Deal', 'New client'], true)) return 'New job / deal';
        if ($outcome === 'Meeting' || $any(['meetingBooked'])) return 'Meeting';
        if ($outcome === 'Interested' || $channels['call'] === 'positive' || $any(['emailsInterested', 'linkedinInterested', 'aircallInterested', 'apiInterested', 'manualInterested'])) return 'Interested';
        if ($channels['email'] === 'replied' || $channels['message'] === 'replied' || $channels['call'] === 'answered' || $channels['call'] === 'negative'
            || $any(['whatsappReplied', 'smsReplied', 'emailsNotInterested', 'linkedinNotInterested', 'aircallNotInterested', 'manualNotInterested', 'apiNotInterested'])) return 'Replied';
        if ($any(['emailsUnsubscribed', 'entityUnsubscribed', 'variableUnsubscribed', 'apiUnsubscribed', 'manualUnsubscribed'])) return 'Unsubscribed';
        if ($channels['email'] === 'bounced') return 'Bounced';
        if (!empty($lead['paused']) || ($latestPause !== null && ($latestResume === null || $latestResume < $latestPause))) return 'Paused';
        if ($level['n'] > 0 && $level['total'] > 0 && $level['n'] >= $level['total'] && in_array((string) ($lead['status'] ?? ''), ['done', 'finished', 'ended'], true)) return 'Finished, no reply';
        if (in_array((string) ($lead['status'] ?? ''), ['done', 'finished'], true) && $level['n'] > 0) return 'Finished, no reply';
        if ($channels['invite'] === 'accepted') return 'Connected';
        if ($level['n'] > 0) return 'In sequence';
        return 'Not started';
    }
}

/** Resumable sync: team, call statuses, running campaigns (steps, leads, stats), activities since last time, open tasks. */
final class LemlistSync
{
    public const STATS_TTL = 3600;
    public const OVERLAP = 600; // re-read the last 10 minutes of activities (dedupe by _id)

    /** @var callable(): int */
    public $now;

    public function __construct(private LemlistClient $client, private LemlistStore $store)
    {
        $this->now = fn () => time();
    }

    private static function iso(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /** @return array{result:string, state:array} result: done|running|paused|busy */
    public function run(int $maxSeconds): array
    {
        $deadline = ($this->now)() + max(1, $maxSeconds);
        $st = $this->store->meta('sync_state') ?: ['phase' => 'start'];
        $result = 'running';
        try {
            while (($this->now)() < $deadline) {
                $phase = $st['phase'];
                if ($phase === 'start') {
                    $st = ['phase' => 'team', 'startedAt' => ($this->now)(), 'campaigns' => [], 'ci' => 0, 'part' => 'sequences', 'offset' => 0, 'page' => 1, 'maxAct' => null];
                    Audit::event('lemlist_sync', ['event' => 'start']);
                } elseif ($phase === 'team') {
                    $team = $this->client->get('/team', ['version' => 'v2']);
                    $users = array_map(fn ($u) => ['userId' => (string) ($u['userId'] ?? ''), 'name' => (string) ($u['name'] ?? ''), 'email' => (string) ($u['email'] ?? ''), 'role' => (string) ($u['role'] ?? '')], $team['users'] ?? []);
                    $this->store->setMeta('team_users', $users);
                    $this->store->setMeta('team_id', (string) ($team['_id'] ?? ''));
                    $this->store->setMeta('team_hooks', array_map(fn ($h) => array_intersect_key($h, array_flip(['_id', 'targetUrl', 'type', 'disabled', 'disabledReason', 'disabledAt', 'lastErrorStatus', 'createdAt'])), $team['hooks'] ?? []));
                    try {
                        $ch = $this->client->get('/user/channels');
                        $this->store->setMeta('plan', (string) ($ch['plan'] ?? ''));
                    } catch (AppError) {
                    }
                    try {
                        $cs = $this->client->get('/call-statuses');
                        $this->store->replaceCallStatuses(array_map(fn ($s) => ['key' => (string) $s['key'], 'label' => (string) ($s['label'] ?? $s['key']),
                            'answered' => (int) !empty($s['callAnswered']), 'positive' => (int) !empty($s['callPositive']), 'negative' => (int) !empty($s['callNegative']),
                            'pauses' => (int) !empty($s['pausesCampaigns'])], $cs['data'] ?? []));
                        $this->store->setMeta('calls_api', 'yes');
                    } catch (AppError $e) {
                        if ($e->errCode !== 'LEMLIST_PLAN') throw $e;
                        $this->store->setMeta('calls_api', 'no'); // 402: plan without the calls API
                    }
                    $st['phase'] = 'campaigns';
                } elseif ($phase === 'campaigns') {
                    $list = $this->client->get('/campaigns', ['version' => 'v2', 'status' => 'running', 'limit' => 100, 'offset' => $st['offset']]);
                    $items = is_array($list['campaigns'] ?? null) ? $list['campaigns'] : (array_is_list($list) ? $list : []);
                    foreach ($items as $c) {
                        if (($c['status'] ?? 'running') !== 'running') continue; // running campaigns only
                        $this->store->upsertCampaign(['id' => (string) $c['_id'], 'name' => (string) ($c['name'] ?? ''), 'status' => 'running', 'running' => 1,
                            'sequence_id' => (string) ($c['sequenceId'] ?? ''), 'created_at' => (string) ($c['createdAt'] ?? ''), 'total_steps' => null, 'updated_at' => ($this->now)()]);
                        $st['campaigns'][] = (string) $c['_id'];
                    }
                    if (count($items) >= 100) { $st['offset'] += 100; } else {
                        // campaigns no longer running: hidden from Outreach, history kept (12-month pruning); a restart brings them back
                        $ids = $st['campaigns'];
                        $sql = "UPDATE campaigns SET running = 0, status = 'stopped' WHERE running = 1" . ($ids ? ' AND id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : '');
                        $this->store->db->prepare($sql)->execute($ids);
                        $st['phase'] = 'campaign'; $st['offset'] = 0; $st['ci'] = 0; $st['part'] = 'sequences';
                    }
                } elseif ($phase === 'campaign') {
                    if ($st['ci'] >= count($st['campaigns'])) { $st['phase'] = 'activities'; $st['offset'] = 0; continue; }
                    $cid = $st['campaigns'][$st['ci']];
                    if ($st['part'] === 'sequences') {
                        $this->syncSteps($cid);
                        $st['part'] = 'leads';
                    } elseif ($st['part'] === 'leads') {
                        $this->syncLeads($cid);
                        $st['part'] = 'stats';
                    } else {
                        $this->syncStats($cid);
                        $st['ci']++;
                        $st['part'] = 'sequences';
                    }
                } elseif ($phase === 'activities') {
                    $since = (int) $this->store->meta('activities_since', ($this->now)() - 30 * 86400);
                    $page = $this->client->get('/activities', ['version' => 'v2', 'minDate' => self::iso(max(0, $since - self::OVERLAP)), 'offset' => $st['offset'], 'limit' => 100]);
                    $items = array_is_list($page) ? $page : ($page['activities'] ?? $page['data'] ?? []);
                    foreach ($items as $a) {
                        $this->store->addActivity(self::activityRow($a, 'sync'));
                        $t = strtotime((string) ($a['createdAt'] ?? '')) ?: 0;
                        if ($t > (int) ($st['maxAct'] ?? 0)) $st['maxAct'] = $t;
                    }
                    if (count($items) >= 100) { $st['offset'] += 100; } else {
                        if ($st['maxAct']) $this->store->setMeta('activities_since', $st['maxAct']);
                        $st['phase'] = 'tasks';
                        $st['page'] = 1;
                        $st['taskSeen'] = ($this->now)();
                    }
                } elseif ($phase === 'tasks') {
                    $r = $this->client->get('/v2/tasks', ['status' => 'pending', 'include' => 'contact', 'limit' => 100, 'page' => $st['page'], 'sortBy' => 'dueDate', 'sortOrder' => 'asc']);
                    foreach ($r['results'] ?? [] as $t) $this->storeTask($t, (int) $st['taskSeen']);
                    if (count($r['results'] ?? []) >= 100 && $st['page'] < 100) { $st['page']++; } else {
                        $this->store->closeUnseenTasks((int) $st['taskSeen']);
                        $st['phase'] = 'finish';
                    }
                } elseif ($phase === 'finish') {
                    $now = ($this->now)();
                    $this->store->setMeta('last_sync_at', $now);
                    if ((int) $this->store->meta('last_prune_at', 0) < $now - 86400) {
                        $this->store->prune($now);
                        $this->store->setMeta('last_prune_at', $now);
                    }
                    Audit::event('lemlist_sync', ['event' => 'done', 'campaigns' => count($st['campaigns']), 'seconds' => $now - (int) $st['startedAt']]);
                    $st = ['phase' => 'start'];
                    $result = 'done';
                    break;
                }
                $this->store->setMeta('sync_state', $st);
            }
        } catch (LemlistRateLimited $e) {
            $result = 'paused';
            Audit::event('lemlist_sync', ['event' => 'paused', 'retryAfter' => $e->retryAfter, 'phase' => $st['phase']]);
        }
        $this->store->setMeta('sync_state', $st);
        return ['result' => $result, 'state' => ['phase' => $st['phase']]];
    }

    public static function activityRow(array $a, string $source): array
    {
        return ['id' => (string) ($a['_id'] ?? ''), 'type' => (string) ($a['type'] ?? ''), 'lead_id' => isset($a['leadId']) ? (string) $a['leadId'] : null,
            'campaign_id' => isset($a['campaignId']) ? (string) $a['campaignId'] : null, 'contact_id' => isset($a['contactId']) ? (string) $a['contactId'] : null,
            'step_id' => isset($a['stepId']) ? (string) $a['stepId'] : null, 'seq_step' => isset($a['sequenceStep']) ? (int) $a['sequenceStep'] : null,
            'total_seq_step' => isset($a['totalSequenceStep']) ? (int) $a['totalSequenceStep'] : null, 'created_at' => (string) ($a['createdAt'] ?? ''),
            'send_user_id' => isset($a['sendUserId']) ? (string) $a['sendUserId'] : null, 'source' => $source];
    }

    /** Main sequence steps (the "of N") plus condition-branch steps, with the text a manual step will send. */
    private function syncSteps(string $cid): void
    {
        $camp = $this->store->all('SELECT sequence_id FROM campaigns WHERE id = ?', [$cid])[0] ?? [];
        $seqs = $this->client->get("/campaigns/$cid/sequences");
        $mainId = (string) ($camp['sequence_id'] ?? '');
        if ($mainId === '' || !isset($seqs[$mainId])) $mainId = (string) array_key_first($seqs);
        $count = 0;
        foreach ($seqs as $sid => $seq) {
            foreach ($seq['steps'] ?? [] as $s) {
                $isMain = (string) $sid === $mainId;
                if ($isMain) $count++;
                $label = (string) ($s['title'] ?? '');
                if (($s['type'] ?? '') === 'conditional') $label = implode(' / ', array_filter(array_map(fn ($c) => (string) ($c['name'] ?? $c['label'] ?? ''), $s['conditions'] ?? [])));
                $this->store->upsertStep(['step_id' => (string) $s['_id'], 'campaign_id' => $cid, 'sequence_id' => (string) $sid, 'main' => (int) $isMain,
                    'idx' => (int) ($s['index'] ?? 0), 'seq_step' => (int) ($s['sequenceStep'] ?? 0), 'type' => (string) ($s['type'] ?? ''), 'label' => mb_substr($label, 0, 200),
                    'delay' => (int) ($s['delay'] ?? 0), 'title' => mb_substr((string) ($s['subject'] ?? $s['title'] ?? ''), 0, 300), 'message' => mb_substr((string) ($s['message'] ?? ''), 0, 20000)]);
            }
        }
        $this->store->db->prepare('UPDATE campaigns SET total_steps = ? WHERE id = ?')->execute([$count, $cid]);
    }

    private function syncLeads(string $cid): void
    {
        $rows = $this->client->get("/v2/campaigns/$cid/export/leads", ['state' => 'all', 'format' => 'json']);
        foreach (array_is_list($rows) ? $rows : [] as $l) {
            $li = (string) ($l['linkedinUrl'] ?? '');
            $paused = in_array(strtolower((string) ($l['lastState'] ?? '')), ['paused'], true) || strtolower((string) ($l['status'] ?? '')) === 'paused';
            $row = ['lead_id' => (string) $l['_id'], 'campaign_id' => $cid, 'contact_id' => (string) ($l['contactId'] ?? ''),
                'first_name' => mb_substr((string) ($l['firstName'] ?? ''), 0, 120), 'last_name' => mb_substr((string) ($l['lastName'] ?? ''), 0, 120),
                'company' => mb_substr((string) ($l['companyName'] ?? ''), 0, 200), 'title' => mb_substr((string) ($l['jobTitle'] ?? ''), 0, 200),
                'linkedin' => mb_substr($li, 0, 300), 'linkedin_key' => LinkedIn::profileKey($li), 'email' => mb_substr((string) ($l['email'] ?? ''), 0, 200),
                'phone' => mb_substr((string) ($l['phone'] ?? ''), 0, 60), 'last_state' => (string) ($l['lastState'] ?? ''), 'status' => (string) ($l['status'] ?? ''),
                'paused' => (int) $paused, 'added_at' => (string) ($l['createdAt'] ?? $l['addedAt'] ?? ''), 'updated_at' => ($this->now)()];
            $this->store->upsertLead($row);
        }
    }

    private function syncStats(string $cid): void
    {
        $now = ($this->now)();
        $last = (int) $this->store->one("SELECT fetched_at FROM stats WHERE campaign_id = ? AND period = '30d'", [$cid]);
        if ($last > $now - self::STATS_TTL) return;
        $data = $this->client->get("/v2/campaigns/$cid/stats", ['startDate' => self::iso($now - 30 * 86400), 'endDate' => self::iso($now), 'channels' => '["email","linkedin","others"]']);
        $this->store->setStats($cid, '30d', $data, $now);
    }

    private function storeTask(array $t, int $seen): void
    {
        self::storeTaskRow($this->store, $t, $seen);
    }

    /** One open task from GET /v2/tasks into the tasks table (the contact's phone goes to its lead, if the lead has none). */
    public static function storeTaskRow(LemlistStore $store, array $t, int $seen): void
    {
        $contact = $t['contact'] ?? [];
        if (!empty($t['leadId']) && !empty($contact['phone'])) {
            $store->db->prepare("UPDATE leads SET phone = ? WHERE lead_id = ? AND (phone IS NULL OR phone = '')")->execute([mb_substr((string) $contact['phone'], 0, 60), (string) $t['leadId']]);
        }
        $store->upsertTask(['id' => (string) $t['_id'], 'type' => (string) ($t['type'] ?? ''), 'channel' => (string) ($t['channel'] ?? ''), 'status' => (string) ($t['status'] ?? ''),
            'campaign_id' => (string) ($t['campaignId'] ?? ''), 'lead_id' => (string) ($t['leadId'] ?? ''), 'contact_id' => (string) ($t['contactId'] ?? ''),
            'assigned_to' => (string) ($t['assignedTo'] ?? ''), 'title' => mb_substr((string) ($t['title'] ?? ''), 0, 300), 'message' => mb_substr((string) ($t['message'] ?? ''), 0, 20000),
            'due_date' => (string) ($t['dueDate'] ?? ''), 'manual' => (int) !empty($t['manual']),
            'lead_name' => mb_substr(trim(($t['leadFirstName'] ?? '') . ' ' . ($t['leadLastName'] ?? '')), 0, 200), 'lead_company' => mb_substr((string) ($t['leadCompanyName'] ?? ''), 0, 200),
            'seen_at' => $seen]);
    }
}

/** Links lemlist leads to approved decision makers (Decision Makers / HR Decision Makers tabs, read-only) by LinkedIn profile. */
final class LemlistLinker
{
    /**
     * @param callable(string $tab): array $readTab rows of a tab as Sheet::toRows() returns them ([] when the tab is missing)
     * @param callable|null $atlas fn(string $title, string $sub, string $group): array{m:?int,n:?int}|null
     * @return array{linked:int, it:int, hr:int}
     */
    public static function run(LemlistStore $store, callable $readTab, ?callable $atlas = null): array
    {
        $signals = [];
        foreach ([Sheet::DECISION_MAKERS => 'IT', Sheet::HR_DECISION_MAKERS => 'HR'] as $tab => $track) {
            foreach ($readTab($tab) as $r) {
                if ($r['row'] < 2) continue;
                $c = $r['cells'];
                foreach (LinkedIn::splitCell($c[Sheet::DMT_LINKEDIN] ?? null) as $link) {
                    $k = LinkedIn::profileKey($link);
                    if ($k === null || isset($signals[$k])) continue; // first (oldest) approval wins
                    $signals[$k] = ['signal_track' => $track, 'signal_row' => $r['row'], 'signal_company' => Sheet::display($c[3] ?? ''),
                        'signal_job_title' => Sheet::display($c[5] ?? ''), 'signal_role_group' => Sheet::display($c[6] ?? ''), 'signal_sub_group' => Sheet::display($c[7] ?? ''),
                        'signal_job_url' => trim(Sheet::display($c[Sheet::DMT_JOB_URL] ?? '')),
                        'signal_approved_at' => ExcelDate::cellToIso($c[Sheet::DMT_DATE_VERIFIED] ?? null), 'signal_date_found' => ExcelDate::cellToIso($c[2] ?? null)];
                }
            }
        }
        $out = ['linked' => 0, 'it' => 0, 'hr' => 0];
        $atlasCache = [];
        $upd = $store->db->prepare('UPDATE leads SET signal_track=?, signal_row=?, signal_company=?, signal_job_title=?, signal_role_group=?, signal_sub_group=?, signal_job_url=?, signal_approved_at=?, signal_date_found=?, atlas_pool=?, atlas_matching=? WHERE lead_id=?');
        foreach ($store->all('SELECT lead_id, linkedin_key FROM leads') as $l) {
            $s = $l['linkedin_key'] ? ($signals[$l['linkedin_key']] ?? null) : null;
            if (!$s) {
                $upd->execute([null, null, null, null, null, null, null, null, null, null, null, $l['lead_id']]);
                continue;
            }
            $ak = $s['signal_job_title'] . '|' . $s['signal_sub_group'] . '|' . $s['signal_role_group'];
            if ($atlas && !array_key_exists($ak, $atlasCache)) {
                try {
                    $atlasCache[$ak] = $atlas($s['signal_job_title'], $s['signal_sub_group'], $s['signal_role_group']);
                } catch (\Throwable) {
                    $atlasCache[$ak] = null;
                }
            }
            $a = $atlasCache[$ak] ?? null;
            $upd->execute([$s['signal_track'], $s['signal_row'], $s['signal_company'], $s['signal_job_title'], $s['signal_role_group'], $s['signal_sub_group'], $s['signal_job_url'],
                $s['signal_approved_at'], $s['signal_date_found'], $a['m'] ?? null, $a['n'] ?? null, $l['lead_id']]);
            $out['linked']++;
            $out[strtolower($s['signal_track'])]++;
        }
        $store->setMeta('last_link_at', time());
        $store->setMeta('approved_dms', count($signals));
        // approved decision makers not (yet) in any lemlist campaign: "Needs action" (no LinkedIn URLs stored, just the job)
        $inLemlist = array_flip(array_filter(array_column($store->all('SELECT linkedin_key FROM leads'), 'linkedin_key')));
        $unpushed = [];
        foreach ($signals as $k => $s) {
            if (isset($inLemlist[$k])) continue;
            $unpushed[] = ['track' => $s['signal_track'], 'row' => $s['signal_row'], 'company' => $s['signal_company'], 'jobTitle' => $s['signal_job_title'], 'approvedAt' => $s['signal_approved_at']];
        }
        usort($unpushed, fn ($a, $b) => strcmp((string) $b['approvedAt'], (string) $a['approvedAt']));
        $store->setMeta('unpushed_dms', ['count' => count($unpushed), 'items' => array_slice($unpushed, 0, 50)]);
        // the first funnel steps come from the workbook: rows per date (Signals by block date, verify tab by Date Found, approvals by Date Verified)
        $counts = ['signals' => [], 'verified' => [], 'approved' => []];
        foreach (Sheet::blocks($readTab(Sheet::SIGNALS)) as $b) if ($b['date']) $counts['signals'][$b['date']] = ($counts['signals'][$b['date']] ?? 0) + count($b['rows']);
        foreach ($readTab(Sheet::VERIFY) as $r) {
            $d = $r['row'] >= 2 && Sheet::isDataRow($r['cells']) ? ExcelDate::cellToIso($r['cells'][0] ?? null) : null;
            if ($d) $counts['verified'][$d] = ($counts['verified'][$d] ?? 0) + 1;
        }
        foreach ([Sheet::DECISION_MAKERS, Sheet::HR_DECISION_MAKERS] as $tab) foreach ($readTab($tab) as $r) {
            $d = $r['row'] >= 2 ? ExcelDate::cellToIso($r['cells'][Sheet::DMT_DATE_VERIFIED] ?? null) : null;
            if ($d) $counts['approved'][$d] = ($counts['approved'][$d] ?? 0) + 1;
        }
        $store->setMeta('workbook_counts', $counts);
        return $out;
    }
}

/** POST /hooks/lemlist: secret checked in constant time, event stored (ids and types only), answered fast. */
final class LemlistWebhook
{
    public const MAX_BODY = 512_000;

    /** @return array{0:int,1:array} [HTTP status, JSON body] */
    public static function handle(array $config, string $method, string $raw, ?LemlistStore $store, ?callable $now = null): array
    {
        $now ??= fn () => time();
        if (Lemlist::mode($config) === 'off') return [404, ['error' => 'Not found']];
        if (strtoupper($method) !== 'POST') return [405, ['error' => 'POST only']];
        $secret = (string) ($config['LEMLIST_WEBHOOK_SECRET'] ?? '');
        if ($secret === '') return [503, ['error' => 'Webhook secret not configured']];
        if (strlen($raw) > self::MAX_BODY) return [413, ['error' => 'Too large']];
        $d = json_decode($raw, true);
        $given = is_array($d) && is_string($d['secret'] ?? null) ? $d['secret'] : '';
        if (!hash_equals($secret, $given)) {
            Audit::event('lemlist_hook_rejected', ['reason' => is_array($d) ? 'bad secret' : 'not JSON']);
            return [401, ['error' => 'Unauthorized']];
        }
        if (!$store) return [503, ['error' => 'Storage unavailable']];
        $type = mb_substr((string) ($d['type'] ?? ''), 0, 60);
        $id = (string) ($d['_id'] ?? '');
        if ($id === '') $id = 'hook_' . substr(hash('sha256', $raw), 0, 32);
        $t = $now();
        $new = $store->addEvent(['id' => mb_substr($id, 0, 80), 'type' => $type, 'created_at' => (string) ($d['createdAt'] ?? gmdate('c', $t)), 'received_at' => $t,
            'lead_id' => isset($d['leadId']) ? (string) $d['leadId'] : null, 'campaign_id' => isset($d['campaignId']) ? (string) $d['campaignId'] : null,
            'contact_id' => isset($d['contactId']) ? (string) $d['contactId'] : null]);
        if ($new && str_starts_with($id, 'act_')) $store->addActivity(LemlistSync::activityRow($d + ['_id' => $id], 'hook'));
        if ($new && in_array($type, ['sendLimitReached', 'connectionIssue', 'customDomainErrors', 'lemwarmPaused', 'deliverabilityAlertTriggered'], true)) {
            $alerts = $store->meta('alerts', []) ?: [];
            $alerts[] = ['type' => $type, 'at' => $t];
            $store->setMeta('alerts', array_slice($alerts, -50));
        }
        if ($new) {
            // Calls tab: replies make the inbox reload; task events make the queue reload (and close the task here at once)
            if (preg_match('/Replied$|^linkedinInterested$|^emailsInterested$|^opportunityReplied$/', $type)) $store->setMeta('inbox_event_at', $t);
            $taskId = is_string($d['taskId'] ?? null) ? $d['taskId'] : '';
            if ($taskId !== '' || preg_match('/^(aircall|manual)(Done|Ignored)$|^taskDone$|^skipped$/', $type)) $store->setMeta('tasks_event_at', $t);
            if (preg_match('/^opp_[A-Za-z0-9]{3,60}$/', $taskId) && preg_match('/Done$|Ignored$|^skipped$|^aircallEnded$/', $type)) {
                $store->db->prepare("UPDATE tasks SET status = 'closed' WHERE id = ?")->execute([$taskId]);
            }
        }
        $store->setMeta('last_event_at', $t);
        Audit::event('lemlist_hook', ['type' => $type, 'duplicate' => !$new]);
        return [200, ['ok' => true, 'duplicate' => !$new]];
    }
}

final class Lemlist
{
    public static function mode(array $c): string
    {
        $b = strtolower((string) ($c['LEMLIST_BACKEND'] ?? ''));
        if ($b === 'mock') return 'mock';
        if ($b === 'off') return 'off';
        return ($c['LEMLIST_API_KEY'] ?? '') !== '' ? 'lemlist' : 'off';
    }

    public static function client(array $c): ?LemlistClient
    {
        $mode = self::mode($c);
        if ($mode === 'off') return null;
        $client = new LemlistClient($mode === 'mock' ? 'mock-key' : (string) $c['LEMLIST_API_KEY']);
        if ($mode === 'mock') {
            static $mock = null;
            if ($mock === null) {
                $mock = new MockLemlist();
                if (!empty($c['STORAGE_DIR']) && is_dir((string) $c['STORAGE_DIR'])) $mock->persist($c['STORAGE_DIR'] . '/lemlist-mock-state.json'); // local demo only
            }
            $client->transport = $mock;
            $client->pacer = new ReadPacer(100000);
        }
        return $client;
    }

    public static function store(array $c): ?LemlistStore
    {
        if (self::mode($c) === 'off') return null;
        return new LemlistStore($c['STORAGE_DIR'] . '/lemlist.sqlite');
    }

    /** Rows of a workbook tab for the linker (read-only; [] when the tab does not exist yet). */
    public static function tabReader(WorkbookBackend $wb): callable
    {
        return function (string $tab) use ($wb): array {
            try {
                return Sheet::toRows($wb->usedRange($tab), Sheet::DM_TAB_COLS);
            } catch (\Throwable) {
                return [];
            }
        };
    }

    /** Atlas pool size and matching titles for a job title (title index), or null without Atlas. */
    public static function atlasCounter(array $c, string $privateDir): ?callable
    {
        $atlas = Atlas::source($c, $privateDir);
        if (!$atlas) return null;
        return function (string $title, string $sub, string $group) use ($atlas): ?array {
            $s = $atlas->summary($title, $sub, $group);
            return !empty($s['none']) ? null : ['m' => $s['m'], 'n' => $s['n']];
        };
    }
}
