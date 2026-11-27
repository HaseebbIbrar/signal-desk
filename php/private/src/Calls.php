<?php
// Calls tab (SDR desk): call tasks with the call logger, manual tasks, and the lemlist inbox.
//
//   Everything acts as LEMLIST_SHARED_SENDER (Jonathan) and only for users in LEMLIST_SENDER_ALLOWED; others are view only.
//   LEMLIST_WRITES=off (default): every action is checked and answered with what WOULD be sent; nothing reaches lemlist.
//   Writes go through LemlistClient -> LemlistGuard (explicit list + body rules). Per-user hourly limits (CallDeskLimiter).
//   Audit: real Signal Desk user, action, task/lead/contact ids, status. Never message text, notes, emails or phones.
declare(strict_types=1);

namespace SignalDesk;

/** Per-user, per-bucket hourly limits in storage/lemlist-limits.json (timestamps only). */
final class CallDeskLimiter
{
    public const LIMITS = ['send' => 30, 'call' => 100, 'task' => 200, 'label' => 100, 'lookup' => 60];

    /** @var callable(): int */
    public $now;

    public function __construct(private string $file)
    {
        $this->now = fn () => time();
    }

    /** Count one action; refuse (429) when the user already did the hourly maximum. */
    public function hit(string $user, string $bucket): void
    {
        $limit = self::LIMITS[$bucket] ?? 0;
        $now = ($this->now)();
        $key = strtolower(trim($user)) . '|' . $bucket;
        $over = false;
        JsonStore::update($this->file, [], function (array $d) use ($key, $now, $limit, &$over) {
            $list = array_values(array_filter($d[$key] ?? [], fn ($t) => (int) $t > $now - 3600));
            if (count($list) >= $limit) { $over = true; $d[$key] = $list; return $d; }
            $list[] = $now;
            $d[$key] = $list;
            foreach ($d as $k => $v) if (!$v || max($v) < $now - 3600) unset($d[$k]); // keep the file small
            return $d;
        });
        if ($over) throw new AppError(429, 'RATE_LIMIT', "Limit reached: at most $limit " . ['send' => 'messages sent', 'call' => 'calls logged', 'task' => 'task changes', 'label' => 'label changes'][$bucket] . ' per hour per user. Try again later.');
    }
}

/**
 * The call outcomes Signal Desk offers (step 2 of "Now calling") and how EVERY count groups a call (today's numbers, the Dashboard,
 * lead channels, task history). This map wins over lemlist's callAnswered / callPositive / callNegative flags: "Left voicemail" is
 * not answered, even for calls logged before (counts are computed when read). Keys are lemlist's own; none is ever created.
 */
final class CallOutcomes
{
    /** key => [label, group (answered | not_answered), result (positive | negative | null), offered as a button] */
    public const MAP = [
        'busy' => ['Busy', 'answered', null, true],
        'connected-neutral' => ['Connected neutral', 'answered', null, true],
        'connected-positive' => ['Connected positive', 'answered', 'positive', true],
        'connected-negative' => ['Connected negative', 'answered', 'negative', true],
        'left-voicemail' => ['Left voicemail', 'not_answered', null, true],
        'wrong-number' => ['Wrong number', 'not_answered', null, true],
        'not-in-service' => ['Not in service', 'not_answered', null, true],
        // not offered; only so that older calls with these statuses are counted in the right group
        'connected' => ['Connected', 'answered', null, false],
        'no-answer' => ['No answer', 'not_answered', null, false],
        'gatekeeper' => ['Gatekeeper', 'not_answered', null, false],
    ];

    /**
     * Every status for counting: key => {label, answered, positive, negative, pauses, inLemlist, offered}. The map wins;
     * a lemlist status that is not in the map keeps lemlist's own flags.
     */
    public static function all(LemlistStore $store): array
    {
        $out = [];
        foreach ($store->all('SELECT * FROM call_statuses ORDER BY position') as $r) {
            $out[(string) $r['key']] = ['label' => (string) $r['label'], 'answered' => (int) $r['answered'], 'positive' => (int) $r['positive'], 'negative' => (int) $r['negative'],
                'pauses' => (int) $r['pauses'], 'inLemlist' => true, 'offered' => false];
        }
        foreach (self::MAP as $key => [$label, $group, $result, $offered]) {
            $out[$key] = ['label' => $label, 'answered' => (int) ($group === 'answered'), 'positive' => (int) ($result === 'positive'), 'negative' => (int) ($result === 'negative'),
                'pauses' => (int) ($out[$key]['pauses'] ?? 0), 'inLemlist' => isset($out[$key]), 'offered' => $offered];
        }
        return $out;
    }

    /** Step 2 buttons per step-1 answer, in the map's order: only offered keys that exist in lemlist. */
    public static function buttons(LemlistStore $store): array
    {
        $out = ['answered' => [], 'notAnswered' => []];
        $all = self::all($store);
        foreach (array_keys(self::MAP) as $key) {
            $s = $all[$key];
            if (!$s['offered'] || !$s['inLemlist']) continue;
            $out[$s['answered'] ? 'answered' : 'notAnswered'][] = ['key' => $key, 'label' => $s['label'], 'positive' => (bool) $s['positive'], 'negative' => (bool) $s['negative'], 'pauses' => (bool) $s['pauses']];
        }
        return $out;
    }
}

final class CallDesk
{
    /** Atlas (read lookups + the two allowed writes), for the call panel's note and call-back task; null = Atlas off. */
    public ?AtlasClient $atlas = null;

    public const CALL_TITLE = '/^\s*(call|calling again|final call|voice\s*mail)/i';
    public const VOICEMAIL_TITLE = '/voice\s*mail/i';
    public const MESSAGE_TYPES = ['email', 'linkedinSend', 'linkedinInvite', 'linkedinVoiceNote', 'whatsappMessage'];
    public const OPEN = ['due', 'upcoming'];
    public const REFRESH_EVERY = 60;     // tasks re-read from lemlist at most once a minute (and after every action)
    public const STATUSES_EVERY = 600;   // call statuses re-read every 10 minutes
    public const MAX_NOTE = 2000;
    public const MAX_MESSAGE = 5000;
    private const OUR_LABEL_OUTCOME = ['Interested' => 'Interested', 'Meeting' => 'Meeting', 'Not interested' => 'Not interested'];

    /** @var callable(): int */
    public $now;
    /** @var callable(int): void */
    public $sleep;
    private ?array $senderCache = null;

    public function __construct(
        private LemlistStore $store,
        private ?LemlistClient $client,
        private array $config,
        private string $user,
        private CallDeskLimiter $limiter,
        private string $tz = 'Europe/Brussels',
    ) {
        $this->now = fn () => time();
        $this->sleep = fn (int $ms) => usleep($ms * 1000);
    }

    // ------------------------------------------------------------------ who, and may we write?

    public function writesOn(): bool
    {
        return strtolower((string) ($this->config['LEMLIST_WRITES'] ?? 'off')) === 'on';
    }

    /** The lemlist user this Signal Desk user acts as, or null (view only). */
    public function sender(): ?array
    {
        if ($this->senderCache !== null) return $this->senderCache ?: null;
        $s = LemlistSender::for($this->config, $this->user, $this->store->meta('team_users', []) ?: []);
        $this->senderCache = $s ?? [];
        return $s;
    }

    private function senderOrFail(): array
    {
        return $this->sender() ?? throw new AppError(403, 'VIEW_ONLY', 'View only: your login is not in LEMLIST_SENDER_ALLOWED, so you cannot send or log calls as the lemlist user.');
    }

    private function clientOrFail(): LemlistClient
    {
        return $this->client ?? throw new AppError(503, 'LEMLIST_OFF', 'lemlist is not configured.');
    }

    private function audit(string $action, array $ids, string $status): void
    {
        Audit::event('calls_action', ['user' => $this->user, 'action' => $action] + array_filter($ids, fn ($v) => $v !== null && $v !== '') + ['status' => $status]);
    }

    /** Preview answer when LEMLIST_WRITES=off: the requests that would be sent (endpoint and field names only). */
    private function preview(string $action, array $requests, array $ids): array
    {
        foreach ($requests as [$m, $p, $b]) LemlistGuard::assert($m, $p, $b); // the guard would allow it
        $this->audit($action, $ids, 'preview');
        return ['ok' => true, 'preview' => true,
            'message' => 'Preview mode (LEMLIST_WRITES is off): nothing was sent to lemlist.',
            'wouldSend' => array_map(fn ($r) => ['request' => $r[0] . ' ' . preg_replace('~/(ctc|opp|lea)_[A-Za-z0-9]+~', '/{id}', $r[1]), 'fields' => array_keys($r[2])], $requests)];
    }

    // ------------------------------------------------------------------ tasks

    /** Which bucket a task belongs to: call (call queue + "Log call" in manual tasks), message (Send), or other (Done). */
    public static function kind(array $t): string
    {
        if ($t['type'] === 'phone') return 'phone';
        if ($t['type'] === 'manual' && preg_match(self::CALL_TITLE, (string) $t['title'])) return 'manualCall';
        if (in_array($t['type'], self::MESSAGE_TYPES, true)) return 'message';
        return 'other';
    }

    /** Type chip on the manual task list. */
    public static function manualType(array $t): string
    {
        $k = self::kind($t);
        if ($k === 'manualCall') return 'call';
        if ($t['type'] === 'email') return 'email';
        if (str_starts_with((string) $t['type'], 'linkedin')) return 'li';
        if (preg_match('/out of office/i', (string) $t['title'])) return 'ooo';
        if (preg_match('/visit/i', (string) $t['title'])) return 'visit';
        return 'task';
    }

    /** Re-read open tasks from lemlist (throttled). Returns true when it read them. */
    public function refreshTasks(bool $force = false): bool
    {
        if (!$this->client) return false;
        $now = ($this->now)();
        if (!$force && (int) $this->store->meta('tasks_refreshed_at', 0) > $now - self::REFRESH_EVERY) return false;
        for ($page = 1; $page <= 20; $page++) {
            $r = $this->client->get('/v2/tasks', ['status' => 'pending', 'include' => 'contact', 'limit' => 100, 'page' => $page, 'sortBy' => 'dueDate', 'sortOrder' => 'asc']);
            $this->store->batch($r['results'] ?? [], fn ($t) => LemlistSync::storeTaskRow($this->store, $t, $now));
            if (count($r['results'] ?? []) < 100) break;
        }
        $this->store->closeUnseenTasks($now);
        $this->store->setMeta('tasks_refreshed_at', $now);
        return true;
    }

    /** Open (due/upcoming) tasks, overdue first, with lead, campaign, step and signal. */
    private function openTasks(): array
    {
        $rows = $this->store->all("SELECT t.*, c.name AS campaign_name FROM tasks t LEFT JOIN campaigns c ON c.id = t.campaign_id WHERE t.status IN ('due','upcoming') ORDER BY t.due_date, t.id");
        $closed = array_flip($this->store->closedByHook()); // done in lemlist, the cron has not imported the event yet
        if ($closed) $rows = array_values(array_filter($rows, fn ($r) => !isset($closed[$r['id']])));
        $leadIds = array_values(array_unique(array_filter(array_column($rows, 'lead_id'))));
        $leads = $acts = $steps = [];
        if ($leadIds) {
            $in = implode(',', array_fill(0, count($leadIds), '?'));
            foreach ($this->store->all("SELECT * FROM leads WHERE lead_id IN ($in)", $leadIds) as $l) $leads[$l['lead_id']] = $l;
            foreach ($this->store->all("SELECT * FROM activities WHERE lead_id IN ($in) ORDER BY created_at", $leadIds) as $a) $acts[$a['lead_id']][] = $a;
            $cids = array_values(array_unique(array_filter(array_column($leads, 'campaign_id'))));
            if ($cids) foreach ($this->store->all('SELECT * FROM steps WHERE campaign_id IN (' . implode(',', array_fill(0, count($cids), '?')) . ')', $cids) as $s) $steps[$s['campaign_id']][] = $s;
        }
        $out = [];
        foreach ($rows as $t) {
            $l = $leads[$t['lead_id']] ?? null;
            $level = $l ? LeadLogic::level($acts[$t['lead_id']] ?? [], $steps[$l['campaign_id']] ?? []) : ['n' => 0, 'total' => 0, 'type' => '', 'label' => ''];
            $out[] = ['task' => $t, 'lead' => $l, 'level' => $level, 'acts' => $acts[$t['lead_id']] ?? [], 'steps' => $l ? ($steps[$l['campaign_id']] ?? []) : []];
        }
        return $out;
    }

    private function brief(array $x): array
    {
        $t = $x['task'];
        $l = $x['lead'];
        $name = $l ? trim($l['first_name'] . ' ' . $l['last_name']) : (string) $t['lead_name'];
        $due = (string) $t['due_date'];
        $dueTs = $due !== '' ? (int) strtotime($due) : 0;
        return ['id' => $t['id'], 'type' => $t['type'], 'kind' => self::kind($t), 'title' => $t['title'], 'status' => $t['status'], 'dueDate' => $due !== '' ? $due : null,
            'overdue' => $dueTs > 0 && $dueTs < ($this->now)() - 300 && $t['status'] === 'due', 'assignedTo' => $t['assigned_to'],
            'leadId' => $t['lead_id'] ?: null, 'contactId' => $t['contact_id'] ?: null, 'name' => $name !== '' ? $name : '(no name)',
            'jobTitle' => $l['title'] ?? '', 'company' => $l['company'] ?? (string) $t['lead_company'], 'campaign' => (string) ($t['campaign_name'] ?? ''),
            'step' => $x['level']['total'] ? ($x['level']['n'] + 1 <= $x['level']['total'] ? $x['level']['n'] + 1 : $x['level']['total']) . '/' . $x['level']['total'] : '',
            'signal' => $l && $l['signal_job_title'] ? ['track' => $l['signal_track'] ?: 'IT', 'jobTitle' => $l['signal_job_title']] : null];
    }

