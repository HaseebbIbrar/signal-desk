<?php
// lemlist (https://developer.lemlist.com): outreach data for the Outreach and Calls tabs.
//
//   LemlistGuard   GET allowlist + an explicit WRITE list (anything else is refused before a request is made):
//                    POST /calls · PATCH /tasks {id, done:true} | {id, dueDate} · POST /tasks/ignore {ids}
//                    POST /inbox/email · POST /inbox/linkedin · POST /inbox/conversations/labels/{contactId}
//                    POST /inbox/labels (our 4 labels only) · POST /hooks, DELETE /hooks/{id} (setup script only)
//   LemlistClient  Basic auth (empty user, key as password), ~8 requests/s (lemlist allows 20 per 2 s), Retry-After on 429,
//                  audit log of method + endpoint pattern + status only (never bodies, keys or personal data)
//   LemlistStore   storage/lemlist.sqlite: campaigns, steps, leads (the ONLY place lead emails/phones are kept),
//                  activities, webhook events (ids and types only), tasks, call statuses, stats; 12-month pruning
//   LemlistSender  who may send/log as which lemlist user (shared sender or per-user map; others view only)
declare(strict_types=1);

namespace SignalDesk;

final class LemlistRateLimited extends AppError
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(429, 'LEMLIST_RATE_LIMITED', "lemlist rate limit reached. Try again in about $retryAfter seconds.");
    }
}

final class LemlistGuard
{
    private const ID = '[A-Za-z0-9_-]{3,64}';
    /** pattern label => regex. GET only. */
    private const READS = [
        '/team' => '#^/team$#', '/users/{id}' => '#^/users/' . self::ID . '$#', '/user/channels' => '#^/user/channels$#', '/senders' => '#^/senders$#',
        '/campaigns' => '#^/campaigns$#', '/campaigns/{id}' => '#^/campaigns/' . self::ID . '$#',
        '/campaigns/{id}/sequences' => '#^/campaigns/' . self::ID . '/sequences$#',
        '/v2/campaigns/{id}/stats' => '#^/v2/campaigns/' . self::ID . '/stats$#',
        '/v2/campaigns/{id}/export/leads' => '#^/v2/campaigns/' . self::ID . '/export/leads$#',
        '/activities' => '#^/activities$#', '/v2/tasks' => '#^/v2/tasks$#', '/call-statuses' => '#^/call-statuses$#',
        '/calls/{id}' => '#^/calls/' . '[A-Za-z0-9_.:-]{3,128}' . '$#', '/leads' => '#^/leads$#',
        '/inbox' => '#^/inbox$#', '/inbox/labels' => '#^/inbox/labels$#', '/inbox/{id}' => '#^/inbox/' . self::ID . '$#',
        '/hooks' => '#^/hooks$#',
    ];
    /** "METHOD pattern" => regex. The only writes the app can ever send. */
    private const WRITES = [
        'POST /calls' => '#^/calls$#',
        'PATCH /tasks' => '#^/tasks$#',
        'POST /tasks/ignore' => '#^/tasks/ignore$#',
        'POST /inbox/email' => '#^/inbox/email$#',
        'POST /inbox/linkedin' => '#^/inbox/linkedin$#',
        'POST /inbox/conversations/labels/{id}' => '#^/inbox/conversations/labels/' . self::ID . '$#',
        'POST /inbox/labels' => '#^/inbox/labels$#',
        'POST /hooks' => '#^/hooks$#',
        'DELETE /hooks/{id}' => '#^/hooks/' . self::ID . '$#',
    ];
    /** The only labels the app may create in lemlist. */
    public const LABELS = ['Interested', 'Meeting', 'Not interested', 'Out of office'];

