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
    public const LIMITS = ['send' => 30, 'call' => 100, 'task' => 200, 'label' => 100];

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

final class CallDesk
{
    public const CALL_TITLE = '/^\s*(call|calling again|final call)\b/i';
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
            foreach ($r['results'] ?? [] as $t) LemlistSync::storeTaskRow($this->store, $t, $now);
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
            'linkedin' => $l['linkedin'] ?? null,
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
        $vars = ['firstName' => $l['first_name'] ?? '', 'lastName' => $l['last_name'] ?? '', 'companyName' => $l['company'] ?? '', 'jobTitle' => $l['title'] ?? '',
            'linkedinUrl' => $l['linkedin'] ?? ''];
        $fill = fn (string $s) => preg_replace_callback('/\{\{\s*([A-Za-z]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $vars) && $vars[$m[1]] !== '' ? $vars[$m[1]] : $m[0], $s) ?? $s;
        $subject = trim((string) $pick['title']) !== '' && $t['type'] === 'email' ? $fill((string) $pick['title']) : null;
        return ['subject' => $subject, 'text' => $fill(self::plain((string) $pick['message']))];
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
        return (int) $store->one("SELECT COUNT(*) FROM tasks WHERE status = 'due' AND (type = 'phone' OR type = 'manual' OR manual = 1)");
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
        $rows = $this->store->all('SELECT * FROM call_statuses ORDER BY position');
        $map = fn ($r) => ['key' => $r['key'], 'label' => $r['label'], 'positive' => (bool) $r['positive'], 'negative' => (bool) $r['negative'], 'pauses' => (bool) $r['pauses']];
        return ['callsApi' => $this->store->meta('calls_api') !== 'no',
            'answered' => array_values(array_map($map, array_filter($rows, fn ($r) => (int) $r['answered'] === 1))),
            'notAnswered' => array_values(array_map($map, array_filter($rows, fn ($r) => (int) $r['answered'] === 0)))];
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
            if ($k === 'phone' || $k === 'manualCall') $queue[] = $b + ['context' => $this->context($x)];
            if ($x['task']['type'] !== 'phone' && ($x['task']['type'] === 'manual' || (int) $x['task']['manual'] === 1)) {
                $m = $b + ['manualType' => self::manualType($x['task'])];
                if ($k === 'message') $m['preview'] = $this->messagePreview($x);
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
            'eventAt' => $this->store->meta('last_event_at'), 'refreshedAt' => $this->store->meta('tasks_refreshed_at'),
        ];
    }

    /** Today's calls logged here: done / answered / positive. */
    public function today(): array
    {
        $start = (new \DateTimeImmutable('@' . ($this->now)()))->setTimezone(new \DateTimeZone($this->tz))->setTime(0, 0)->getTimestamp();
        $st = [];
        foreach ($this->store->all('SELECT * FROM call_statuses') as $r) $st[$r['key']] = $r;
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
        $st = $this->store->all('SELECT * FROM call_statuses WHERE key = ?', [$statusKey])[0] ?? throw new ValidationError('Pick an outcome from the list.');
        if ((bool) (int) $st['answered'] !== $answered) throw new ValidationError('That outcome does not match "' . ($answered ? 'Answered' : 'Not answered') . '".');
        $due = null;
        if ($callback !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $callback, new \DateTimeZone($this->tz));
            $today = (new \DateTimeImmutable('@' . ($this->now)()))->setTimezone(new \DateTimeZone($this->tz))->setTime(0, 0);
            if (!$d || $d->format('Y-m-d') !== $callback || $d <= $today || $d > $today->modify('+366 days')) throw new ValidationError('Pick a call-back date after today (within a year).');
            $due = $d->setTime(9, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
        }
        $leadId = (string) $task['lead_id'];
        $externalId = 'sd-' . substr($taskId, 4) . '-' . $attempt;
        $call = ['externalId' => $externalId, 'userId' => $sender['userId'], 'direction' => 'outbound', 'startedAt' => gmdate('Y-m-d\TH:i:s\Z', ($this->now)()),
            'callStatus' => $statusKey, 'provider' => 'signal-desk'];
        if ($note !== '') $call['note'] = $note;
        if ($kind === 'phone' && $due === null) $call['taskId'] = $taskId;
        elseif ($leadId !== '') $call['leadId'] = $leadId;
        else $call['contactId'] = (string) $task['contact_id'];
        $requests = [['POST', '/calls', $call]];
        if ($due !== null) $requests[] = ['PATCH', '/tasks', ['id' => $taskId, 'dueDate' => $due]];
        elseif ($kind === 'manualCall') $requests[] = ['PATCH', '/tasks', ['id' => $taskId, 'done' => true]];
        $ids = ['taskId' => $taskId, 'leadId' => $leadId, 'callStatus' => $statusKey];
        if (!$this->writesOn()) return $this->preview('call_log', $requests, $ids) + ['next' => true];
        if ($this->store->meta('calls_api') === 'no') throw new AppError(402, 'LEMLIST_PLAN', 'Calls API needs Email Pro+: log this call in lemlist itself.');
        $this->limiter->hit($this->user, 'call');
        $client = $this->clientOrFail();
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
            $this->store->db->prepare('INSERT INTO calls (external_id, lemlist_id, task_id, lead_id, user_id, by_user, call_status, started_at, created_at, answered, task_done, callback_at, kind)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(external_id) DO UPDATE SET lemlist_id = excluded.lemlist_id')
                ->execute([$externalId, (string) ($r['data']['_id'] ?? 'logged'), $taskId, $leadId, $sender['userId'], $this->user, $statusKey, $call['startedAt'], ($this->now)(),
                    (int) $answered, $kind === 'phone' && $due === null ? 1 : 0, $due, $kind]);
        }
        try {
            foreach (array_slice($requests, 1) as [$m, $p, $b]) $client->write($m, $p, $b);
        } catch (AppError $e) {
            $this->audit('call_log', $ids, 'task_update_failed');
            throw new AppError($e->status, $e->errCode, 'The call is logged in lemlist, but updating the task failed (' . $e->getMessage() . '). Click Save again: the call will not be logged twice.');
        }
        $this->store->db->prepare('UPDATE calls SET task_done = 1 WHERE external_id = ?')->execute([$externalId]);
        if ($due === null) $this->store->db->prepare("UPDATE tasks SET status = 'closed' WHERE id = ?")->execute([$taskId]);
        else $this->store->db->prepare("UPDATE tasks SET due_date = ?, status = 'upcoming' WHERE id = ?")->execute([$due, $taskId]);
        if ($leadId !== '' && ((int) $st['positive'] || (int) $st['negative'])) $this->setOutcome($leadId, (int) $st['positive'] ? 'Interested' : 'Not interested');
        $this->audit('call_log', $ids, $due ? 'logged_callback' : 'logged');
        $this->refreshAfterAction();
        return ['ok' => true, 'next' => true, 'message' => $due ? 'Call logged; the task moves to ' . $callback . '.' : 'Call logged and task completed in lemlist.', 'today' => $this->today()];
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