    /** The "Now calling" context: last email/LinkedIn events, Atlas count, linked signal, phone and LinkedIn. */
    private function context(array $x): array
    {
        $l = $x['lead'];
        $events = [];
        foreach (array_reverse($x['acts']) as $a) {
            if (!preg_match('/^(emails|linkedin)/', (string) $a['type']) || in_array($a['type'], ['emailsOpened', 'emailsClicked', 'linkedinOpened'], true)) continue;
            $events[] = ['label' => Outreach::activityLabel((string) $a['type']), 'at' => $a['created_at']];
            if (count($events) >= 3) break;
        }
        $phone = $l ? trim((string) $l['phone']) : '';
        return ['events' => $events, 'phone' => $phone !== '' ? $phone : null, 'tel' => $phone !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $phone) : null,
            'linkedin' => $l['linkedin'] ?? null, 'jobUrl' => LeadSearch::jobUrl($this->store, $l),
            'atlas' => $l && $l['atlas_pool'] !== null ? ['pool' => (int) $l['atlas_pool'], 'matching' => $l['atlas_matching'] !== null ? (int) $l['atlas_matching'] : null] : null];
    }

    /** Approximate text of a manual message step: the campaign step of the same type at the lead's next position, variables filled in. */
    private function messagePreview(array $x): ?array
    {
        $t = $x['task'];
        $l = $x['lead'];
        $cands = array_values(array_filter($x['steps'], fn ($s) => $s['type'] === $t['type'] && trim((string) $s['message'] . (string) $s['title']) !== ''));
        if (!$cands) return trim((string) $t['message']) !== '' ? ['subject' => null, 'text' => self::plain((string) $t['message'])] : null;
        usort($cands, fn ($a, $b) => [(int) $b['main'], (int) $a['seq_step']] <=> [(int) $a['main'], (int) $b['seq_step']]);
        $next = $x['level']['n'];
        $pick = null;
        foreach ($cands as $s) if ((int) $s['main'] === 1 && (int) $s['seq_step'] >= $next) { $pick = $s; break; }
        $pick ??= $cands[0];
        $values = LeadVars::values($l);
        $fill = fn (string $s) => LeadVars::fill($s, $values)[0];
        $subject = trim((string) $pick['title']) !== '' && $t['type'] === 'email' ? $fill((string) $pick['title']) : null;
        return ['subject' => $subject, 'text' => $fill(self::plain((string) $pick['message']))];
    }

    /**
     * The campaign step a task belongs to: the task's stepId, else its sequence position, else the step whose title pattern
     * ("Calling Again {{phone}}") matches the task title, else the only / next step of that type.
     */
    public static function stepFor(array $task, array $steps, int $next = 0): ?array
    {
        $byId = array_column($steps, null, 'step_id');
        if (!empty($task['step_id']) && isset($byId[$task['step_id']])) return $byId[$task['step_id']];
        $sameType = array_values(array_filter($steps, fn ($st) => $st['type'] === $task['type']));
        if (isset($task['seq_step']) && $task['seq_step'] !== null && $task['seq_step'] !== '') {
            foreach ($sameType as $st) if ((int) $st['seq_step'] === (int) $task['seq_step'] && (int) $st['main'] === 1) return $st;
        }
        $pick = function (array $c) use ($next): ?array {
            if (!$c) return null;
            usort($c, fn ($a, $b) => [(int) $b['main'], (int) $a['seq_step']] <=> [(int) $a['main'], (int) $b['seq_step']]);
            foreach ($c as $st) if ((int) $st['main'] === 1 && (int) $st['seq_step'] >= $next) return $st;
            return $c[0];
        };
        $title = trim((string) $task['title']);
        $byTitle = array_values(array_filter($sameType, fn ($st) => trim((string) $st['title']) !== '' && preg_match(self::titlePattern((string) $st['title']), $title)));
        if ($byTitle) return $pick($byTitle);
        return count($sameType) === 1 ? $sameType[0] : $pick($sameType);
    }

    /** "Calling Again {{phone}}" -> /^calling\s*again\b.*$/i (variables match anything, spacing and case are ignored). */
    private static function titlePattern(string $stepTitle): string
    {
        $parts = preg_split('/\{\{[^{}]*\}\}/', $stepTitle) ?: [$stepTitle];
        $re = implode('.*', array_map(fn ($p) => implode('\s*', array_map(fn ($w) => preg_quote($w, '/'), preg_split('/\s+/', trim($p)) ?: [])), $parts));
        return '/^\s*' . $re . '.*$/isu';
    }

    /**
     * The call script for a call task: the step's message with the lead's fields filled in (HTML stripped). When the script
     * field is empty for this lead: empty=true and the raw step text.
     * @return array{text:?string,raw:string,empty:bool,fields:array,step:?string}|null
     */
    public static function script(?array $step, ?array $lead): ?array
    {
        if (!$step || trim((string) $step['message']) === '') return null;
        $raw = self::plain((string) $step['message']);
        [$text, $missing] = LeadVars::fill($raw, LeadVars::values($lead));
        $text = trim($text);
        $onlyVars = trim((string) preg_replace('/\{\{[^{}]*\}\}/', '', $raw)) === '';
        $empty = $text === '' || ($missing && $onlyVars);
        return ['text' => $empty ? null : $text, 'raw' => $raw, 'empty' => $empty, 'fields' => LeadVars::names($raw), 'step' => trim((string) $step['title']) ?: null];
    }

    /** The lead's voicemail script (the campaign's "Voice mail" step), when it has one and it is not the current step. */
    private static function voicemail(array $steps, ?array $current, ?array $lead): ?array
    {
        foreach ($steps as $st) {
            if (!preg_match(self::VOICEMAIL_TITLE, (string) $st['title']) || ($current && $st['step_id'] === $current['step_id'])) continue;
            $sc = self::script($st, $lead);
            if ($sc && !$sc['empty']) return $sc;
        }
        return null;
    }

    /** HTML message -> plain text with line breaks. */
    public static function plain(string $html): string
    {
        $s = preg_replace('~<\s*(br|/p|/div|/li|/h[1-6])\s*/?>~i', "\n", $html) ?? $html;
        $s = preg_replace('~<(style|script)\b.*?</\1>~is', '', $s) ?? $s;
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace("/[ \t]+/", ' ', $s) ?? $s;
        $s = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $s) ?? $s;
        return trim($s);
    }

    /** Open call + manual tasks that are due (the Calls badge in the top bar). */
    public static function dueCount(LemlistStore $store): int
    {
        $closed = $store->closedByHook();
        return (int) $store->one("SELECT COUNT(*) FROM tasks WHERE status = 'due' AND (type = 'phone' OR type = 'manual' OR manual = 1)"
            . ($closed ? ' AND id NOT IN (' . implode(',', array_fill(0, count($closed), '?')) . ')' : ''), $closed);
    }

    // ------------------------------------------------------------------ call statuses

    /** Live call statuses (re-read every 10 minutes), split by "did they answer?". */
    public function statuses(bool $force = false): array
    {
        $now = ($this->now)();
        if ($this->client && ($force || (int) $this->store->meta('statuses_at', 0) < $now - self::STATUSES_EVERY) && $this->store->meta('calls_api') !== 'no') {
            try {
                $cs = $this->client->get('/call-statuses');
                $this->store->replaceCallStatuses(self::statusRows($cs['data'] ?? []));
                $this->store->setMeta('calls_api', 'yes');
            } catch (AppError $e) {
                if ($e->errCode !== 'LEMLIST_PLAN') throw $e;
                $this->store->setMeta('calls_api', 'no');
            }
            $this->store->setMeta('statuses_at', $now);
        }
        return ['callsApi' => $this->store->meta('calls_api') !== 'no'] + CallOutcomes::buttons($this->store); // step 2: only the outcomes of CallOutcomes::MAP
    }

    /** GET /call-statuses data -> call_statuses rows. */
    public static function statusRows(array $data): array
    {
        return array_map(fn ($s) => ['key' => (string) $s['key'], 'label' => (string) ($s['label'] ?? $s['key']), 'answered' => (int) !empty($s['callAnswered']),
            'positive' => (int) !empty($s['callPositive']), 'negative' => (int) !empty($s['callNegative']), 'pauses' => (int) !empty($s['pausesCampaigns'])], $data);
    }

    // ------------------------------------------------------------------ the screen

    public function view(): array
    {
        try {
            $this->refreshTasks();
        } catch (LemlistRateLimited) {
            // show what we have; the next refresh catches up
        }
        $statuses = $this->statuses();
        $queue = $manual = [];
        foreach ($this->openTasks() as $x) {
            $k = self::kind($x['task']);
            $b = $this->brief($x);
            $isCall = $k === 'phone' || $k === 'manualCall';
            $step = $isCall ? self::stepFor($x['task'], $x['steps'], (int) $x['level']['n']) : null;
            $script = $isCall ? self::script($step, $x['lead']) : null;
            if ($isCall) $queue[] = $b + ['context' => $this->context($x) + ['script' => $script, 'voicemail' => self::voicemail($x['steps'], $step, $x['lead'])]];
            if ($x['task']['type'] !== 'phone' && ($x['task']['type'] === 'manual' || (int) $x['task']['manual'] === 1)) {
                $m = $b + ['manualType' => self::manualType($x['task'])];
                if ($k === 'message') $m['preview'] = $this->messagePreview($x);
                if ($k === 'manualCall') $m['script'] = $script;
                $manual[] = $m;
            }
        }
        // overdue first, then by due date
        $order = fn ($a, $b) => [!$a['overdue'], $a['dueDate'] ?? '9', $a['id']] <=> [!$b['overdue'], $b['dueDate'] ?? '9', $b['id']];
        usort($queue, $order);
        usort($manual, $order);
        $s = $this->sender();
        $users = $this->store->meta('team_users', []) ?: [];
        return [
            'writes' => $this->writesOn() ? 'on' : 'off',
            'sender' => $s ? ['label' => LemlistSender::label($s), 'name' => $s['name'], 'email' => $s['email']] : null,
            'viewOnly' => $s === null,
            'statuses' => $statuses,
            'queue' => $queue, 'manual' => $manual,
            'counts' => ['calls' => count(array_filter($queue, fn ($q) => $q['status'] === 'due')), 'manual' => count(array_filter($manual, fn ($q) => $q['status'] === 'due')),
                'badge' => self::dueCount($this->store)], // unique tasks (a manual call task is in both lists)
            'today' => $this->today(),
            'callers' => array_map(fn ($u) => ['userId' => $u['userId'], 'name' => $u['name']], $users),
            'eventAt' => $this->store->live('last_event_at'), 'refreshedAt' => $this->store->meta('tasks_refreshed_at'),
        ];
    }

    /** Today's calls logged here: done / answered / positive. */
    public function today(): array
    {
        $start = (new \DateTimeImmutable('@' . ($this->now)()))->setTimezone(new \DateTimeZone($this->tz))->setTime(0, 0)->getTimestamp();
        $st = CallOutcomes::all($this->store);
        $done = $answered = $positive = 0;
        foreach ($this->store->all('SELECT call_status FROM calls WHERE created_at >= ?', [$start]) as $c) {
            $done++;
            if ((int) ($st[$c['call_status']]['answered'] ?? 0)) $answered++;
            if ((int) ($st[$c['call_status']]['positive'] ?? 0)) $positive++;
        }
        return ['done' => $done, 'answered' => $answered, 'positive' => $positive];
    }

    // ------------------------------------------------------------------ A. log a call

    /**
     * Phone task: POST /calls {taskId} (lemlist completes the task). Manual call task: POST /calls {leadId} + PATCH /tasks {id, done:true}.
     * Call-back date: POST /calls {leadId} (task stays open) + PATCH /tasks {id, dueDate}. Idempotent per attempt (externalId).
     */
    public function logCall(array $in): array
    {
        $taskId = is_string($in['taskId'] ?? null) ? $in['taskId'] : '';
        $attempt = is_string($in['attempt'] ?? null) ? $in['attempt'] : '';
        if (!preg_match('/^opp_[A-Za-z0-9]{3,60}$/', $taskId)) throw new ValidationError('Unknown task');
        if (!preg_match('/^[a-f0-9]{16,32}$/', $attempt)) throw new ValidationError('Missing call attempt id (reload the page).');
        $answered = $in['answered'] ?? null;
        if (!is_bool($answered)) throw new ValidationError('Choose Answered or Not answered first.');
        $statusKey = is_string($in['status'] ?? null) ? $in['status'] : '';
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > self::MAX_NOTE) throw new ValidationError('The note is too long (max ' . self::MAX_NOTE . ' characters).');
        $callback = trim((string) ($in['callbackDate'] ?? ''));
        $sender = $this->senderOrFail();
        $task = $this->store->all('SELECT * FROM tasks WHERE id = ?', [$taskId])[0] ?? throw new NotFoundError('This task is no longer in the queue. Refresh the page.');
        $kind = self::kind($task);
        if (!in_array($kind, ['phone', 'manualCall'], true)) throw new ValidationError('This task is not a call task.');
        if (!in_array($task['status'], self::OPEN, true)) return $this->closedAnswer($taskId, 'This task is already closed in lemlist. The queue was refreshed.');
        $st = CallOutcomes::all($this->store)[$statusKey] ?? null;
        if (!$st || !$st['offered'] || !$st['inLemlist']) throw new ValidationError('Pick an outcome from the list.');
        if ((bool) $st['answered'] !== $answered) throw new ValidationError('That outcome does not match "' . ($answered ? 'Answered' : 'Not answered') . '".');
        $due = null;
        if ($callback !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $callback, new \DateTimeZone($this->tz));
            $today = (new \DateTimeImmutable('@' . ($this->now)()))->setTimezone(new \DateTimeZone($this->tz))->setTime(0, 0);
            if (!$d || $d->format('Y-m-d') !== $callback || $d <= $today || $d > $today->modify('+366 days')) throw new ValidationError('Pick a call-back date after today (within a year).');
            $due = $d->setTime(9, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
        }
        $leadId = (string) $task['lead_id'];
        $lead = $leadId !== '' ? ($this->store->all('SELECT * FROM leads WHERE lead_id = ?', [$leadId])[0] ?? null) : null;
        $name = $lead ? trim($lead['first_name'] . ' ' . $lead['last_name']) : trim((string) $task['lead_name']);
        $company = (string) ($lead['company'] ?? $task['lead_company'] ?? '');
        $externalId = 'sd-' . substr($taskId, 4) . '-' . $attempt;
        $call = ['externalId' => $externalId, 'userId' => $sender['userId'], 'direction' => 'outbound', 'startedAt' => gmdate('Y-m-d\TH:i:s\Z', ($this->now)()),
            'callStatus' => $statusKey, 'provider' => 'signal-desk'];
        if ($note !== '') $call['note'] = $note;
        // no call-back: the call completes its task (a phone task through POST /calls {taskId}; a manual call task with PATCH done).
        // a call-back date: the call is logged on the lead (POST /calls with taskId would complete the task) and the SAME task moves to
        // that day, stays open and gets the note (PATCH /tasks {id, dueDate, message}): the lead waits on that call, the sequence does not move on.
        if ($kind === 'phone' && $due === null) $call['taskId'] = $taskId;
        elseif ($leadId !== '') $call['leadId'] = $leadId;
        else $call['contactId'] = (string) $task['contact_id'];
        $requests = [['POST', '/calls', $call]];
        $taskUpdate = null;
        if ($due !== null) {
            $taskUpdate = ['id' => $taskId, 'dueDate' => $due];
            if ($note !== '') $taskUpdate['message'] = $note;
        } elseif ($kind === 'manualCall') {
            $taskUpdate = ['id' => $taskId, 'done' => true];
        }
        if ($taskUpdate !== null) $requests[] = ['PATCH', '/tasks', $taskUpdate];
        $ids = ['taskId' => $taskId, 'leadId' => $leadId, 'callStatus' => $statusKey];
        $ctx = ['externalId' => $externalId, 'note' => $note, 'callback' => $callback, 'answered' => $answered, 'outcome' => (string) $st['label'],
            'campaign' => $this->campaignInfo((string) $task['campaign_id'])['name'], 'name' => $name, 'company' => $company, 'lead' => $lead];
        if (!$this->writesOn()) { // preview mode: nothing is sent anywhere, Atlas included
            return $this->preview('call_log', $requests, $ids) + ['next' => true, 'results' => $this->atlasSide($ctx, true)];
        }
        if ($this->store->meta('calls_api') === 'no') throw new AppError(402, 'LEMLIST_PLAN', 'Calls API needs Email Pro+: log this call in lemlist itself.');
        $this->limiter->hit($this->user, 'call');
        $client = $this->clientOrFail();
        $results = [];
        $lemlistError = null;
        try {
            $done = $this->store->all('SELECT * FROM calls WHERE external_id = ?', [$externalId])[0] ?? null;
            if (!$done || !$done['lemlist_id']) {
                try {
                    $r = $this->postCall($client, $call);
                } catch (ConflictError $e) {
                    if ($e->errCode === 'CALLS_API_TASK_ALREADY_CLOSED') {
                        $this->audit('call_log', $ids, 'task_already_closed');
                        return $this->closedAnswer($taskId, 'Someone already completed this task in lemlist. The queue was refreshed; nothing was logged twice.');
                    }
                    if ($e->errCode === 'CALLS_API_LOG_IN_PROGRESS') {
                        $this->audit('call_log', $ids, 'log_in_progress');
                        $this->refreshAfterAction();
                        return ['ok' => false, 'retry' => true, 'message' => 'lemlist is still saving this call. Click Save again in a moment: it will not be logged twice.'];
                    }
                    throw $e;
                }
                $this->store->db->prepare('INSERT INTO calls (external_id, lemlist_id, task_id, lead_id, user_id, by_user, call_status, started_at, created_at, answered, task_done, callback_at, kind, note)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(external_id) DO UPDATE SET lemlist_id = excluded.lemlist_id')
                    ->execute([$externalId, (string) ($r['data']['_id'] ?? 'logged'), $taskId, $leadId, $sender['userId'], $this->user, $statusKey, $call['startedAt'], ($this->now)(),
                        (int) $answered, $kind === 'phone' && $due === null ? 1 : 0, $due, $kind, $note !== '' ? $note : null]);
            }
            try {
                if ($taskUpdate !== null) $client->write('PATCH', '/tasks', $taskUpdate); // the same body twice is harmless: Save again is safe
            } catch (AppError $e) {
                $this->audit('call_log', $ids, 'task_update_failed');
                throw new AppError($e->status, $e->errCode, 'The call is logged in lemlist, but ' . ($due !== null ? 'moving the task to ' . $callback : 'completing the task') . ' failed (' . $e->getMessage() . ').');
            }
            $this->store->db->prepare('UPDATE calls SET task_done = 1 WHERE external_id = ?')->execute([$externalId]);
            if ($due === null) $this->store->db->prepare("UPDATE tasks SET status = 'closed' WHERE id = ?")->execute([$taskId]);
            else $this->store->db->prepare("UPDATE tasks SET due_date = ?, status = 'upcoming' WHERE id = ?")->execute([$due, $taskId]);
            if ($leadId !== '' && ((int) $st['positive'] || (int) $st['negative'])) $this->setOutcome($leadId, (int) $st['positive'] ? 'Interested' : 'Not interested');
            $this->audit('call_log', $ids, $due ? 'logged_callback' : 'logged');
            $results[] = ['dest' => 'lemlist', 'ok' => true, 'text' => 'lemlist ✓ call logged' . ($note !== '' ? ' with your note' : '')
                . ($due !== null ? ', task moved to ' . $callback . ($note !== '' ? ' with your note' : '') . ' (still open)' : '')];
        } catch (AppError $e) {
            $lemlistError = $e;
            $results[] = ['dest' => 'lemlist', 'ok' => false, 'text' => 'lemlist: ' . $e->getMessage()];
        }
        // Atlas runs whatever happened in lemlist (and the other way round): one side never blocks or undoes the other
        array_push($results, ...$this->atlasSide($ctx, false));
        if ($lemlistError) {
            return ['ok' => false, 'results' => $results, 'message' => $lemlistError->getMessage() . ' Click Save again: nothing that worked is sent twice.'];
        }
        $this->refreshAfterAction();
        return ['ok' => true, 'next' => true, 'results' => $results, 'today' => $this->today(),
            'message' => $due ? 'Call logged; the task moves to ' . $callback . ' and stays open.' : 'Call logged and task completed in lemlist.'];
    }

    // ------------------------------------------------------------------ lead search (Calls tab): every synced lead, every campaign

    public const SEARCH_LIMIT = 50;

    /** id => {name, status} of every campaign we know (running ones from the sync, every other status from the daily campaign list). */
    private function campaignNames(): array
    {
        $out = [];
        foreach ($this->store->all('SELECT id, name, status FROM campaign_names') as $c) $out[$c['id']] = ['name' => (string) $c['name'], 'status' => (string) $c['status']];
        foreach ($this->store->all('SELECT id, name, status, COALESCE(running, 1) AS running FROM campaigns') as $c) {
            $out[$c['id']] = ['name' => (string) $c['name'], 'status' => $out[$c['id']]['status'] ?? ((int) $c['running'] ? (string) $c['status'] : 'stopped')];
        }
        return $out;
    }

    /**
     * Search name, phone, company or email over ALL leads in the local database: every campaign (running, paused, ended), every lead status,
     * finished tasks included. Accents and case are ignored ("Sørensen" = "sorensen"); phones are compared on their last 9 digits.
     */
    public function searchLeads(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return ['q' => $q, 'results' => [], 'total' => 0];
        $phone = LeadSearch::phoneQuery($q);
        $tokens = $phone === null ? LeadSearch::tokens($q) : [];
        $hits = [];
        foreach ($this->store->all('SELECT lead_id, campaign_id, first_name, last_name, company, title, email, phone, phones FROM leads') as $l) {
            $match = $phone !== null ? LeadSearch::phoneMatch($phone, array_merge([(string) $l['phone']], explode("\n", (string) $l['phones']))) : LeadSearch::textMatch($tokens, $l);
            if ($match) $hits[] = $l;
        }
        usort($hits, fn ($a, $b) => [LeadSearch::fold($a['first_name'] . ' ' . $a['last_name']), $a['lead_id']] <=> [LeadSearch::fold($b['first_name'] . ' ' . $b['last_name']), $b['lead_id']]);
        $camps = $this->campaignNames();
        $results = array_map(function ($l) use ($camps) {
            $name = trim($l['first_name'] . ' ' . $l['last_name']);
            $c = $camps[$l['campaign_id']] ?? null;
            return ['id' => $l['lead_id'], 'name' => $name !== '' ? $name : '(no name)', 'title' => (string) $l['title'], 'company' => (string) $l['company'],
                'campaign' => $c['name'] ?? '', 'campaignStatus' => $c['status'] ?? '', 'phone' => trim((string) $l['phone']) ?: null];
        }, array_slice($hits, 0, self::SEARCH_LIMIT));
        return ['q' => $q, 'results' => $results, 'total' => count($hits)];
    }

    /** The lead card: who, campaign / status / step, all variables, call history, the signal, the job advert. Read-only. */
    public function leadCard(string $id): array
    {
        if (!preg_match('/^lea_[A-Za-z0-9]{3,60}$/', $id)) throw new ValidationError('Unknown lead');
        $l = $this->store->all('SELECT * FROM leads WHERE lead_id = ?', [$id])[0] ?? throw new NotFoundError('This lead is not in the local database (yet).');
        $o = (new Outreach($this->store, $this->config, $this->tz))->lead($id);
        $c = $this->campaignNames()[$l['campaign_id']] ?? null;
        $fields = [];
        foreach (json_decode((string) $l['fields'], true) ?: [] as $k => $v) if (is_string($v) && trim($v) !== '') $fields[] = ['name' => (string) $k, 'value' => $v];
        $phone = trim((string) $l['phone']);
        $name = trim($l['first_name'] . ' ' . $l['last_name']);
        $lv = $o['level'];
        $s = $this->sender();
        return ['id' => $id, 'name' => $name !== '' ? $name : '(no name)', 'title' => (string) $l['title'], 'company' => (string) $l['company'], 'email' => trim((string) $l['email']) ?: null,
            'phone' => $phone !== '' ? $phone : null, 'tel' => $phone !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $phone) : null,
            'otherPhones' => array_values(array_filter(explode("\n", (string) $l['phones']))), 'linkedin' => trim((string) $l['linkedin']) ?: null,
            'jobUrl' => LeadSearch::jobUrl($this->store, $l),
            'campaign' => ['id' => $l['campaign_id'], 'name' => $c['name'] ?? (string) $o['campaign'], 'status' => $c['status'] ?? ''],
            'leadStatus' => $o['status'], 'lemlistState' => (string) $l['last_state'], 'paused' => (bool) $l['paused'],
            'step' => ['n' => (int) $lv['n'], 'total' => (int) $lv['total'], 'label' => (string) $lv['label']],
            'lemlistUrl' => $o['lemlistUrl'], 'fields' => $fields, 'calls' => $this->callHistory($l), 'signal' => LeadSearch::signal($this->store, $l),
            'writes' => $this->writesOn() ? 'on' : 'off', 'viewOnly' => $s === null, 'statuses' => $this->statuses(), 'calledToday' => $this->today()];
    }

    /**
     * Every call on this person (this lead, and the same contact / email in other campaigns), newest first: calls logged here (date, outcome,
     * note) and lemlist's own call activities (dialer calls, calls logged in lemlist) that are not one of ours.
     */
    private function callHistory(array $l): array
    {
        $ids = [$l['lead_id']];
        $contact = (string) $l['contact_id'];
        $email = trim((string) $l['email']);
        if ($contact !== '' || $email !== '') {
            foreach ($this->store->all("SELECT lead_id FROM leads WHERE (contact_id = ? AND contact_id <> '') OR (email = ? COLLATE NOCASE AND email <> '')", [$contact, $email]) as $r) $ids[] = $r['lead_id'];
        }
        $ids = array_values(array_unique($ids));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $camps = $this->campaignNames();
        $campOf = [];
        foreach ($this->store->all("SELECT lead_id, campaign_id FROM leads WHERE lead_id IN ($in)", $ids) as $r) $campOf[$r['lead_id']] = $camps[$r['campaign_id']]['name'] ?? '';
        $st = CallOutcomes::all($this->store);
        $out = [];
        $ours = [];
        foreach ($this->store->all("SELECT * FROM calls WHERE lead_id IN ($in) ORDER BY created_at DESC", $ids) as $c) {
            $t = (int) $c['created_at'];
            $ours[] = $t;
            $out[] = ['at' => gmdate('Y-m-d\TH:i:s\Z', $t), 'outcome' => (string) ($st[$c['call_status']]['label'] ?? $c['call_status']), 'answered' => (bool) $c['answered'],
                'note' => trim((string) ($c['note'] ?? '')) ?: null, 'callback' => $c['callback_at'] ? substr((string) $c['callback_at'], 0, 10) : null,
                'by' => (string) $c['by_user'], 'campaign' => $campOf[$c['lead_id']] ?? '', 'source' => 'Signal Desk'];
        }
        foreach ($this->store->all("SELECT type, lead_id, created_at FROM activities WHERE lead_id IN ($in) AND type LIKE 'aircall%' ORDER BY created_at DESC", $ids) as $a) {
            $t = self::ts($a['created_at']);
            $dup = false;
            foreach ($ours as $o) if (abs($o - $t) <= 900) { $dup = true; break; } // the activity of a call we logged
            if ($dup) continue;
            $out[] = ['at' => gmdate('Y-m-d\TH:i:s\Z', $t), 'outcome' => Outreach::activityLabel((string) $a['type']), 'answered' => null, 'note' => null, 'callback' => null,
                'by' => '', 'campaign' => $campOf[$a['lead_id']] ?? '', 'source' => 'lemlist'];
        }
        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));
        return $out;
    }

    /** Outcome key + "did they answer?" checked against the live call statuses. */
    private function outcomeFor(string $statusKey, mixed $answered): array
    {
        if (!is_bool($answered)) throw new ValidationError('Choose Answered or Not answered first.');
        $st = CallOutcomes::all($this->store)[$statusKey] ?? null;
        if (!$st || !$st['offered'] || !$st['inLemlist']) throw new ValidationError('Pick an outcome from the list.');
        if ((bool) $st['answered'] !== $answered) throw new ValidationError('That outcome does not match "' . ($answered ? 'Answered' : 'Not answered') . '".');
        return $st;
    }

    /** A call-back date (YYYY-MM-DD, after today, within a year) as 09:00 Brussels in UTC, or null for none. */
    private function callbackDue(string $callback): ?string
    {
        if ($callback === '') return null;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $callback, new \DateTimeZone($this->tz));
        $today = (new \DateTimeImmutable('@' . ($this->now)()))->setTimezone(new \DateTimeZone($this->tz))->setTime(0, 0);
        if (!$d || $d->format('Y-m-d') !== $callback || $d <= $today || $d > $today->modify('+366 days')) throw new ValidationError('Pick a call-back date after today (within a year).');
        return $d->setTime(9, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
    }

    /**
     * "Log a call" from the lead card (inbound call-backs, leads without an open call task): POST /calls {leadId} only, no task is completed,
     * moved or created. A call-back date goes to the Atlas task (as in the Now calling panel); lemlist has no task to move here.
     * Same guard, kill switch (LEMLIST_WRITES), rate limit, idempotency (externalId per attempt) and audit (ids only) as every call.
     */
    public function logLeadCall(array $in): array
    {
        $leadId = is_string($in['leadId'] ?? null) ? $in['leadId'] : '';
        $attempt = is_string($in['attempt'] ?? null) ? $in['attempt'] : '';
        if (!preg_match('/^lea_[A-Za-z0-9]{3,60}$/', $leadId)) throw new ValidationError('Unknown lead');
        if (!preg_match('/^[a-f0-9]{16,32}$/', $attempt)) throw new ValidationError('Missing call attempt id (reload the page).');
        $statusKey = is_string($in['status'] ?? null) ? $in['status'] : '';
        $st = $this->outcomeFor($statusKey, $in['answered'] ?? null);
        $answered = (bool) $in['answered'];
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > self::MAX_NOTE) throw new ValidationError('The note is too long (max ' . self::MAX_NOTE . ' characters).');
        $callback = trim((string) ($in['callbackDate'] ?? ''));
        $due = $this->callbackDue($callback);
        $sender = $this->senderOrFail();
        $lead = $this->store->all('SELECT * FROM leads WHERE lead_id = ?', [$leadId])[0] ?? throw new NotFoundError('This lead is not in the local database (yet).');
        $name = trim($lead['first_name'] . ' ' . $lead['last_name']);
        $externalId = 'sd-lead-' . substr($leadId, 4) . '-' . $attempt;
        $call = ['externalId' => $externalId, 'userId' => $sender['userId'], 'direction' => 'outbound', // the guard allows outbound calls only
            'startedAt' => gmdate('Y-m-d\TH:i:s\Z', ($this->now)()), 'callStatus' => $statusKey, 'provider' => 'signal-desk', 'leadId' => $leadId];
        if ($note !== '') $call['note'] = $note;
        $requests = [['POST', '/calls', $call]];
        $ids = ['leadId' => $leadId, 'callStatus' => $statusKey];
        $ctx = ['externalId' => $externalId, 'note' => $note, 'callback' => $callback, 'answered' => $answered, 'outcome' => (string) $st['label'],
            'campaign' => $this->campaignNames()[$lead['campaign_id']]['name'] ?? '', 'name' => $name, 'company' => (string) $lead['company'], 'lead' => $lead];
        if (!$this->writesOn()) {
            return $this->preview('call_log_lead', $requests, $ids) + ['results' => $this->atlasSide($ctx, true)];
        }
        if ($this->store->meta('calls_api') === 'no') throw new AppError(402, 'LEMLIST_PLAN', 'Calls API needs Email Pro+: log this call in lemlist itself.');
        $this->limiter->hit($this->user, 'call');
        $client = $this->clientOrFail();
        $results = [];
        $lemlistError = null;
        try {
            $done = $this->store->all('SELECT * FROM calls WHERE external_id = ?', [$externalId])[0] ?? null;
            if (!$done || !$done['lemlist_id']) {
                $r = $this->postCall($client, $call);
                $this->store->db->prepare('INSERT INTO calls (external_id, lemlist_id, task_id, lead_id, user_id, by_user, call_status, started_at, created_at, answered, task_done, callback_at, kind, note)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(external_id) DO UPDATE SET lemlist_id = excluded.lemlist_id')
                    ->execute([$externalId, (string) ($r['data']['_id'] ?? 'logged'), '', $leadId, $sender['userId'], $this->user, $statusKey, $call['startedAt'], ($this->now)(),
                        (int) $answered, 0, $due, 'lead', $note !== '' ? $note : null]);
            }
            if ((int) $st['positive'] || (int) $st['negative']) $this->setOutcome($leadId, (int) $st['positive'] ? 'Interested' : 'Not interested');
            $this->audit('call_log_lead', $ids, 'logged');
            $results[] = ['dest' => 'lemlist', 'ok' => true, 'text' => 'lemlist ✓ call logged on the lead' . ($note !== '' ? ' with your note' : '') . ' (no task)'
                . ($due !== null ? '; no lemlist task to move, the call-back date goes to Atlas' : '')];
        } catch (AppError $e) {
            $lemlistError = $e;
            $results[] = ['dest' => 'lemlist', 'ok' => false, 'text' => 'lemlist: ' . $e->getMessage()];
        }
        array_push($results, ...$this->atlasSide($ctx, false));
        if ($lemlistError) return ['ok' => false, 'results' => $results, 'message' => $lemlistError->getMessage() . ' Click Save again: nothing that worked is sent twice.'];
        return ['ok' => true, 'results' => $results, 'today' => $this->today(), 'message' => 'Call logged on ' . ($name !== '' ? $name : 'the lead') . '.', 'card' => $this->leadCard($leadId)];
    }

    /**
     * "Search lemlist": a live, read-only lookup for leads not in the local database yet. An email -> GET /leads?email= (every campaign of that
     * person, with variables); anything else -> GET /v2/contacts?search= (name, email, phone). Nothing is stored.
     */
    public function searchLemlist(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) throw new ValidationError('Type at least 2 characters.');
        $client = $this->clientOrFail();
        $this->limiter->hit($this->user, 'lookup');
        $local = fn (string $id) => $id !== '' && $this->store->one('SELECT 1 FROM leads WHERE lead_id = ?', [$id]) !== null;
        if (filter_var($q, FILTER_VALIDATE_EMAIL)) return ['q' => $q, 'source' => 'leads', 'results' => $this->liveLeads($client, $q, $local)];
        try {
            $r = (array) $client->get('/v2/contacts', ['search' => $q, 'limit' => 20]);
        } catch (NotFoundError) {
            $r = [];
        }
        $rows = $r['data']['data'] ?? $r['data'] ?? $r['contacts'] ?? (array_is_list($r) ? $r : []);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $c) {
            if (!is_array($c)) continue;
            $name = trim((string) ($c['fullName'] ?? trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''))));
            $out[] = ['contactId' => (string) ($c['_id'] ?? ''), 'name' => $name !== '' ? $name : '(no name)', 'title' => (string) ($c['jobTitle'] ?? ''), 'company' => (string) ($c['companyName'] ?? ''),
                'email' => (string) ($c['email'] ?? '') ?: null, 'phone' => (string) ($c['phone'] ?? '') ?: null, 'linkedin' => (string) ($c['linkedinUrl'] ?? '') ?: null,
                'campaigns' => (int) ($c['campaignCount'] ?? 0)];
        }
        return ['q' => $q, 'source' => 'contacts', 'results' => $out];
    }

    /** One person's leads in lemlist, live (GET /leads?email=, read-only): campaign, status and every variable. */
    public function lemlistLeads(string $email): array
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ValidationError('Not an email address');
        $client = $this->clientOrFail();
        $this->limiter->hit($this->user, 'lookup');
        $local = fn (string $id) => $id !== '' && $this->store->one('SELECT 1 FROM leads WHERE lead_id = ?', [$id]) !== null;
        return ['email' => $email, 'results' => $this->liveLeads($client, $email, $local)];
    }

    private function liveLeads(LemlistClient $client, string $email, callable $local): array
    {
        try {
            $r = $client->get('/leads', ['email' => $email, 'version' => 'v2']);
        } catch (NotFoundError) {
            return [];
        }
        $rows = is_array($r) ? (array_is_list($r) ? $r : ($r['data'] ?? [$r])) : [];
        $out = [];
        foreach ($rows as $l) {
            if (!is_array($l) || empty($l['_id'])) continue;
            $v = is_array($l['variables'] ?? null) ? $l['variables'] : $l;
            $fields = [];
            foreach (json_decode((string) LeadVars::all($v), true) ?: [] as $k => $val) $fields[] = ['name' => (string) $k, 'value' => (string) $val];
            $row = ['fields' => LeadVars::all($v)];
            $job = null;
            foreach (LeadSearch::JOB_URL_FIELDS as $n) {
                $u = LeadVars::field($row, [$n]);
                if ($u !== null && preg_match('~^https?://~i', $u) && filter_var($u, FILTER_VALIDATE_URL)) { $job = ['url' => $u, 'from' => 'lemlist variable "' . $n . '"']; break; }
            }
            $phone = trim((string) ($v['phone'] ?? ''));
            $id = (string) $l['_id'];
            $out[] = ['id' => $id, 'inDb' => $local($id), 'name' => trim(($v['firstName'] ?? '') . ' ' . ($v['lastName'] ?? '')) ?: '(no name)', 'title' => (string) ($v['jobTitle'] ?? ''),
                'company' => (string) ($v['companyName'] ?? ''), 'email' => (string) ($v['email'] ?? $email), 'phone' => $phone !== '' ? $phone : null,
                'tel' => $phone !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $phone) : null, 'linkedin' => (string) ($v['linkedinUrl'] ?? '') ?: null, 'jobUrl' => $job,
                'campaign' => ['id' => (string) ($l['campaign']['id'] ?? $l['campaignId'] ?? ''), 'name' => (string) ($l['campaign']['name'] ?? ''), 'status' => (string) ($l['campaign']['status'] ?? '')],
                'leadStatus' => (string) ($l['status'] ?? ''), 'lemlistState' => (string) ($l['state'] ?? ''), 'paused' => !empty($l['isPaused']), 'fields' => $fields];
        }
        return $out;
    }

    private function synced(string $externalId, string $what): bool
    {
        return (string) $this->store->one("SELECT $what FROM call_sync WHERE external_id = ?", [$externalId]) !== '';
    }

    private function markSynced(string $externalId, string $what, string $value): void
    {
        $this->store->db->prepare("INSERT INTO call_sync (external_id, $what, at) VALUES (?,?,?) ON CONFLICT(external_id) DO UPDATE SET $what = excluded.$what")
            ->execute([$externalId, $value, ($this->now)()]);
    }

    /**
     * The Atlas side of a saved call: a person note (when there is a note) and a task (when there is a call-back date), on the ONE Atlas
     * person matching the lead (email first, then LinkedIn URL; read-only lookups). No match or several: nothing in Atlas. ATLAS_WRITES=off
     * (or lemlist preview mode): nothing is written, the lines say what would happen. Each write is sent once per save (call_sync).
     * @return list<array{dest:string, ok:?bool, text:string}>
     */
    private function atlasSide(array $ctx, bool $preview): array
    {
        $wantNote = $ctx['note'] !== '';
        $wantTask = $ctx['callback'] !== '';
        if (!$wantNote && !$wantTask) return [];
        if (!$this->atlas) return [['dest' => 'atlas', 'ok' => null, 'text' => 'Atlas: not connected (saved in lemlist only)']];
        if (!Atlas::writesOn($this->config)) return [['dest' => 'atlas', 'ok' => null, 'text' => 'Atlas: not sent (ATLAS_WRITES is off in config.php)']];
        $only = $wantNote ? 'Not found in Atlas (note saved in lemlist only)' : 'Not found in Atlas (saved in lemlist only)';
        try {
            $match = $this->atlasPerson($ctx['lead']);
        } catch (\Throwable $e) {
            return [['dest' => 'atlas', 'ok' => false, 'text' => 'Atlas lookup failed: ' . $e->getMessage()]];
        }
        if (count($match) !== 1) return [['dest' => 'atlas', 'ok' => null, 'text' => $only . (count($match) > 1 ? ' (' . count($match) . ' people match)' : '')]];
        $personId = $match[0];
        $out = [];
        $loginEmail = strtolower($this->user);
        try {
            $owner = $this->atlas->get('/api/v1/users', ['email' => $loginEmail, 'page' => 1, 'pageSize' => 1])['data'][0]['id'] ?? null;
        } catch (\Throwable $e) {
            return [['dest' => 'atlas', 'ok' => false, 'text' => 'Atlas: could not read your Atlas user (' . $e->getMessage() . ')']];
        }
        if (!is_string($owner) || !AtlasGuard::isUuid($owner)) {
            return [['dest' => 'atlas', 'ok' => false, 'text' => "Atlas: no Atlas user with your login email ($loginEmail), so nothing was added there"]];
        }
        if ($wantNote) {
            $when = (new \DateTimeImmutable('@' . ($this->now)()))->setTimezone(new \DateTimeZone($this->tz))->format('Y-m-d H:i');
            $text = 'Call ' . $when . ' · ' . ($ctx['answered'] ? 'Answered' : 'Not answered') . ' · ' . $ctx['outcome'] . ($ctx['campaign'] !== '' ? ' · ' . $ctx['campaign'] : '') . "\n" . $ctx['note'];
            $body = ['personId' => $personId, 'note' => $text, 'ownerEmail' => $loginEmail];
            AtlasGuard::assertWrite('PUT', '/api/v1/people/notes', $body);
            if ($preview) $out[] = ['dest' => 'atlasNote', 'ok' => null, 'text' => 'Atlas note: would be added (preview)'];
            elseif ($this->synced($ctx['externalId'], 'atlas_note')) $out[] = ['dest' => 'atlasNote', 'ok' => true, 'text' => 'Atlas note ✓ (already added)'];
            else {
                try {
                    $r = $this->atlas->write('PUT', '/api/v1/people/notes', $body);
                    $this->markSynced($ctx['externalId'], 'atlas_note', (string) ($r['data']['id'] ?? 'added'));
                    $out[] = ['dest' => 'atlasNote', 'ok' => true, 'text' => 'Atlas note ✓'];
                } catch (\Throwable $e) {
                    $out[] = ['dest' => 'atlasNote', 'ok' => false, 'text' => 'Atlas note: ' . $e->getMessage()];
                }
            }
        }
        if ($wantTask) {
            $who = $ctx['name'] !== '' ? $ctx['name'] : 'lead';
            $body = ['content' => 'Call back ' . $who . ($ctx['company'] !== '' ? ' (' . $ctx['company'] . ')' : '') . ($ctx['note'] !== '' ? "\n" . $ctx['note'] : ''),
                'dueDate' => $ctx['callback'], 'ownerId' => $owner, 'personId' => $personId];
            AtlasGuard::assertWrite('POST', '/api/v1/tasks', $body);
            if ($preview) $out[] = ['dest' => 'atlasTask', 'ok' => null, 'text' => 'Atlas task: would be added for ' . $ctx['callback'] . ' (preview)'];
            elseif ($this->synced($ctx['externalId'], 'atlas_task')) $out[] = ['dest' => 'atlasTask', 'ok' => true, 'text' => 'Atlas task ✓ (already added)'];
            else {
                try {
                    $r = $this->atlas->write('POST', '/api/v1/tasks', $body);
                    $this->markSynced($ctx['externalId'], 'atlas_task', (string) ($r['data']['id'] ?? 'added'));
                    $out[] = ['dest' => 'atlasTask', 'ok' => true, 'text' => 'Atlas task ✓ (due ' . $ctx['callback'] . ')'];
                } catch (\Throwable $e) {
                    $out[] = ['dest' => 'atlasTask', 'ok' => false, 'text' => 'Atlas task: ' . $e->getMessage()];
                }
            }
        }
        if (!$preview) $this->audit('atlas_call_sync', ['externalId' => $ctx['externalId'], 'note' => $wantNote ? 'yes' : 'no', 'task' => $wantTask ? 'yes' : 'no'], 'done');
        return $out;
    }

    /** The Atlas person ids matching a lead: by email first, then by LinkedIn URL (read-only GET /people/search). */
    private function atlasPerson(?array $lead): array
    {
        if (!$lead) return [];
        $ids = function (array $r): array {
            $out = [];
            foreach ($r['data'] ?? [] as $p) if (is_array($p) && AtlasGuard::isUuid((string) ($p['id'] ?? ''))) $out[(string) $p['id']] = true;
            return array_keys($out);
        };
        $email = trim((string) ($lead['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $found = $ids($this->atlas->get('/api/v1/people/search', ['emails' => $email]));
            } catch (NotFoundError) {
                $found = [];
            }
            if ($found) return $found;
        }
        $li = trim((string) ($lead['linkedin'] ?? ''));
        if ($li !== '' && preg_match('~linkedin\.com/~i', $li)) {
            try {
                return $ids($this->atlas->get('/api/v1/people/search', ['linkedinUrls' => $li]));
            } catch (NotFoundError) {
                return [];
            }
        }
        return [];
    }

    /** POST /calls; a racing duplicate (409 LOG_IN_PROGRESS) is retried once after a second (same externalId = never twice). */
    private function postCall(LemlistClient $client, array $call): array
    {
        try {
            return (array) $client->write('POST', '/calls', $call);
        } catch (ConflictError $e) {
            if ($e->errCode !== 'CALLS_API_LOG_IN_PROGRESS') throw $e;
            ($this->sleep)(1000);
            return (array) $client->write('POST', '/calls', $call);
        }
    }

    private function closedAnswer(string $taskId, string $message): array
    {
        $this->store->db->prepare("UPDATE tasks SET status = 'closed' WHERE id = ?")->execute([$taskId]);
        $this->refreshAfterAction();
        return ['ok' => false, 'refreshed' => true, 'next' => true, 'message' => $message];
    }

    private function refreshAfterAction(): void
    {
        try {
            $this->refreshTasks(true);
        } catch (AppError) {
            // the local change already shows; the next view refresh catches up
        }
    }

    private function setOutcome(string $leadId, string $outcome): void
    {
        if (!$this->store->one('SELECT 1 FROM leads WHERE lead_id = ?', [$leadId])) return;
        $cur = (string) $this->store->one('SELECT outcome FROM outcomes WHERE lead_id = ?', [$leadId]);
        if (in_array($cur, ['Meeting', 'New job', 'Deal', 'New client'], true)) return; // never downgrade a business result
        $this->store->db->prepare('INSERT INTO outcomes (lead_id, outcome, by_user, at) VALUES (?,?,?,?) ON CONFLICT(lead_id) DO UPDATE SET outcome = excluded.outcome, by_user = excluded.by_user, at = excluded.at')
            ->execute([$leadId, $outcome, $this->user, ($this->now)()]);
    }

    // ------------------------------------------------------------------ B. manual tasks

    /** done (non-message tasks), send (message steps, after a confirm: lemlist then sends the step), skip (POST /tasks/ignore). */
    public function taskAction(array $in): array
    {
        $taskId = is_string($in['taskId'] ?? null) ? $in['taskId'] : '';
        $action = (string) ($in['action'] ?? '');
        if (!preg_match('/^opp_[A-Za-z0-9]{3,60}$/', $taskId)) throw new ValidationError('Unknown task');
        if (!in_array($action, ['done', 'send', 'skip'], true)) throw new ValidationError('Unknown action');
        $this->senderOrFail();
        $task = $this->store->all('SELECT * FROM tasks WHERE id = ?', [$taskId])[0] ?? throw new NotFoundError('This task is no longer open. Refresh the page.');
        if (!in_array($task['status'], self::OPEN, true)) return $this->closedAnswer($taskId, 'This task is already closed in lemlist. The list was refreshed.');
        $kind = self::kind($task);
        if ($action === 'send' && $kind !== 'message') throw new ValidationError('Only message steps can be sent.');
        if ($action === 'done' && $kind === 'message') throw new ValidationError('A message step is sent with Send (after the confirm step).');
        if ($action === 'done' && $kind === 'manualCall') throw new ValidationError('Log the call to close a call task.');
        if ($action === 'send' && ($in['confirm'] ?? null) !== true) throw new ValidationError('Confirm before sending: lemlist sends this step to the lead.');
        $req = $action === 'skip' ? ['POST', '/tasks/ignore', ['ids' => [$taskId]]] : ['PATCH', '/tasks', ['id' => $taskId, 'done' => true]];
        $ids = ['taskId' => $taskId, 'leadId' => $task['lead_id'], 'taskType' => $task['type']];
        if (!$this->writesOn()) return $this->preview('task_' . $action, [$req], $ids);
        $this->limiter->hit($this->user, $action === 'send' ? 'send' : 'task');
        $this->clientOrFail()->write(...$req);
        $this->store->db->prepare("UPDATE tasks SET status = 'closed' WHERE id = ?")->execute([$taskId]);
        $this->audit('task_' . $action, $ids, 'ok');
        $this->refreshAfterAction();
        return ['ok' => true, 'message' => ['done' => 'Task done in lemlist.', 'send' => 'Sent: lemlist is sending this step.', 'skip' => 'Task skipped in lemlist.'][$action]];
    }

    // ------------------------------------------------------------------ C. inbox

    /** Our 4 labels: name => id (creates missing ones when the lemlist user is an admin and $create is set). */
    private function labels(bool $create = false): array
    {
        $client = $this->clientOrFail();
        $list = $client->get('/inbox/labels');
        $all = [];
        foreach (is_array($list) ? (array_is_list($list) ? $list : ($list['data'] ?? [])) : [] as $l) $all[(string) ($l['name'] ?? '')] = (string) ($l['_id'] ?? '');
        $ours = [];
        foreach (LemlistGuard::LABELS as $name) {
            if (isset($all[$name])) { $ours[$name] = $all[$name]; continue; }
            if (!$create) continue;
            $s = $this->senderOrFail();
            $role = '';
            foreach ($this->store->meta('team_users', []) ?: [] as $u) if (($u['userId'] ?? '') === $s['userId']) $role = (string) ($u['role'] ?? '');
            if ($role !== 'admin') throw new AppError(403, 'LABEL_NEEDS_ADMIN', "The lemlist label \"$name\" does not exist yet and only a lemlist admin can create it. Create it in lemlist (Inbox → Labels).");
            try {
                $r = $client->write('POST', '/inbox/labels', ['labelName' => $name]);
                $ours[$name] = (string) ($r['_id'] ?? '');
                $this->audit('label_create', ['label' => $name], 'ok');
            } catch (ConflictError) {
                $again = $this->labels(false); // created meanwhile by someone else
                $ours[$name] = $again['ours'][$name] ?? '';
            }
        }
        return ['ours' => $ours, 'all' => $all];
    }

    private function labelNames(array $ids, array $all): array
    {
        $byId = array_flip($all);
        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
    }

    /** Conversation label ids, when lemlist returns them (null = unknown). */
    private static function convLabels(array $c): ?array
    {
        if (!array_key_exists('labels', $c) && !array_key_exists('labelIds', $c)) return null;
        $raw = $c['labels'] ?? $c['labelIds'] ?? [];
        return array_values(array_filter(array_map(fn ($l) => is_array($l) ? (string) ($l['_id'] ?? '') : (string) $l, (array) $raw)));
    }

    /** What a conversation says about its contact, to find the lead when lemlist did not give us the contactId (its lead export has none). */
    private static function convHints(array $c): array
    {
        $contact = is_array($c['contact'] ?? null) ? $c['contact'] : [];
        $ids = array_merge(is_string($c['leadId'] ?? null) ? [$c['leadId']] : [], array_filter((array) ($c['leadIds'] ?? []), 'is_string'));
        return ['email' => (string) ($c['contactEmail'] ?? $contact['email'] ?? $c['email'] ?? ''),
            'linkedin' => (string) ($c['contactLinkedinUrl'] ?? $contact['linkedinUrl'] ?? $c['linkedinUrl'] ?? ''), 'leadIds' => array_values(array_filter($ids))];
    }

    /**
     * The campaign leads of one inbox contact, best first (running campaign, then most recently added).
     * Found by contactId (lead export, tasks, activities, webhook events, leadIds lemlist gives), then email, then LinkedIn URL;
     * then widened to the same person's leads in other campaigns. The contactId is stored on the leads found, so the next lookup is direct.
     * @return list<array<string,mixed>>
     */
    private function leadsFor(string $contactId, array $hints = []): array
    {
        $sel = 'SELECT l.*, c.status AS camp_status FROM leads l LEFT JOIN campaigns c ON c.id = l.campaign_id WHERE ';
        $in = fn (array $ids) => implode(',', array_fill(0, count($ids), '?'));
        $free = "(l.contact_id IS NULL OR l.contact_id = '' OR l.contact_id = ?)"; // never take another lemlist contact's lead
        $rows = $this->store->all($sel . 'l.contact_id = ?', [$contactId]);
        if (!$rows) {
            $ids = array_column($this->store->all("SELECT lead_id FROM tasks WHERE contact_id = ? AND lead_id <> '' UNION SELECT lead_id FROM activities WHERE contact_id = ? AND lead_id IS NOT NULL
                UNION SELECT lead_id FROM events WHERE contact_id = ? AND lead_id IS NOT NULL", [$contactId, $contactId, $contactId]), 'lead_id');
            $ids = array_values(array_unique(array_merge($ids, $hints['leadIds'] ?? [])));
            if ($ids) $rows = $this->store->all($sel . "l.lead_id IN ({$in($ids)}) AND $free", [...$ids, $contactId]);
        }
        $email = strtolower(trim((string) ($hints['email'] ?? '')));
        if (!$rows && str_contains($email, '@')) $rows = $this->store->all($sel . "l.email = ? COLLATE NOCASE AND $free", [$email, $contactId]);
        $key = LinkedIn::profileKey((string) ($hints['linkedin'] ?? ''));
        if (!$rows && $key) $rows = $this->store->all($sel . "l.linkedin_key = ? AND $free", [$key, $contactId]);
        if (!$rows) return [];
        // the same person in other campaigns (same email or LinkedIn profile)
        $emails = array_values(array_unique(array_filter(array_map(fn ($l) => strtolower((string) $l['email']), $rows))));
        $keys = array_values(array_unique(array_filter(array_column($rows, 'linkedin_key'))));
        $or = [];
        $args = [];
        if ($emails) { $or[] = "lower(l.email) IN ({$in($emails)})"; array_push($args, ...$emails); }
        if ($keys) { $or[] = "l.linkedin_key IN ({$in($keys)})"; array_push($args, ...$keys); }
        if ($or) {
            $byId = array_column($rows, null, 'lead_id');
            foreach ($this->store->all($sel . '(' . implode(' OR ', $or) . ") AND $free", [...$args, $contactId]) as $l) $byId[$l['lead_id']] ??= $l;
            $rows = array_values($byId);
        }
        $todo = array_values(array_filter(array_column(array_filter($rows, fn ($l) => (string) $l['contact_id'] !== $contactId), 'lead_id')));
        if ($todo) $this->store->db->prepare("UPDATE leads SET contact_id = ? WHERE lead_id IN ({$in($todo)})")->execute([$contactId, ...$todo]);
        usort($rows, fn ($a, $b) => [strtolower((string) $b['camp_status']) === 'running', (string) $b['added_at']] <=> [strtolower((string) $a['camp_status']) === 'running', (string) $a['added_at']]);
        return $rows;
    }

    private static function signalOf(?array $lead): ?string
    {
        return $lead && $lead['signal_job_title'] ? ($lead['signal_track'] ?: 'IT') . ' · ' . $lead['signal_job_title'] : null;
    }

    /** One line for the inbox list: plain text, whitespace folded, at most 90 characters. */
    public static function snippet(string $text): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', self::plain($text)));
        return mb_strlen($t) > 90 ? rtrim(mb_substr($t, 0, 89)) . '…' : $t;
    }

    /** Incoming (the lead wrote) or outgoing (us), by activity type: *Replied = incoming; *Sent / *Done = outgoing; otherwise who sent it. */
    public static function isOutgoing(array $m): bool
    {
        $type = (string) ($m['type'] ?? '');
        if (preg_match('/^(emails|linkedin|whatsapp|sms|aircall)Replied$|^(emails|linkedin)(Interested|NotInterested)$/i', $type)) return false;
        if (preg_match('/(Sent|Done)$/', $type)) return true;
        return !empty($m['sendUserId']) || !empty($m['sendUserEmail']);
    }

    /**
     * The full text of one inbox activity, whatever the channel: `message` (emails as HTML; LinkedIn, WhatsApp, SMS as text), else `text`,
     * `body`, `messagePreview` or a nested object (lemlist fills different fields per channel). HTML becomes text with its line breaks;
     * plain text is kept as is.
     */
    public static function messageText(array $m): string
    {
        foreach (['message', 'text', 'body', 'messagePreview', 'content', 'html'] as $k) {
            $v = $m[$k] ?? null;
            if (is_array($v)) $v = $v['text'] ?? $v['html'] ?? $v['body'] ?? $v['message'] ?? null;
            if (!is_string($v) || trim($v) === '') continue;
            if (preg_match('~<(p|br|div|span|a|html|body|table|tr|td|li|ul|ol|b|i|u|strong|em|blockquote|font|h[1-6])\b[^>]*>|</[a-z][a-z0-9]*>~i', $v)) return self::plain($v);
            return trim(str_replace(["\r\n", "\r"], "\n", html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        return '';
    }

    /** An email's own text without the quoted history below it ("On … wrote:", "Op … schreef:", "-----Original Message-----", "From: … Sent:", "> …"). */
    public static function trimQuoted(string $text): string
    {
        $lines = explode("\n", $text);
        $cut = count($lines);
        foreach ($lines as $i => $l) {
            $t = trim($l);
            if ($i === 0) continue;
            if (preg_match('/^(On|Op|Le|Am|El|Il)\b.{4,200}\b(wrote|schreef|a écrit|schrieb|escribió|ha scritto)\b.{0,200}:\s*$/iu', $t)
                || preg_match('/^-{2,}\s*(Original Message|Oorspronkelijk bericht|Message d\'origine|Ursprüngliche Nachricht|Forwarded message)\s*-{2,}$/iu', $t)
                || preg_match('/^_{10,}$/', $t)
                || (preg_match('/^(From|Van|De|Von)\s*:/iu', $t) && preg_match('/^(Sent|Verzonden|Envoyé|Gesendet|Date|Datum|To|Aan|À|An)\s*:/iu', trim($lines[$i + 1] ?? '') . trim($lines[$i + 2] ?? '')))
                || (str_starts_with($t, '>') && $i > 0 && count(array_filter(array_slice($lines, $i, 3), fn ($x) => str_starts_with(trim($x), '>'))) >= 2)) {
                $cut = $i;
                break;
            }
        }
        $own = trim(implode("\n", array_slice($lines, 0, $cut)));
        return $own !== '' ? $own : trim($text);
    }

    private static function channelOf(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'linkedin') => 'LinkedIn', str_starts_with($type, 'whatsapp') => 'WhatsApp', str_starts_with($type, 'sms') => 'SMS', default => 'Email',
        };
    }

    private static function ts(?string $iso): int
    {
        return $iso !== null && $iso !== '' ? (int) strtotime($iso) : 0;
    }

    public const FILTERS = ['all', 'unread', 'Interested', 'Meeting', 'Not interested', 'Out of office'];
    public const PAGE_SIZE = 25;
    public const THREAD_PAGE = 10;
    /** Threads read per list load to find a conversation's campaign (from its activities); the cron reads more. */
    public const LINK_READS = 8;
    /** "Mark as unread" (lemlist has no API for it): contact id => time, kept here until the conversation is opened again. */
    public const UNREAD_MARKS = 'inbox-unread-marks.json';

    /** @var array|null all conversations of this request (GET /inbox, every page) */
    private ?array $convs = null;

    /** Every conversation of the sender's lemlist inbox (myConversations), lemlist's order: latest activity first. */
    private function conversations(): array
    {
        if ($this->convs !== null) return $this->convs;
        $sender = $this->sender() ?? ['userId' => (string) ($this->config['LEMLIST_SHARED_SENDER'] ?? '')];
        $rows = [];
        for ($page = 1; $page <= 20; $page++) {
            $r = $this->clientOrFail()->get('/inbox', ['userId' => $sender['userId'], 'page' => $page, 'limit' => 50]);
            $data = $r['data'] ?? $r['conversations'] ?? [];
            array_push($rows, ...(is_array($data) ? array_filter($data, 'is_array') : []));
            if (count($data) < 50 || $page >= (int) ($r['pagination']['totalPages'] ?? $page)) break;
        }
        usort($rows, fn ($a, $b) => self::ts($b['lastActivityAt'] ?? null) <=> self::ts($a['lastActivityAt'] ?? null)); // stable: lemlist's order on ties
        return $this->convs = $rows;
    }

    /**
     * The campaign and lead of a conversation, from the campaignId + leadId on its activities (read from the thread and remembered,
     * or from synced activities), else from the leads Signal Desk knows. ['', ''] = no activity has a campaign (a direct contact); null = not known yet.
     */
    private function contactLink(string $contactId, array $hints = []): ?array
    {
        $l = $this->store->all('SELECT campaign_id, lead_id FROM contact_links WHERE contact_id = ?', [$contactId])[0] ?? null;
        if ($l && (string) $l['campaign_id'] !== '') return [(string) $l['campaign_id'], (string) $l['lead_id']];
        $a = $this->store->all("SELECT campaign_id, lead_id FROM activities WHERE contact_id = ? AND campaign_id IS NOT NULL AND campaign_id <> '' ORDER BY created_at DESC LIMIT 1", [$contactId])[0] ?? null;
        if ($a) return [(string) $a['campaign_id'], (string) $a['lead_id']];
        if ($l) return ['', ''];
        $lead = $this->leadsFor($contactId, $hints)[0] ?? null;
        return $lead ? [(string) $lead['campaign_id'], (string) $lead['lead_id']] : null;
    }

    /** Remember a conversation's campaign from its thread activities (newest with a campaignId). $complete: every activity was seen. */
    private function linkFromActivities(string $contactId, array $acts, bool $complete): void
    {
        $best = null;
        foreach ($acts as $a) {
            if (!is_array($a) || !is_string($a['campaignId'] ?? null) || $a['campaignId'] === '') continue;
            if ($best === null || self::ts($a['createdAt'] ?? null) > self::ts($best['createdAt'] ?? null)) $best = $a;
        }
        if (!$best && !$complete) return; // older activities may still have one
        $company = '';
        foreach ($acts as $a) if (is_array($a) && $company === '' && is_string($a['leadCompanyName'] ?? $a['companyName'] ?? null)) $company = mb_substr((string) ($a['leadCompanyName'] ?? $a['companyName']), 0, 200);
        $this->store->db->prepare('INSERT INTO contact_links (contact_id, campaign_id, lead_id, checked_at, company) VALUES (?,?,?,?,?) ON CONFLICT(contact_id) DO UPDATE SET
            campaign_id = excluded.campaign_id, lead_id = excluded.lead_id, checked_at = excluded.checked_at, company = COALESCE(NULLIF(excluded.company, \'\'), contact_links.company)')
            ->execute([$contactId, $best ? (string) $best['campaignId'] : '', $best ? (string) ($best['leadId'] ?? '') : '', ($this->now)(), $company]);
    }

    /** Read a conversation's latest activities (not marking it read) to find its campaign. */
    private function linkFromThread(string $contactId): void
    {
        $sender = $this->sender() ?? ['userId' => (string) ($this->config['LEMLIST_SHARED_SENDER'] ?? '')];
        try {
            $r = $this->clientOrFail()->get('/inbox/' . $contactId, ['userId' => $sender['userId'], 'limit' => self::THREAD_PAGE, 'skip' => 0]);
        } catch (\Throwable) {
            return;
        }
        $acts = $r['data'] ?? [];
        $this->linkFromActivities($contactId, is_array($acts) ? $acts : [], count($acts) < self::THREAD_PAGE && empty($r['pagination']['nextPage']));
    }

    /** Campaign name and status (all statuses, refreshed daily by the sync), else the running campaigns table. */
    private function campaignInfo(string $campaignId): array
    {
        if ($campaignId === '') return ['name' => '', 'status' => null];
        $c = $this->store->all('SELECT name, status FROM campaign_names WHERE id = ?', [$campaignId])[0]
            ?? $this->store->all("SELECT name, CASE WHEN running = 1 THEN 'running' ELSE status END AS status FROM campaigns WHERE id = ?", [$campaignId])[0] ?? null;
        return ['name' => (string) ($c['name'] ?? ''), 'status' => isset($c['status']) ? strtolower((string) $c['status']) : null];
    }

    /** The lead row behind a link (company, title, name, signal), when Signal Desk has it. */
    private function leadRow(?array $link, string $contactId, array $hints = []): ?array
    {
        if ($link && $link[1] !== '') {
            $l = $this->store->all('SELECT * FROM leads WHERE lead_id = ?', [$link[1]])[0] ?? null;
            if ($l) return $l;
        }
        return $this->leadsFor($contactId, $hints)[0] ?? null;
    }

    private function unreadMarks(): array
    {
        return JsonStore::read($this->store->dir . '/' . self::UNREAD_MARKS, []) ?: [];
    }

    /**
     * The lemlist inbox as lemlist shows it: every conversation (myConversations, any campaign status, sent-only ones too), latest activity
     * first, $per at a time ("Load more"). Each row: name, company, campaign (+ status when not running), the last message's preview
     * (whoever wrote it; "You:" when ours), channel, unread, labels. Filters: all, unread, our 4 labels; search by name, email or company.
     */
    public function inbox(string $filter = 'all', string $q = '', int $page = 1, int $per = self::PAGE_SIZE, int $linkReads = self::LINK_READS): array
    {
        $this->convs = null; // always the current lemlist list
        $sender = $this->sender() ?? ['userId' => (string) ($this->config['LEMLIST_SHARED_SENDER'] ?? '')];
        $labels = $this->labels(false);
        $marks = $this->unreadMarks();
        $all = [];
        foreach ($this->conversations() as $c) {
            $cid = (string) ($c['contactId'] ?? '');
            if ($cid === '') continue;
            $unread = isset($marks[$cid]);
            foreach ($c['users'] ?? [] as $u) if (($u['userId'] ?? '') === $sender['userId'] && empty($u['read'])) $unread = true;
            $ids = self::convLabels($c);
            $names = $ids === null ? [] : $this->labelNames($ids, $labels['all']);
            $ourLabel = array_values(array_intersect($names, array_keys(self::OUR_LABEL_OUTCOME)))[0] ?? null;
            if ($ourLabel !== null) { // a label set in lemlist counts in the Dashboard numbers too (for the conversation's lead)
                $link = $this->contactLink($cid, self::convHints($c));
                if (($link[1] ?? '') !== '' && !$this->store->one('SELECT 1 FROM outcomes WHERE lead_id = ?', [$link[1]])) $this->setOutcome($link[1], self::OUR_LABEL_OUTCOME[$ourLabel]);
            }
            $contact = is_array($c['contact'] ?? null) ? $c['contact'] : [];
            $all[] = ['c' => $c, 'cid' => $cid, 'unread' => $unread, 'labels' => $names, 'labelsKnown' => $ids !== null,
                'email' => strtolower((string) ($c['contactEmail'] ?? $contact['email'] ?? '')),
                'name' => trim((string) ($c['contactName'] ?? $contact['fullName'] ?? trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? ''))))];
        }
        $unreadCount = count(array_filter($all, fn ($x) => $x['unread']));
        try {
            JsonStore::write($this->store->dir . '/' . self::UNREAD_FILE, ['count' => $unreadCount, 'at' => ($this->now)()]); // the Inbox badge on every page
        } catch (\Throwable) {
        }
        if (!in_array($filter, self::FILTERS, true)) $filter = 'all';
        if ($filter === 'unread') $all = array_filter($all, fn ($x) => $x['unread']);
        elseif ($filter !== 'all') $all = array_filter($all, fn ($x) => in_array($filter, $x['labels'], true));
        $q = mb_strtolower(trim($q));
        $rows = [];
        foreach ($all as $x) {
            $c = $x['c'];
            $cid = $x['cid'];
            $hints = self::convHints($c);
            $link = $this->contactLink($cid, $hints);
            $lead = $this->leadRow($link, $cid, $hints);
            $company = (string) ($lead['company'] ?? $c['contactCompanyName'] ?? ($c['contact']['companyName'] ?? '') ?: '');
            if ($company === '') $company = (string) $this->store->one('SELECT company FROM contact_links WHERE contact_id = ?', [$cid]); // from the thread activities
            $name = $x['name'] ?: ($lead ? trim($lead['first_name'] . ' ' . $lead['last_name']) : '') ?: 'Unknown contact';
            if ($q !== '' && !str_contains(mb_strtolower($name . ' ' . $x['email'] . ' ' . $company), $q)) continue;
            $rows[] = ['x' => $x, 'link' => $link, 'lead' => $lead, 'company' => $company, 'name' => $name, 'hints' => $hints];
        }
        $total = count($rows);
        $page = max(1, $page);
        $slice = array_slice($rows, ($page - 1) * $per, $per);
        $out = [];
        foreach ($slice as $r) {
            $c = $r['x']['c'];
            $cid = $r['x']['cid'];
            if ($r['link'] === null && $linkReads > 0) { // campaign not known yet: read the conversation's latest activities once
                $linkReads--;
                $this->linkFromThread($cid);
                $r['link'] = $this->contactLink($cid, $r['hints']);
                $r['lead'] = $this->leadRow($r['link'], $cid, $r['hints']);
            }
            [$campaignId, $leadId] = $r['link'] ?? [null, null];
            $camp = $this->campaignInfo((string) $campaignId);
            if ($r['company'] === '') $r['company'] = (string) $this->store->one('SELECT company FROM contact_links WHERE contact_id = ?', [$cid]);
            // the last message, whoever wrote it (lemlist gives a preview of each side)
            $repAt = (string) ($c['lastRepliedAt'] ?? '');
            $sentAt = (string) ($c['lastSentAt'] ?? '');
            $ours = self::ts($sentAt) > self::ts($repAt);
            $preview = $ours ? ($c['lastSentMessagePreview'] ?? null) : ($c['lastRepliedMessagePreview'] ?? null);
            if (!is_string($preview) || trim($preview) === '') $preview = $ours ? ($c['lastRepliedMessagePreview'] ?? '') : ($c['lastSentMessagePreview'] ?? '');
            $channel = (string) ($ours ? ($c['lastSentChannel'] ?? $c['lastRepliedChannel'] ?? ($c['channels'][0] ?? 'email')) : ($c['lastRepliedChannel'] ?? ($c['channels'][0] ?? 'email')));
            $lead = $r['lead'];
            $out[] = ['contactId' => $cid, 'leadId' => $leadId ?: ($lead['lead_id'] ?? null), 'name' => $r['name'], 'company' => $r['company'],
                'campaignId' => $campaignId ?: null, 'campaign' => $camp['name'], 'campaignStatus' => $camp['status'],
                'inCampaign' => $campaignId === null ? null : $campaignId !== '', // null: not known yet
                'signal' => self::signalOf($lead), 'canLinkedin' => (bool) ($leadId ?: ($lead['lead_id'] ?? null)),
                'channel' => str_contains($channel, 'linkedin') ? 'linkedin' : (str_contains($channel, 'whatsapp') ? 'whatsapp' : (str_contains($channel, 'sms') ? 'sms' : 'email')),
                'unread' => $r['x']['unread'], 'at' => (string) ($c['lastActivityAt'] ?? max($repAt, $sentAt)), 'snippet' => self::snippet((string) $preview), 'ours' => $ours && trim((string) $preview) !== '',
                'labels' => $r['x']['labels'], 'label' => array_values(array_intersect($r['x']['labels'], LemlistGuard::LABELS))[0] ?? null, 'labelsKnown' => $r['x']['labelsKnown']];
        }
        return ['conversations' => $out, 'filter' => $filter, 'q' => $q, 'page' => $page, 'total' => $total, 'hasMore' => $page * $per < $total,
            'unread' => $unreadCount, 'labels' => array_keys($labels['ours'])];
    }

    /** "Mark as unread": lemlist has no API for it, so it is kept here (and counted in the badge) until the conversation is opened again. */
    public function markUnread(array $in): array
    {
        $contactId = is_string($in['contactId'] ?? null) ? $in['contactId'] : '';
        if (!preg_match('/^ctc_[A-Za-z0-9]{3,60}$/', $contactId)) throw new ValidationError('Unknown conversation');
        $now = ($this->now)();
        JsonStore::update($this->store->dir . '/' . self::UNREAD_MARKS, [], function ($d) use ($contactId, $now) {
            $d = array_filter(is_array($d) ? $d : [], fn ($t) => (int) $t > $now - 90 * 86400); // ids and times only
            $d[$contactId] = $now;
            return $d;
        });
        @unlink($this->store->dir . '/' . self::UNREAD_FILE);
        $this->audit('inbox_unread', ['contactId' => $contactId], 'ok');
        return ['ok' => true, 'unread' => true, 'message' => 'Marked as unread in Signal Desk (lemlist has no "unread" setting in its API).'];
    }

    /**
     * One thread, THREAD_PAGE activities at a time ($skip for "Load older"), oldest first on screen. Every activity of every channel is shown;
     * emails without their quoted history (the full text comes along for "Show full email"). Opening it marks it read in lemlist
     * (markAsRead, only when writes are on: it changes lemlist) and clears a "Mark as unread" made here.
     */
    public function thread(string $contactId, int $skip = 0): array
    {
        if (!preg_match('/^ctc_[A-Za-z0-9]{3,60}$/', $contactId)) throw new ValidationError('Unknown conversation');
        $skip = max(0, $skip);
        $sender = $this->sender() ?? ['userId' => (string) ($this->config['LEMLIST_SHARED_SENDER'] ?? '')];
        $q = ['userId' => $sender['userId'], 'limit' => self::THREAD_PAGE, 'skip' => $skip];
        if ($skip === 0 && $this->writesOn() && $this->sender()) $q['markAsRead'] = 'true';
        $r = $this->clientOrFail()->get('/inbox/' . $contactId, $q);
        $acts = array_values(array_filter(is_array($r['data'] ?? null) ? $r['data'] : [], 'is_array'));
        $hasOlder = count($acts) >= self::THREAD_PAGE && (!isset($r['pagination']) || !empty($r['pagination']['nextPage']) || (int) ($r['pagination']['totalItems'] ?? 0) > $skip + count($acts));
        $this->linkFromActivities($contactId, $acts, !$hasOlder);
        if ($skip === 0) {
            $marks = $this->unreadMarks();
            if (isset($marks[$contactId])) {
                JsonStore::update($this->store->dir . '/' . self::UNREAD_MARKS, [], function ($d) use ($contactId) {
                    $d = is_array($d) ? $d : [];
                    unset($d[$contactId]);
                    return $d;
                });
                @unlink($this->store->dir . '/' . self::UNREAD_FILE);
            }
        }
        $link = $this->contactLink($contactId);
        $lead = $this->leadRow($link, $contactId, ['leadIds' => array_values(array_unique(array_filter(array_map(fn ($m) => is_string($m['leadId'] ?? null) ? $m['leadId'] : '', $acts))))]);
        $leadName = $lead ? trim($lead['first_name'] . ' ' . $lead['last_name']) : '';
        $msgs = [];
        foreach ($acts as $m) {
            $type = (string) ($m['type'] ?? '');
            $out = self::isOutgoing($m);
            $full = self::messageText($m);
            $email = self::channelOf($type) === 'Email';
            $own = $email ? self::trimQuoted($full) : $full;
            $files = array_values(array_filter(array_map(fn ($f) => is_array($f) ? (string) ($f['name'] ?? $f['filename'] ?? $f['fileName'] ?? '') : (is_string($f) ? basename($f) : ''), (array) ($m['attachments'] ?? []))));
            $msgs[] = ['id' => (string) ($m['_id'] ?? $m['id'] ?? ''), 'out' => $out, 'type' => $type, 'channel' => self::channelOf($type), 'at' => (string) ($m['createdAt'] ?? $m['sentAt'] ?? ''),
                'who' => $out ? (string) ($m['sendUserName'] ?? 'You') : ($leadName !== '' ? $leadName : 'Contact'),
                'subject' => $email && is_string($m['subject'] ?? null) && $m['subject'] !== '' ? $m['subject'] : null,
                'text' => $own !== '' ? $own : ($full === '' ? Outreach::activityLabel($type) : ''), 'full' => $own !== $full ? $full : null,
                'attachments' => $files, 'hasAttachments' => !empty($m['hasAttachments']) || $files !== []];
        }
        usort($msgs, fn ($a, $b) => self::ts($a['at']) <=> self::ts($b['at'])); // oldest at the top
        [$campaignId, $leadId] = $link ?? [null, null];
        $camp = $this->campaignInfo((string) $campaignId);
        return ['contactId' => $contactId, 'leadId' => $leadId ?: ($lead['lead_id'] ?? null), 'name' => $leadName, 'jobTitle' => $lead['title'] ?? '',
            'company' => ($lead['company'] ?? '') ?: (string) $this->store->one('SELECT company FROM contact_links WHERE contact_id = ?', [$contactId]),
            'campaign' => $camp['name'], 'campaignStatus' => $camp['status'], 'inCampaign' => $campaignId === null ? null : $campaignId !== '',
            'signal' => self::signalOf($lead), 'messages' => $msgs, 'skip' => $skip, 'hasOlder' => $hasOlder,
            'canLinkedin' => (bool) ($leadId ?: ($lead['lead_id'] ?? null)), 'markedRead' => isset($q['markAsRead'])];
    }

    /** Reply by email (in the latest thread) or LinkedIn (campaign leads only). Confirm step required. */
    public function reply(array $in): array
    {
        $contactId = is_string($in['contactId'] ?? null) ? $in['contactId'] : '';
        $channel = (string) ($in['channel'] ?? '');
        $text = trim((string) ($in['message'] ?? ''));
        if (!preg_match('/^ctc_[A-Za-z0-9]{3,60}$/', $contactId)) throw new ValidationError('Unknown conversation');
        if (!in_array($channel, ['email', 'linkedin'], true)) throw new ValidationError('Choose Email or LinkedIn');
        if ($text === '') throw new ValidationError('Write a reply first.');
        if (mb_strlen($text) > self::MAX_MESSAGE) throw new ValidationError('The reply is too long (max ' . self::MAX_MESSAGE . ' characters).');
        if (!empty($in['attachments'])) throw new ValidationError('Attachments are not supported.');
        if (($in['confirm'] ?? null) !== true) throw new ValidationError('Confirm before sending.');
        $s = $this->senderOrFail();
        $link = $this->contactLink($contactId);
        $leadId = ($link[1] ?? '') !== '' ? $link[1] : ($this->leadsFor($contactId)[0]['lead_id'] ?? null);
        if ($channel === 'linkedin') {
            if (!$leadId) throw new ValidationError('LinkedIn replies need the lead of a campaign. Reply by email instead.');
            $req = ['POST', '/inbox/linkedin', ['sendUserId' => $s['userId'], 'leadId' => (string) $leadId, 'contactId' => $contactId, 'message' => $text]];
        } else {
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
            $req = ['POST', '/inbox/email', ['sendUserId' => $s['userId'], 'sendUserEmail' => $s['email'], 'sendUserMailboxId' => $this->writesOn() ? $this->mailbox($s) : 'usm_preview',
                'contactId' => $contactId, 'message' => $html, 'replyToActivityId' => 'latest']];
        }
        $ids = ['contactId' => $contactId, 'leadId' => $leadId, 'channel' => $channel];
        if (!$this->writesOn()) return $this->preview('reply', [$req], $ids);
        $this->limiter->hit($this->user, 'send');
        $this->clientOrFail()->write(...$req);
        $this->audit('reply', $ids, 'ok');
        return ['ok' => true, 'message' => 'Sent through lemlist.'];
    }

    /** The sender's connected mailbox (needed by POST /inbox/email), from the user map or GET /users/{id}. */
    private function mailbox(array $s): string
    {
        if (!empty($s['mailboxId'])) return (string) $s['mailboxId'];
        $cached = $this->store->meta('mailbox_' . $s['userId']);
        if (is_string($cached) && $cached !== '') return $cached;
        $u = $this->clientOrFail()->get('/users/' . $s['userId']);
        foreach ($u['mailboxes'] ?? [] as $m) {
            if (strtoupper((string) ($m['status'] ?? '')) === 'CONNECTED' && (string) ($m['_id'] ?? '') !== '') {
                $this->store->setMeta('mailbox_' . $s['userId'], (string) $m['_id']);
                return (string) $m['_id'];
            }
        }
        throw new AppError(409, 'NO_MAILBOX', 'The lemlist sender has no connected mailbox. Connect it in lemlist first.');
    }

    /**
     * Set one of our 4 labels (or none) on a conversation, keeping every other label: appendLabels:false with
     * (current labels minus our 4) + the chosen one. When lemlist does not return the current labels, only adding is allowed (appendLabels:true).
     */
    public function label(array $in): array
    {
        $contactId = is_string($in['contactId'] ?? null) ? $in['contactId'] : '';
        $label = $in['label'] ?? null;
        if (!preg_match('/^ctc_[A-Za-z0-9]{3,60}$/', $contactId)) throw new ValidationError('Unknown conversation');
        if ($label !== null && !in_array($label, LemlistGuard::LABELS, true)) throw new ValidationError('Only the labels ' . implode(', ', LemlistGuard::LABELS) . ' can be set here.');
        $s = $this->senderOrFail();
        $labels = $this->writesOn() ? $this->labels(true) : $this->labels(false);
        if ($label !== null && !isset($labels['ours'][$label]) && $this->writesOn()) throw new AppError(409, 'NO_LABEL', "The lemlist label \"$label\" could not be found or created.");
        $conv = null;
        $this->convs = null; // the conversation's labels as they are now
        foreach ($this->conversations() as $c) if (($c['contactId'] ?? '') === $contactId) $conv = $c;
        $current = $conv ? self::convLabels($conv) : null;
        $ourIds = array_values($labels['ours']);
        $chosen = $label !== null ? ($labels['ours'][$label] ?? 'lbl_new') : null;
        if ($current !== null) {
            $keep = array_values(array_diff($current, $ourIds));
            $body = ['labelIds' => array_values(array_unique(array_merge($keep, $chosen ? [$chosen] : []))), 'appendLabels' => false];
        } elseif ($chosen !== null) {
            $body = ['labelIds' => [$chosen], 'appendLabels' => true];
        } else {
            throw new ValidationError('lemlist did not return this conversation\'s labels, so removing a label is not safe here. Remove it in lemlist.');
        }
        $req = ['POST', '/inbox/conversations/labels/' . $contactId, $body];
        $link = $this->contactLink($contactId, $conv ? self::convHints($conv) : []);
        $lead = ($link[1] ?? '') !== '' ? $link[1] : null;
        $ids = ['contactId' => $contactId, 'leadId' => $lead, 'label' => $label ?? 'none'];
        if (!$this->writesOn()) return $this->preview('label', [$req], $ids);
        $this->limiter->hit($this->user, 'label');
        $this->clientOrFail()->write(...$req);
        if ($lead && $label !== null && isset(self::OUR_LABEL_OUTCOME[$label])) $this->setOutcome($lead, self::OUR_LABEL_OUTCOME[$label]);
        $this->audit('label', $ids, 'ok');
        return ['ok' => true, 'label' => $label, 'message' => $label ? "Label \"$label\" set in lemlist." : 'Label removed in lemlist.'];
    }

    public const UNREAD_FILE = 'inbox-unread.json';
    public const UNREAD_TTL = 120;

    /** The Inbox badge as last counted (unread conversations; no lemlist request), null before the first count. */
    public static function cachedUnread(string $dir): ?int
    {
        $c = JsonStore::read("$dir/" . self::UNREAD_FILE, null);
        return is_array($c) && isset($c['count']) ? (int) $c['count'] : null;
    }

    /** The Inbox badge (unread conversations, as lemlist): re-counted at most every 2 minutes, at once after a reply webhook or a "Mark as unread". */
    public function unread(): array
    {
        $file = $this->store->dir . '/' . self::UNREAD_FILE;
        $c = JsonStore::read($file, []) ?: [];
        $at = (int) ($c['at'] ?? 0);
        if ($at <= ($this->now)() - self::UNREAD_TTL || (int) $this->store->live('inbox_event_at', 0) > $at) {
            $this->inbox('all', '', 1, self::PAGE_SIZE, 0);
            $c = JsonStore::read($file, []) ?: [];
        }
        return ['badge' => (int) ($c['count'] ?? 0), 'unread' => (int) ($c['count'] ?? 0), 'at' => (int) ($c['at'] ?? 0)];
    }

    /** Cheap poll (local database only): when did the webhook last bring news? */
    public function updates(): array
    {
        return ['eventAt' => $this->store->live('last_event_at'), 'inboxAt' => $this->store->live('inbox_event_at'), 'tasksAt' => $this->store->live('tasks_event_at'),
            'badge' => self::dueCount($this->store)];
    }
}

/**
 * Calls tab lead search and the Job advert link. Pure helpers over the local lemlist database (read-only):
 * accent- and case-insensitive text, phones compared on their last 9 digits, the job URL from the lead's variables or the matching signal.
 */
final class LeadSearch
{
    /** lemlist variables that hold the job advert, in order (names matched ignoring case and spaces). Live campaigns use "Job URL" and "Source URL". */
    public const JOB_URL_FIELDS = ['Job URL', 'jobUrl', 'Job Link', 'jobLink', 'Source URL'];
    /** The vacancy title among the lead's variables (the lead's own jobTitle is the person's title). */
    public const POSITION_FIELDS = ['Position', 'Job Title Vacancy', 'Vacancy'];
    public const OTHER_PHONES = ['phone1', 'phone2', 'phone3', 'phone4'];
    private const FOLD = ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ď' => 'd', 'đ' => 'd', 'ð' => 'd', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i', 'ł' => 'l', 'ľ' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o', 'œ' => 'oe', 'ŕ' => 'r', 'ř' => 'r', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ș' => 's',
        'ß' => 'ss', 'ť' => 't', 'ţ' => 't', 'ț' => 't', 'þ' => 'th', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z', '’' => "'", '‘' => "'"];

    /** Lowercase without accents: "Sørensen" -> "sorensen", "Dupré" -> "dupre". */
    public static function fold(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), self::FOLD);
    }

    /** @return list<string> the folded words of a text query */
    public static function tokens(string $q): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim(self::fold($q))) ?: [], fn ($t) => $t !== ''));
    }

    public static function textMatch(array $tokens, array $lead): bool
    {
        if (!$tokens) return false;
        $hay = self::fold(implode(' ', [$lead['first_name'] ?? '', $lead['last_name'] ?? '', $lead['company'] ?? '', $lead['email'] ?? '', $lead['title'] ?? '']));
        foreach ($tokens as $t) if (!str_contains($hay, $t)) return false;
        return true;
    }

    /** A phone number's digits without the international 00 / trunk 0 prefixes, last 9 kept: "+32 470 12 34 56", "0032470123456", "0470123456" -> "470123456". */
    public static function phoneKey(string $p): string
    {
        return substr(ltrim((string) preg_replace('/\D/', '', $p), '0'), -9);
    }

    /**
     * A phone query ("+32 470 12 34 56", "0032470123456", "0470123456", "470 12"): its key, or null when the query is not a phone number.
     * 8 digits or more: a whole number, compared on its last 9 digits (a Belgian landline has 8: 02 555 01 01). Fewer: a part of the number.
     */
    public static function phoneQuery(string $q): ?array
    {
        if (!preg_match('~^\+?[\d\s().\/-]+$~', trim($q)) || strlen((string) preg_replace('/\D/', '', $q)) < 2) return null;
        $key = self::phoneKey($q);
        return $key === '' ? null : ['full' => strlen($key) >= 8, 'key' => $key];
    }

    /** Does any of the lead's phones match? Spaces, dashes, brackets and the prefix are ignored: the last 9 digits are compared. */
    public static function phoneMatch(array $query, array $phones): bool
    {
        foreach ($phones as $p) {
            $k = self::phoneKey((string) $p);
            if (strlen($k) < 6) continue;
            $hit = $query['full'] ? (strlen($k) >= 8 && (str_ends_with($k, $query['key']) || str_ends_with($query['key'], $k))) : str_contains($k, $query['key']);
            if ($hit) return true;
        }
        return false;
    }

    /** phone1-4 of an exported lead, one per line (searched like the main phone). */
    public static function otherPhones(array $lead): ?string
    {
        $out = [];
        $by = [];
        foreach ($lead as $k => $v) if (is_string($k) && (is_string($v) || is_int($v))) $by[strtolower($k)] = trim((string) $v);
        foreach (self::OTHER_PHONES as $n) if (($by[$n] ?? '') !== '') $out[] = mb_substr($by[$n], 0, 60);
        return $out ? implode("\n", $out) : null;
    }

    /** OutreachBusiness::companyKey plus a few more trailing legal forms (ApS, A/S, AB, Oy, SAS, SARL, SpA, KG, SE, SL, VZW, ASBL, CommV). */
    public static function companyKey(string $name): string
    {
        $s = trim(self::fold($name));
        for ($i = 0; $i < 2; $i++) $s = (string) preg_replace('~[\s,]+(aps|a/s|as|ab|oy|sas|sarl|spa|kg|se|sl|vzw|asbl|commv)\.?$~u', '', $s);
        return OutreachBusiness::companyKey($s);
    }

    /** "Data Architect (m/f/x)" -> "data architect" */
    public static function titleKey(string $title): string
    {
        $s = self::fold($title);
        $s = (string) preg_replace('~\(([mfvhx]\s*/\s*)+[mfvhx]\)|\b([mfvhx]\s*/\s*){1,2}[mfvhx]\b~u', ' ', $s);
        return trim((string) preg_replace('~[^a-z0-9]+~', ' ', $s));
    }

    private static function isUrl(?string $u): bool
    {
        return $u !== null && preg_match('~^https?://~i', $u) && filter_var($u, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * The job advert for a lead: (a) a lemlist variable holding the job URL (JOB_URL_FIELDS), else (b) the matching signal in Signal Desk:
     * the decision maker's own row (linked by LinkedIn), else the one signal with the same company + job title. null = no job link.
     * @return array{url:string, from:string}|null
     */
    public static function jobUrl(LemlistStore $store, ?array $lead): ?array
    {
        if (!$lead) return null;
        foreach (self::JOB_URL_FIELDS as $n) {
            $v = LeadVars::field($lead, [$n]);
            if (self::isUrl($v)) return ['url' => $v, 'from' => 'lemlist variable "' . self::realName($lead, $n) . '"'];
        }
        $s = self::signal($store, $lead);
        if ($s && self::isUrl($s['jobUrl'])) return ['url' => $s['jobUrl'], 'from' => 'Signal Desk signal (' . $s['tab'] . ' row ' . $s['row'] . ')'];
        return null;
    }

    private static function realName(array $lead, string $n): string
    {
        foreach (array_keys(json_decode((string) ($lead['fields'] ?? ''), true) ?: []) as $k) {
            if (strtolower((string) preg_replace('/\s+/', '', (string) $k)) === strtolower((string) preg_replace('/\s+/', '', $n))) return (string) $k;
        }
        return $n;
    }

    /**
     * The original signal behind a lead: the approved decision-maker row it is linked to (LinkedIn), else the single workbook signal with the
     * same company and job title (the lead's "Position" variable, else its title). Several different job URLs for that pair = no match.
     */
    public static function signal(LemlistStore $store, array $lead): ?array
    {
        if (trim((string) ($lead['signal_job_title'] ?? '')) !== '') {
            return ['track' => $lead['signal_track'] ?: 'IT', 'company' => (string) $lead['signal_company'], 'jobTitle' => (string) $lead['signal_job_title'],
                'jobUrl' => (string) $lead['signal_job_url'], 'roleGroup' => (string) $lead['signal_role_group'], 'dateFound' => $lead['signal_date_found'],
                'tab' => $lead['signal_track'] === 'HR' ? Sheet::HR_DECISION_MAKERS : Sheet::DECISION_MAKERS, 'row' => (int) $lead['signal_row'], 'match' => 'decision maker (LinkedIn)'];
        }
        $ck = self::companyKey((string) ($lead['company'] ?? ''));
        if ($ck === '') return null;
        foreach (array_filter([LeadVars::field($lead, self::POSITION_FIELDS), (string) ($lead['title'] ?? '')]) as $title) {
            $tk = self::titleKey($title);
            if ($tk === '') continue;
            $rows = $store->all('SELECT * FROM signal_jobs WHERE company_key = ? AND title_key = ? ORDER BY (tab = ?) DESC, (tab = ?) DESC, row', [$ck, $tk, Sheet::DECISION_MAKERS, Sheet::HR_DECISION_MAKERS]);
            if (!$rows) continue;
            $urls = array_unique(array_filter(array_map(fn ($r) => trim((string) $r['job_url']), $rows)));
            if (count($urls) > 1) return null; // the same title at the same company with different adverts: not sure which one
            $r = $rows[0];
            foreach ($rows as $x) if (trim((string) $x['job_url']) !== '') { $r = $x; break; }
            return ['track' => $r['track'], 'company' => $r['company'], 'jobTitle' => $r['job_title'], 'jobUrl' => (string) $r['job_url'], 'roleGroup' => (string) $r['role_group'],
                'dateFound' => $r['date_found'], 'tab' => $r['tab'], 'row' => (int) $r['row'], 'match' => 'same company + job title'];
        }
        return null;
    }

    /** Rebuild signal_jobs from the workbook (read-only): Decision Makers, HR Decision Makers, the verify tab, Hr signals and Signals. */
    public static function indexSignals(LemlistStore $store, callable $readTab): int
    {
        $rows = [];
        $add = function (string $tab, string $track, int $row, string $company, string $title, string $url, ?string $date, string $group) use (&$rows) {
            $ck = self::companyKey($company);
            $tk = self::titleKey($title);
            if ($ck === '' || $tk === '') return;
            $rows[] = [$ck, $tk, mb_substr($company, 0, 200), mb_substr($title, 0, 200), mb_substr($url, 0, 600), $track, $tab, $row, $date, mb_substr($group, 0, 120)];
        };
        foreach ([Sheet::DECISION_MAKERS => 'IT', Sheet::HR_DECISION_MAKERS => 'HR'] as $tab => $track) {
            foreach ($readTab($tab) as $r) {
                if ($r['row'] < 2) continue;
                $c = $r['cells'];
                $add($tab, $track, $r['row'], Sheet::display($c[3] ?? ''), Sheet::display($c[5] ?? ''), trim(Sheet::display($c[Sheet::DMT_JOB_URL] ?? '')), ExcelDate::cellToIso($c[2] ?? null), Sheet::display($c[6] ?? ''));
            }
        }
        foreach ([Sheet::VERIFY => 'IT', Sheet::HR => 'HR', Sheet::SIGNALS => 'IT'] as $tab => $track) {
            foreach ($readTab($tab) as $r) {
                if ($r['row'] < 2 || !Sheet::isDataRow($r['cells'])) continue;
                $c = $r['cells'];
                $add($tab, $track, $r['row'], Sheet::display($c[Sheet::COMPANY] ?? ''), Sheet::display($c[Sheet::JOB_TITLE] ?? ''), trim(Sheet::display($c[Sheet::JOB_URL] ?? '')),
                    ExcelDate::cellToIso($c[Sheet::DATE_FOUND] ?? null), Sheet::display($c[Sheet::ROLE_GROUP] ?? ''));
            }
        }
        $store->write(function () use ($store, $rows) {
            $store->db->exec('DELETE FROM signal_jobs');
            $ins = $store->db->prepare('INSERT INTO signal_jobs (company_key, title_key, company, job_title, job_url, track, tab, row, date_found, role_group) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $r) $ins->execute($r);
        });
        return count($rows);
    }
}