    /** @return string endpoint pattern, safe for logs */
    public static function assert(string $method, string $path, ?array $body = null, bool $setup = false): string
    {
        $method = strtoupper($method);
        if ($method === 'GET') {
            foreach (self::READS as $label => $re) if (preg_match($re, $path)) return $label;
            throw new ForbiddenWriteError('Refused: this lemlist endpoint is not on the read allowlist');
        }
        $op = null;
        foreach (self::WRITES as $label => $re) {
            if (str_starts_with($label, $method . ' ') && preg_match($re, $path)) { $op = $label; break; }
        }
        if ($op === null) throw new ForbiddenWriteError("Refused: Signal Desk never sends $method $path to lemlist");
        $body ??= [];
        $keys = array_keys($body);
        $only = function (array $allowed, array $required) use ($keys, $op): void {
            $extra = array_diff($keys, $allowed);
            $missing = array_diff($required, $keys);
            if ($extra || $missing) throw new ForbiddenWriteError("Refused: $op" . ($extra ? ' does not take ' . implode(', ', $extra) : '') . ($missing ? ' needs ' . implode(', ', $missing) : ''));
        };
        $text = fn ($v, int $max) => is_string($v) && $v !== '' && mb_strlen($v) <= $max;
        switch ($op) {
            case 'POST /calls':
                // our own call log only: no recordings, transcripts, numbers or emails
                $only(['externalId', 'userId', 'direction', 'startedAt', 'callStatus', 'note', 'provider', 'duration', 'taskId', 'leadId', 'contactId'], ['externalId', 'userId', 'direction', 'startedAt', 'callStatus']);
                $refs = array_intersect($keys, ['taskId', 'leadId', 'contactId']);
                if (count($refs) !== 1 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', (string) $body['externalId']) || ($body['direction'] ?? '') !== 'outbound'
                    || !preg_match('/^usr_[A-Za-z0-9]+$/', (string) $body['userId']) || (isset($body['note']) && !$text($body['note'], 2000))) {
                    throw new ForbiddenWriteError('Refused: POST /calls needs one of taskId/leadId/contactId, an outbound call by a usr_ and a valid externalId');
                }
                break;
            case 'POST /inbox/email':
                // replies in an existing thread only (no new emails, no CC, no attachments)
                $only(['sendUserId', 'sendUserEmail', 'sendUserMailboxId', 'contactId', 'message', 'replyToActivityId'], ['sendUserId', 'sendUserEmail', 'sendUserMailboxId', 'contactId', 'message', 'replyToActivityId']);
                if ($body['replyToActivityId'] !== 'latest' || !$text($body['message'], 30000)) throw new ForbiddenWriteError('Refused: POST /inbox/email is a reply to the latest thread message only');
                break;
            case 'POST /inbox/linkedin':
                $only(['sendUserId', 'leadId', 'contactId', 'message'], ['sendUserId', 'leadId', 'contactId', 'message']);
                if (!$text($body['message'], 8000)) throw new ForbiddenWriteError('Refused: POST /inbox/linkedin needs a message');
                break;
            case 'POST /inbox/conversations/labels/{id}':
                $only(['labelIds', 'appendLabels'], ['labelIds', 'appendLabels']);
                if (!is_bool($body['appendLabels']) || !is_array($body['labelIds']) || count($body['labelIds']) > 20 || array_filter($body['labelIds'], fn ($l) => !is_string($l) || $l === '')) {
                    throw new ForbiddenWriteError('Refused: labels are {labelIds: [ids], appendLabels: bool}');
                }
                break;
            case 'PATCH /tasks':
                $keys = array_keys($body);
                sort($keys);
                $done = $keys === ['done', 'id'] && $body['done'] === true;
                $snooze = $keys === ['dueDate', 'id'] && is_string($body['dueDate']) && strtotime($body['dueDate']) !== false;
                if (!is_string($body['id'] ?? null) || (!$done && !$snooze)) throw new ForbiddenWriteError('Refused: PATCH /tasks may only set done:true or a dueDate');
                break;
            case 'POST /tasks/ignore':
                if (array_keys($body) !== ['ids'] || !is_array($body['ids']) || count($body['ids']) !== 1 || !is_string($body['ids'][0])) throw new ForbiddenWriteError('Refused: POST /tasks/ignore skips one task: {ids: [id]}');
                break;
            case 'POST /inbox/labels':
                if (array_keys($body) !== ['labelName'] || !in_array($body['labelName'] ?? null, self::LABELS, true)) throw new ForbiddenWriteError('Refused: only the labels ' . implode(', ', self::LABELS) . ' may be created');
                break;
            case 'POST /hooks':
            case 'DELETE /hooks/{id}':
                if (!$setup) throw new ForbiddenWriteError('Refused: webhooks are managed by bin/lemlist-webhook.php only');
                break;
        }
        return $op;
    }
}