    /** Conversations (live from lemlist, nothing stored) with lead, campaign, channel, unread and labels. */
    public function inbox(string $filter = 'all'): array
    {
        $sender = $this->sender() ?? ['userId' => (string) ($this->config['LEMLIST_SHARED_SENDER'] ?? '')];
        $client = $this->clientOrFail();
        $r = $client->get('/inbox', ['userId' => $sender['userId'], 'page' => 1, 'limit' => 50]);
        $labels = $this->labels(false);
        $names = array_column($this->store->all('SELECT id, name FROM campaigns'), 'name', 'id');
        $out = [];
        foreach ($r['data'] ?? [] as $c) {
            $cid = (string) ($c['contactId'] ?? '');
            if ($cid === '') continue;
            $lead = $this->store->all('SELECT * FROM leads WHERE contact_id = ? ORDER BY added_at DESC LIMIT 1', [$cid])[0] ?? null;
            $unread = false;
            foreach ($c['users'] ?? [] as $u) if (($u['userId'] ?? '') === $sender['userId'] && empty($u['read'])) $unread = true;
            $ids = self::convLabels($c);
            $names2 = $ids === null ? [] : $this->labelNames($ids, $labels['all']);
            $ourLabel = null;
            foreach ($names2 as $n) if (in_array($n, LemlistGuard::LABELS, true)) $ourLabel = $n;
            $last = $c['messages'][count($c['messages'] ?? []) - 1] ?? null;
            $snippet = $last ? mb_substr(self::plain((string) ($last['body'] ?? $last['message'] ?? '')), 0, 120) : '';
            $channel = (string) ($c['lastRepliedChannel'] ?? ($c['channels'][0] ?? 'email'));
            $name = trim((string) ($c['contact']['fullName'] ?? '')) ?: ($lead ? trim($lead['first_name'] . ' ' . $lead['last_name']) : '') ?: 'Unknown contact';
            $out[] = ['contactId' => $cid, 'leadId' => $lead['lead_id'] ?? null, 'name' => $name, 'company' => $lead['company'] ?? '', 'campaign' => $lead ? ($names[$lead['campaign_id']] ?? '') : '',
                'channel' => str_contains($channel, 'linkedin') ? 'linkedin' : 'email', 'unread' => $unread, 'at' => (string) ($c['lastActivityAt'] ?? $c['lastRepliedAt'] ?? ''),
                'label' => $ourLabel, 'labelsKnown' => $ids !== null, 'snippet' => $snippet];
        }
        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));
        $unread = count(array_filter($out, fn ($c) => $c['unread']));
        if ($filter === 'unread') $out = array_values(array_filter($out, fn ($c) => $c['unread']));
        elseif (in_array($filter, ['Interested', 'Meeting'], true)) $out = array_values(array_filter($out, fn ($c) => $c['label'] === $filter));
        return ['conversations' => $out, 'unread' => $unread, 'labels' => array_keys($labels['ours'])];
    }

    /** One thread. Marks it read in lemlist only when writes are on (it changes lemlist state). */
    public function thread(string $contactId): array
    {
        if (!preg_match('/^ctc_[A-Za-z0-9]{3,60}$/', $contactId)) throw new ValidationError('Unknown conversation');
        $sender = $this->sender() ?? ['userId' => (string) ($this->config['LEMLIST_SHARED_SENDER'] ?? '')];
        $q = ['userId' => $sender['userId'], 'limit' => 50];
        if ($this->writesOn() && $this->sender()) $q['markAsRead'] = 'true';
        $r = $this->clientOrFail()->get('/inbox/' . $contactId, $q);
        $lead = $this->store->all('SELECT * FROM leads WHERE contact_id = ? ORDER BY added_at DESC LIMIT 1', [$contactId])[0] ?? null;
        $msgs = [];
        foreach ($r['data'] ?? [] as $m) {
            $type = (string) ($m['type'] ?? '');
            $out = preg_match('/Sent$|Done$/', $type) === 1 || (!empty($m['sendUserId']) && !preg_match('/Replied$/', $type));
            $msgs[] = ['out' => $out, 'channel' => str_starts_with($type, 'linkedin') ? 'LinkedIn' : 'Email', 'at' => (string) ($m['createdAt'] ?? $m['sentAt'] ?? ''),
                'who' => $out ? (string) ($m['sendUserName'] ?? 'You') : ($lead ? trim($lead['first_name'] . ' ' . $lead['last_name']) : 'Contact'),
                'subject' => isset($m['subject']) ? (string) $m['subject'] : null, 'text' => self::plain((string) ($m['message'] ?? $m['body'] ?? ''))];
        }
        usort($msgs, fn ($a, $b) => strcmp($a['at'], $b['at']));
        $names = array_column($this->store->all('SELECT id, name FROM campaigns'), 'name', 'id');
        return ['contactId' => $contactId, 'leadId' => $lead['lead_id'] ?? null,
            'name' => $lead ? trim($lead['first_name'] . ' ' . $lead['last_name']) : '', 'jobTitle' => $lead['title'] ?? '', 'company' => $lead['company'] ?? '',
            'campaign' => $lead ? ($names[$lead['campaign_id']] ?? '') : '',
            'signal' => $lead && $lead['signal_job_title'] ? ($lead['signal_track'] ?: 'IT') . ' · ' . $lead['signal_job_title'] : null,
            'messages' => $msgs, 'canLinkedin' => $lead !== null, 'markedRead' => isset($q['markAsRead'])];
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
        $lead = $this->store->all('SELECT * FROM leads WHERE contact_id = ? ORDER BY added_at DESC LIMIT 1', [$contactId])[0] ?? null;
        if ($channel === 'linkedin') {
            if (!$lead) throw new ValidationError('LinkedIn replies work for campaign leads only. Reply by email instead.');
            $req = ['POST', '/inbox/linkedin', ['sendUserId' => $s['userId'], 'leadId' => (string) $lead['lead_id'], 'contactId' => $contactId, 'message' => $text]];
        } else {
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
            $req = ['POST', '/inbox/email', ['sendUserId' => $s['userId'], 'sendUserEmail' => $s['email'], 'sendUserMailboxId' => $this->writesOn() ? $this->mailbox($s) : 'usm_preview',
                'contactId' => $contactId, 'message' => $html, 'replyToActivityId' => 'latest']];
        }
        $ids = ['contactId' => $contactId, 'leadId' => $lead['lead_id'] ?? null, 'channel' => $channel];
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
        $r = $this->clientOrFail()->get('/inbox', ['userId' => $s['userId'], 'page' => 1, 'limit' => 50]);
        foreach ($r['data'] ?? [] as $c) if (($c['contactId'] ?? '') === $contactId) $conv = $c;
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
        $lead = $this->store->all('SELECT lead_id FROM leads WHERE contact_id = ? ORDER BY added_at DESC LIMIT 1', [$contactId])[0]['lead_id'] ?? null;
        $ids = ['contactId' => $contactId, 'leadId' => $lead, 'label' => $label ?? 'none'];
        if (!$this->writesOn()) return $this->preview('label', [$req], $ids);
        $this->limiter->hit($this->user, 'label');
        $this->clientOrFail()->write(...$req);
        if ($lead && $label !== null && isset(self::OUR_LABEL_OUTCOME[$label])) $this->setOutcome($lead, self::OUR_LABEL_OUTCOME[$label]);
        $this->audit('label', $ids, 'ok');
        return ['ok' => true, 'label' => $label, 'message' => $label ? "Label \"$label\" set in lemlist." : 'Label removed in lemlist.'];
    }

    /** Cheap poll (local database only): when did the webhook last bring news? */
    public function updates(): array
    {
        return ['eventAt' => $this->store->meta('last_event_at'), 'inboxAt' => $this->store->meta('inbox_event_at'), 'tasksAt' => $this->store->meta('tasks_event_at'),
            'badge' => self::dueCount($this->store)];
    }
}