final class LemlistClient
{
    public const BASE = 'https://api.lemlist.com/api';
    public const PER_MINUTE = 480; // 8/s; lemlist allows 20 per 2 s per key
    public const MAX_WAIT = 10;

    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{status:int,headers:array,body:string} */
    public $transport = null;
    /** @var callable fn(int $ms) */
    public $sleep;
    public ReadPacer $pacer;
    public bool $setup = false; // true only in bin/lemlist-webhook.php

    public function __construct(private string $apiKey, private string $base = self::BASE)
    {
        $this->sleep = fn (int $ms) => usleep($ms * 1000);
        $this->pacer = new ReadPacer(self::PER_MINUTE);
    }

    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query, null);
    }

    public function write(string $method, string $path, array $body): mixed
    {
        return $this->request($method, $path, [], $body);
    }

    private function request(string $method, string $path, array $query, ?array $body): mixed
    {
        $pattern = LemlistGuard::assert($method, $path, $body, $this->setup);
        $url = rtrim($this->base, '/') . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Authorization: Basic ' . base64_encode(':' . $this->apiKey), 'Accept: application/json'];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->pacer->wait();
            $t0 = microtime(true);
            $res = $this->transport ? ($this->transport)($method, $url, $headers, $json) : $this->curl($method, $url, $headers, $json);
            $s = (int) $res['status'];
            $h = array_change_key_case($res['headers'] ?? [], CASE_LOWER);
            $d = json_decode((string) $res['body'], true);
            $code = is_array($d) ? (string) ($d['error']['code'] ?? '') : '';
            Audit::event('lemlist_call', ['method' => $method, 'endpoint' => $pattern, 'status' => $s, 'code' => $code ?: null, 'ms' => (int) round((microtime(true) - $t0) * 1000)]);
            if ($s >= 200 && $s < 300) return $d ?? [];
            if ($s === 429) {
                $wait = max(1, (int) ($h['retry-after'] ?? 2));
                if ($wait > self::MAX_WAIT || $attempt === 3) throw new LemlistRateLimited($wait);
                ($this->sleep)($wait * 1000);
                continue;
            }
            if ($s >= 500 && $method === 'GET' && $attempt < 3) { ($this->sleep)(1000 * $attempt); continue; }
            $msg = is_array($d) ? (string) ($d['error']['message'] ?? (is_string($d['error'] ?? null) ? $d['error'] : ($d['message'] ?? ''))) : '';
            $msg = mb_substr(trim($msg), 0, 200);
            if ($s === 0) throw new UpstreamError('Could not reach lemlist');
            if ($s === 401) throw new UpstreamError('lemlist refused the API key (check LEMLIST_API_KEY in config.php)');
            if ($s === 402) throw new AppError(402, 'LEMLIST_PLAN', 'Calls API needs Email Pro+');
            if ($s === 403) throw new AppError(403, 'LEMLIST_FORBIDDEN', 'The lemlist API key\'s user lacks the permission for this (e.g. "Edit integrations").');
            if ($s === 404) throw new NotFoundError($msg !== '' ? "lemlist: $msg" : 'Not found in lemlist');
            if ($s === 409) throw new ConflictError($msg !== '' ? $msg : 'lemlist refused: conflict', $code !== '' ? $code : 'LEMLIST_CONFLICT');
            if ($s === 400) throw new ValidationError('lemlist refused the request' . ($msg !== '' ? ": $msg" : ''));
            throw new UpstreamError("lemlist error $s");
        }
        throw new UpstreamError('lemlist did not answer');
    }

    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            }];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = $out === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $out === false ? '' : (string) $out];
    }
}

/** storage/lemlist.sqlite. Lead emails and phones live ONLY in the leads table; events keep ids and types only. */
final class LemlistStore
{
    public const KEEP_DAYS = 365;
    public \PDO $db;

    public function __construct(string $file)
    {
        if (!extension_loaded('pdo_sqlite')) throw new AppError(500, 'NO_SQLITE', 'PHP extension pdo_sqlite is missing (needed for storage/lemlist.sqlite)');
        $new = !is_file($file);
        $this->db = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        if ($new) @chmod($file, 0600);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=OFF;');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS campaigns (id TEXT PRIMARY KEY, name TEXT, status TEXT, sequence_id TEXT, created_at TEXT, total_steps INTEGER, updated_at INTEGER);
CREATE TABLE IF NOT EXISTS steps (step_id TEXT PRIMARY KEY, campaign_id TEXT, sequence_id TEXT, main INTEGER, idx INTEGER, seq_step INTEGER, type TEXT, label TEXT, delay INTEGER, title TEXT, message TEXT);
CREATE INDEX IF NOT EXISTS steps_campaign ON steps (campaign_id);
CREATE TABLE IF NOT EXISTS leads (lead_id TEXT PRIMARY KEY, campaign_id TEXT, contact_id TEXT, first_name TEXT, last_name TEXT, company TEXT, title TEXT,
  linkedin TEXT, linkedin_key TEXT, email TEXT, phone TEXT, last_state TEXT, status TEXT, paused INTEGER, added_at TEXT,
  signal_track TEXT, signal_row INTEGER, signal_company TEXT, signal_job_title TEXT, signal_job_url TEXT, signal_role_group TEXT, signal_sub_group TEXT,
  signal_approved_at TEXT, signal_date_found TEXT, atlas_pool INTEGER, atlas_matching INTEGER, updated_at INTEGER);
CREATE INDEX IF NOT EXISTS leads_campaign ON leads (campaign_id);
CREATE INDEX IF NOT EXISTS leads_linkedin ON leads (linkedin_key);
CREATE TABLE IF NOT EXISTS activities (id TEXT PRIMARY KEY, type TEXT, lead_id TEXT, campaign_id TEXT, contact_id TEXT, step_id TEXT, seq_step INTEGER,
  total_seq_step INTEGER, created_at TEXT, send_user_id TEXT, source TEXT);
CREATE INDEX IF NOT EXISTS activities_lead ON activities (lead_id, created_at);
CREATE INDEX IF NOT EXISTS activities_created ON activities (created_at);
CREATE TABLE IF NOT EXISTS events (id TEXT PRIMARY KEY, type TEXT, created_at TEXT, received_at INTEGER, lead_id TEXT, campaign_id TEXT, contact_id TEXT);
CREATE INDEX IF NOT EXISTS events_received ON events (received_at);
CREATE TABLE IF NOT EXISTS tasks (id TEXT PRIMARY KEY, type TEXT, channel TEXT, status TEXT, campaign_id TEXT, lead_id TEXT, contact_id TEXT, assigned_to TEXT,
  title TEXT, message TEXT, due_date TEXT, manual INTEGER, lead_name TEXT, lead_company TEXT, seen_at INTEGER);
CREATE TABLE IF NOT EXISTS call_statuses (key TEXT PRIMARY KEY, label TEXT, answered INTEGER, positive INTEGER, negative INTEGER, pauses INTEGER, position INTEGER);
CREATE TABLE IF NOT EXISTS stats (campaign_id TEXT, period TEXT, data TEXT, fetched_at INTEGER, PRIMARY KEY (campaign_id, period));
CREATE TABLE IF NOT EXISTS calls (external_id TEXT PRIMARY KEY, lemlist_id TEXT, task_id TEXT, lead_id TEXT, user_id TEXT, by_user TEXT, call_status TEXT, started_at TEXT, created_at INTEGER);
CREATE TABLE IF NOT EXISTS outcomes (lead_id TEXT PRIMARY KEY, outcome TEXT, by_user TEXT, at INTEGER);
CREATE TABLE IF NOT EXISTS business (kind TEXT, id TEXT, company TEXT, company_key TEXT, at TEXT, PRIMARY KEY (kind, id));
CREATE INDEX IF NOT EXISTS business_company ON business (company_key);
SQL);
        // added in Phase 2: campaigns that stop running are hidden but keep their history
        $cols = array_column($this->db->query('PRAGMA table_info(campaigns)')->fetchAll(), 'name');
        if (!in_array('running', $cols, true)) $this->db->exec('ALTER TABLE campaigns ADD COLUMN running INTEGER DEFAULT 1');
        // call scripts: the lead's custom fields used by the campaign's steps (JSON), and the step a task belongs to
        $cols = array_column($this->db->query('PRAGMA table_info(leads)')->fetchAll(), 'name');
        if (!in_array('fields', $cols, true)) $this->db->exec('ALTER TABLE leads ADD COLUMN fields TEXT');
        $cols = array_column($this->db->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
        if (!in_array('step_id', $cols, true)) $this->db->exec('ALTER TABLE tasks ADD COLUMN step_id TEXT');
        if (!in_array('seq_step', $cols, true)) $this->db->exec('ALTER TABLE tasks ADD COLUMN seq_step INTEGER');
        // added in Phase 3: resumable call logs (call logged, then the task updated)
        $cols = array_column($this->db->query('PRAGMA table_info(calls)')->fetchAll(), 'name');
        foreach (['answered INTEGER', 'task_done INTEGER', 'callback_at TEXT', 'kind TEXT'] as $col) {
            if (!in_array(explode(' ', $col)[0], $cols, true)) $this->db->exec("ALTER TABLE calls ADD COLUMN $col");
        }
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        $st = $this->db->prepare('SELECT value FROM meta WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : json_decode((string) $v, true);
    }

    public function setMeta(string $key, mixed $value): void
    {
        $this->db->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, json_encode($value)]);
    }

    private function upsert(string $table, array $row, string $pk): void
    {
        $cols = array_keys($row);
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ") ON CONFLICT($pk) DO UPDATE SET "
            . implode(',', array_map(fn ($c) => "$c = excluded.$c", array_diff($cols, [$pk])));
        $this->db->prepare($sql)->execute(array_values($row));
    }

    public function upsertCampaign(array $row): void { $this->upsert('campaigns', $row, 'id'); }
    public function upsertStep(array $row): void { $this->upsert('steps', $row, 'step_id'); }
    public function upsertTask(array $row): void { $this->upsert('tasks', $row, 'id'); }

    /** Leads keep their app-side signal link across syncs (only lemlist fields are overwritten). */
    public function upsertLead(array $row): void
    {
        $this->upsert('leads', $row, 'lead_id');
    }

    /** @return bool true when new (dedupe by lemlist _id) */
    public function addActivity(array $row): bool
    {
        $st = $this->db->prepare('INSERT OR IGNORE INTO activities (id, type, lead_id, campaign_id, contact_id, step_id, seq_step, total_seq_step, created_at, send_user_id, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$row['id'], $row['type'], $row['lead_id'], $row['campaign_id'], $row['contact_id'], $row['step_id'], $row['seq_step'], $row['total_seq_step'], $row['created_at'], $row['send_user_id'], $row['source']]);
        return $st->rowCount() === 1;
    }

    /** @return bool true when new */
    public function addEvent(array $row): bool
    {
        $st = $this->db->prepare('INSERT OR IGNORE INTO events (id, type, created_at, received_at, lead_id, campaign_id, contact_id) VALUES (?,?,?,?,?,?,?)');
        $st->execute([$row['id'], $row['type'], $row['created_at'], $row['received_at'], $row['lead_id'], $row['campaign_id'], $row['contact_id']]);
        return $st->rowCount() === 1;
    }

    public function replaceCallStatuses(array $rows): void
    {
        $this->db->exec('DELETE FROM call_statuses');
        foreach ($rows as $i => $r) $this->upsert('call_statuses', $r + ['position' => $i], 'key');
    }

    public function setStats(string $campaignId, string $period, array $data, int $now): void
    {
        $this->db->prepare('INSERT INTO stats (campaign_id, period, data, fetched_at) VALUES (?,?,?,?) ON CONFLICT(campaign_id, period) DO UPDATE SET data = excluded.data, fetched_at = excluded.fetched_at')
            ->execute([$campaignId, $period, json_encode($data), $now]);
    }

    public function all(string $sql, array $args = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public function one(string $sql, array $args = []): mixed
    {
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    }

    /** Close tasks that are no longer open in lemlist (not seen in the latest full task sync). */
    public function closeUnseenTasks(int $seenAt): int
    {
        $st = $this->db->prepare("UPDATE tasks SET status = 'closed' WHERE seen_at < ? AND status IN ('due','upcoming','paused')");
        $st->execute([$seenAt]);
        return $st->rowCount();
    }

    /** 12-month retention: old activities and events, and leads not seen in a running campaign for a year. */
    public function prune(int $now): array
    {
        $cut = gmdate('Y-m-d\TH:i:s\Z', $now - self::KEEP_DAYS * 86400);
        $out = [];
        foreach (['activities' => "created_at < ?", 'events' => 'received_at < ?', 'leads' => 'updated_at < ?', 'calls' => 'created_at < ?', 'outcomes' => 'at < ?', 'business' => 'at < ?'] as $t => $where) {
            $arg = in_array($t, ['activities', 'business'], true) ? $cut : $now - self::KEEP_DAYS * 86400;
            $st = $this->db->prepare("DELETE FROM $t WHERE $where");
            $st->execute([$arg]);
            $out[$t] = $st->rowCount();
        }
        $this->db->exec("DELETE FROM tasks WHERE status = 'closed' AND seen_at < " . ($now - 30 * 86400));
        return $out;
    }
}

/** Who sends and logs as which lemlist user. */
final class LemlistSender
{
    /**
     * @param array $team users from GET /team?version=v2: [{userId, name, email, role}]
     * @return array{userId:string,name:string,email:string,mailboxId:?string,shared:bool}|null null = view only
     */
    public static function for(array $config, string $loginEmail, array $team = []): ?array
    {
        $login = strtolower(trim($loginEmail));
        $map = json_decode((string) ($config['LEMLIST_USER_MAP'] ?? ''), true) ?: [];
        $map = array_change_key_case($map, CASE_LOWER);
        $byId = [];
        foreach ($team as $u) $byId[(string) ($u['userId'] ?? '')] = $u;
        if (isset($map[$login]['userId'])) {
            $m = $map[$login];
            $u = $byId[$m['userId']] ?? [];
            return ['userId' => (string) $m['userId'], 'name' => (string) ($u['name'] ?? $m['email'] ?? $login), 'email' => (string) ($m['email'] ?? $u['email'] ?? ''),
                'mailboxId' => isset($m['mailboxId']) ? (string) $m['mailboxId'] : null, 'shared' => false];
        }
        $allowed = array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', (string) ($config['LEMLIST_SENDER_ALLOWED'] ?? ''))));
        $shared = trim((string) ($config['LEMLIST_SHARED_SENDER'] ?? ''));
        if ($shared === '' || !in_array($login, $allowed, true)) return null;
        $u = $byId[$shared] ?? [];
        return ['userId' => $shared, 'name' => (string) ($u['name'] ?? $shared), 'email' => (string) ($u['email'] ?? ''), 'mailboxId' => null, 'shared' => true];
    }

    /** "Sending as Jonathan Perkins (jonathan@swooscale.com)" */
    public static function label(array $sender): string
    {
        return 'Sending as ' . $sender['name'] . ($sender['email'] !== '' ? ' (' . $sender['email'] . ')' : '');
    }
}
