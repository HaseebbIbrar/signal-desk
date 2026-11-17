<?php
// Outreach tab (read-only towards lemlist): tiles, the four channel cards, "From reach to business", Needs action, the funnel,
// the running-campaigns table, compare mode and the live lead board, for a campaign SELECTION and a period.
//
//   OutreachPeriod      Today / 7 days / 30 days (+ the previous period of the same length, for the change)
//   OutreachSelection   which campaigns: ?c=<ids>&p=7 in the URL, last selection per user, saved selections, quick groups
//   OutreachBusiness    Atlas new jobs (projects), deals (won opportunities) and placements (new clients), synced hourly by cron
//   Outreach            the numbers, the lead board, a lead's timeline, outcome buttons (stored here, never written to lemlist)
declare(strict_types=1);

namespace SignalDesk;

final class OutreachPeriod
{
    public const PERIODS = ['today' => 'Today', '7' => '7 days', '30' => '30 days'];

    /** @return array{p:string,label:string,start:int,end:int,prevStart:int,prevEnd:int} */
    public static function range(string $p, int $now, string $tz = 'Europe/Brussels'): array
    {
        $p = isset(self::PERIODS[$p]) ? $p : '7';
        if ($p === 'today') {
            $start = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($tz))->setTime(0, 0)->getTimestamp();
            $len = 86400;
            return ['p' => $p, 'label' => 'Today', 'start' => $start, 'end' => $now, 'prevStart' => $start - $len, 'prevEnd' => $now - $len];
        }
        $len = (int) $p * 86400;
        return ['p' => $p, 'label' => self::PERIODS[$p], 'start' => $now - $len, 'end' => $now, 'prevStart' => $now - 2 * $len, 'prevEnd' => $now - $len];
    }
}

final class OutreachSelection
{
    /** "?c=cam_a,cam_b&p=7" -> [ids, period]; ids are filtered to the visible campaigns, unknown ones reported as dropped. */
    public static function parse(?string $c, ?string $p, array $visibleIds): array
    {
        $want = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $c)), fn ($id) => preg_match('/^[A-Za-z0-9_-]{3,64}$/', $id))));
        $keep = array_values(array_intersect($want, $visibleIds));
        return ['ids' => $keep, 'dropped' => array_values(array_diff($want, $visibleIds)), 'all' => !$want, 'p' => isset(OutreachPeriod::PERIODS[(string) $p]) ? (string) $p : null];
    }

    public static function query(array $ids, string $p, int $total): string
    {
        $q = [];
        if ($ids && count($ids) < $total) $q['c'] = implode(',', $ids);
        $q['p'] = $p;
        return '?' . http_build_query($q);
    }

    /**
     * Quick groups from campaign names: a word (or acronym like "MPC", "HR") shared by at least two running campaigns.
     * @param array $campaigns [{id, name}] @return array [{name: "All MPC", ids: [...]}]
     */
    public static function groups(array $campaigns): array
    {
        $stop = ['the', 'and', 'of', 'for', 'to', 'de', 'en', 'et', 'la', 'le', 'les', 'van', 'campaign', 'campaigns', 'copy', 'belgium', 'belgique', 'belgie', 'belgië', 'be', 'new', 'test', 'v1', 'v2', 'v3'];
        $words = [];
        foreach ($campaigns as $c) {
            foreach (array_unique(array_map(fn ($w) => mb_strtolower($w), preg_split('~[^\p{L}\p{N}]+~u', (string) $c['name'], -1, PREG_SPLIT_NO_EMPTY) ?: [])) as $w) {
                if (mb_strlen($w) < 2 || in_array($w, $stop, true) || ctype_digit($w)) continue;
                $words[$w][] = $c['id'];
            }
        }
        $groups = [];
        foreach ($words as $w => $ids) {
            if (count($ids) < 2 || count($ids) === count($campaigns)) continue;
            $label = mb_strlen($w) <= 4 ? mb_strtoupper($w) : mb_convert_case($w, MB_CASE_TITLE);
            $groups[] = ['name' => "All $label", 'ids' => array_values($ids)];
        }
        usort($groups, fn ($a, $b) => [count($b['ids']), $a['name']] <=> [count($a['ids']), $b['name']]);
        $out = [];
        foreach ($groups as $g) { // drop groups that select exactly the same campaigns as a bigger-named one
            $k = implode(',', $g['ids']);
            if (!isset($out[$k])) $out[$k] = $g;
        }
        return array_slice(array_values($out), 0, 8);
    }
}

/** storage/outreach-selections.json: per Signal Desk user, the last selection and named saved selections. */
final class OutreachSelections
{
    public function __construct(private string $file) {}

    private static function u(string $user): string
    {
        return strtolower(trim($user));
    }

    public function forUser(string $user): array
    {
        $d = JsonStore::read($this->file, []) ?: [];
        $u = $d[self::u($user)] ?? [];
        return ['last' => $u['last'] ?? null, 'saved' => array_values($u['saved'] ?? [])];
    }

    public function remember(string $user, array $ids, string $p): void
    {
        JsonStore::update($this->file, [], function ($d) use ($user, $ids, $p) {
            $d = is_array($d) ? $d : [];
            $d[self::u($user)]['last'] = ['c' => array_values($ids), 'p' => $p];
            return $d;
        });
    }

    /** Create or rename/update a saved selection. */
    public function save(string $user, mixed $name, mixed $ids, ?string $id = null): array
    {
        $name = is_string($name) ? trim(preg_replace('~\s+~u', ' ', $name) ?? $name) : '';
        if ($name === '' || mb_strlen($name) > 60) throw new ValidationError('Give the selection a name (max 60 characters).');
        if (!is_array($ids) || !$ids || count($ids) > 200) throw new ValidationError('Choose at least one campaign.');
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn ($x) => preg_match('/^[A-Za-z0-9_-]{3,64}$/', $x))));
        $entry = null;
        JsonStore::update($this->file, [], function ($d) use ($user, $name, $ids, $id, &$entry) {
            $d = is_array($d) ? $d : [];
            $saved = $d[self::u($user)]['saved'] ?? [];
            if (count($saved) >= 30 && $id === null) throw new ValidationError('At most 30 saved selections.');
            $entry = ['id' => $id ?? bin2hex(random_bytes(6)), 'name' => $name, 'c' => $ids];
            $found = false;
            foreach ($saved as &$s) if ($s['id'] === $entry['id']) { $s = $entry; $found = true; }
            unset($s);
            if (!$found) {
                if ($id !== null) throw new NotFoundError('That saved selection no longer exists.');
                $saved[] = $entry;
            }
            $d[self::u($user)]['saved'] = $saved;
            return $d;
        });
        return $entry;
    }

    public function delete(string $user, string $id): bool
    {
        $gone = false;
        JsonStore::update($this->file, [], function ($d) use ($user, $id, &$gone) {
            $d = is_array($d) ? $d : [];
            $saved = $d[self::u($user)]['saved'] ?? [];
            $left = array_values(array_filter($saved, fn ($s) => $s['id'] !== $id));
            $gone = count($left) < count($saved);
            $d[self::u($user)]['saved'] = $left;
            return $d;
        });
        return $gone;
    }
}

/** Atlas business events matched to lead companies: new jobs (projects), deals (won opportunities), placements. */
final class OutreachBusiness
{
    public const EVERY = 3600;

    public static function companyKey(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ï' => 'i', 'î' => 'i', 'ô' => 'o', 'ö' => 'o', 'ü' => 'u', 'û' => 'u', 'ç' => 'c']);
        $s = preg_replace('~\b(nv|bv|sa|srl|sprl|bvba|cvba|scrl|ltd|limited|gmbh|inc|llc|plc|group|groep|groupe|holding|belgium|belgie|belgique|international|the)\b~u', ' ', $s) ?? $s;
        return preg_replace('~[^a-z0-9]+~', '', $s) ?? '';
    }

    /** Hourly from bin/lemlist-sync.php. Read-only Atlas GETs (projects, opportunities, opportunity detail, placements). */
    public static function sync(AtlasClient $atlas, LemlistStore $store, int $now, bool $force = false): array
    {
        if (!$force && (int) $store->meta('business_at', 0) > $now - self::EVERY) return ['skipped' => true];
        $since = gmdate('Y-m-d\TH:i:s\Z', $now - 400 * 86400);
        $put = $store->db->prepare('INSERT INTO business (kind, id, company, company_key, at) VALUES (?,?,?,?,?) ON CONFLICT(kind, id) DO UPDATE SET company = excluded.company, company_key = excluded.company_key, at = excluded.at');
        $n = ['job' => 0, 'deal' => 0, 'placement' => 0];
        $pages = function (string $path, array $q) use ($atlas): \Generator {
            for ($page = 1; $page <= 50; $page++) {
                $r = $atlas->get($path, $q + ['page' => $page, 'pageSize' => 100]);
                foreach ($r['data'] ?? [] as $x) yield $x;
                if ($page >= (int) ($r['pagination']['totalPages'] ?? 1)) break;
            }
        };
        foreach ($pages('/api/v1/projects', ['createdAfter' => $since]) as $p) {
            $co = (string) ($p['company']['name'] ?? '');
            if ($co === '') continue; // talent-pool projects have no company
            $put->execute(['job', (string) $p['id'], $co, self::companyKey($co), (string) ($p['createdAt'] ?? '')]);
            $n['job']++;
        }
        $have = array_flip(array_map(fn ($r) => explode(':', $r['id'])[0], $store->all("SELECT id FROM business WHERE kind = 'deal'")));
        foreach ($pages('/api/v1/opportunities', ['status' => 'won', 'updatedAfter' => $since]) as $o) {
            if (isset($have[(string) $o['id']])) continue;
            $d = $atlas->get('/api/v1/opportunities/' . $o['id'])['data'] ?? [];
            foreach ($d['companies'] ?? [] as $c) {
                $co = (string) ($c['company']['name'] ?? '');
                if ($co === '') continue;
                $put->execute(['deal', $o['id'] . ':' . ($c['companyId'] ?? $c['id'] ?? ''), $co, self::companyKey($co), (string) ($d['stageUpdatedAt'] ?? $d['updatedAt'] ?? $o['updatedAt'] ?? '')]);
                $n['deal']++;
            }
        }
        foreach ($pages('/api/v1/placements', ['createdAfter' => $since]) as $p) {
            $co = (string) ($p['client']['company']['name'] ?? '');
            if ($co === '') continue;
            $put->execute(['placement', (string) $p['id'], $co, self::companyKey($co), (string) ($p['createdAt'] ?? '')]);
            $n['placement']++;
        }
        $store->setMeta('business_at', $now);
        return $n;
    }
}

final class Outreach
{
    public const OUTCOMES = ['Interested', 'Not now', 'Not interested', 'Meeting', 'New job', 'Deal', 'New client'];
    public const PAGE = 50;
    private const RESPONSE = [
        'email' => ['emailsReplied'], 'invite' => ['linkedinInviteAccepted'], 'message' => ['linkedinReplied', 'linkedinVoiceNoteReplied'],
        'call' => ['aircallInterested', 'aircallNotInterested'],
    ];
    private const POSITIVE = ['emailsInterested' => 'email', 'linkedinInterested' => 'message', 'aircallInterested' => 'call'];

    /** @var callable(): int */
    public $now;
    private array $cache = [];

    public function __construct(private LemlistStore $store, private array $config, private string $tz = 'Europe/Brussels')
    {
        $this->now = fn () => time();
    }

    private static function ts(?string $iso): int
    {
        return $iso ? (int) (strtotime($iso) ?: 0) : 0;
    }

    /** Running campaigns (or all with LEMLIST_SHOW_STOPPED=true): [{id, name, leads, running}] */
    public function campaigns(): array
    {
        $showStopped = in_array(strtolower((string) ($this->config['LEMLIST_SHOW_STOPPED'] ?? 'false')), ['1', 'true', 'yes'], true);
        return array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'leads' => (int) $c['leads'], 'running' => (bool) $c['running']],
            $this->store->all('SELECT c.id, c.name, COALESCE(c.running, 1) AS running, (SELECT COUNT(*) FROM leads l WHERE l.campaign_id = c.id) AS leads FROM campaigns c'
                . ($showStopped ? '' : ' WHERE COALESCE(c.running, 1) = 1') . ' ORDER BY c.name'));
    }

    /** Leads, activities, calls and outcomes of the selected campaigns, with each lead's level, channels and status. */
    private function load(array $ids): array
    {
        $key = implode(',', $ids);
        if (isset($this->cache[$key])) return $this->cache[$key];
        if (!$ids) return $this->cache[$key] = ['leads' => [], 'acts' => [], 'steps' => [], 'byLead' => []];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $leads = [];
        foreach ($this->store->all("SELECT * FROM leads WHERE campaign_id IN ($in)", $ids) as $l) $leads[$l['lead_id']] = $l;
        $acts = [];
        foreach ($this->store->all("SELECT * FROM activities WHERE campaign_id IN ($in) ORDER BY created_at", $ids) as $a) { $a['t'] = self::ts($a['created_at']); $acts[] = $a; }
        $steps = [];
        foreach ($this->store->all("SELECT * FROM steps WHERE campaign_id IN ($in)", $ids) as $s) $steps[$s['campaign_id']][] = $s;
        $calls = [];
        foreach ($this->store->all('SELECT * FROM calls') as $c) $calls[$c['lead_id']][] = $c + ['t' => (int) $c['created_at']];
        $statuses = [];
        foreach ($this->store->all('SELECT * FROM call_statuses') as $s) $statuses[$s['key']] = ['answered' => (int) $s['answered'], 'positive' => (int) $s['positive'], 'negative' => (int) $s['negative'], 'label' => $s['label']];
        $outcomes = [];
        foreach ($this->store->all('SELECT * FROM outcomes') as $o) $outcomes[$o['lead_id']] = $o;
        $by = [];
        foreach ($acts as $a) if ($a['lead_id'] !== null && isset($leads[$a['lead_id']])) $by[$a['lead_id']][] = $a;
        $view = [];
        foreach ($leads as $id => $l) {
            $la = $by[$id] ?? [];
            $level = LeadLogic::level($la, $steps[$l['campaign_id']] ?? []);
            $ch = LeadLogic::channels($la, $calls[$id] ?? [], $statuses);
            $out = $outcomes[$id]['outcome'] ?? null;
            $view[$id] = ['lead' => $l, 'acts' => $la, 'calls' => $calls[$id] ?? [], 'level' => $level, 'channels' => $ch,
                'status' => LeadLogic::status($la, $l, $level, $ch, $out), 'outcome' => $outcomes[$id] ?? null];
        }
        return $this->cache[$key] = ['leads' => $leads, 'acts' => $acts, 'steps' => $steps, 'byLead' => $view, 'statuses' => $statuses];
    }

    /** Core numbers for a set of campaigns and a time window. Every rate comes with its responses and actions. */
    public function metrics(array $ids, int $start, int $end): array
    {
        $d = $this->load($ids);
        $in = fn (int $t) => $t >= $start && $t < $end;
        $cnt = fn (array $types) => count(array_filter($d['acts'], fn ($a) => $in($a['t']) && in_array($a['type'], $types, true)));
        $leadsWith = function (array $types) use ($d, $in): array {
            $s = [];
            foreach ($d['acts'] as $a) if ($a['lead_id'] && $in($a['t']) && in_array($a['type'], $types, true)) $s[$a['lead_id']] = true;
            return $s;
        };
        $statuses = $d['statuses'];
        $callsIn = [];
        foreach ($d['byLead'] as $id => $v) foreach ($v['calls'] as $c) if ($in((int) $c['t'])) $callsIn[] = $c + ['lead_id' => $id];

        $emailSent = $cnt(['emailsSent']);
        $bounced = $cnt(['emailsBounced', 'emailsFailed']);
        $delivered = max(0, $emailSent - $bounced);
        $emailReplies = $cnt(['emailsReplied']);
        $bouncedLeads = $leadsWith(['emailsBounced', 'emailsFailed']);
        $emailReached = array_diff_key($leadsWith(['emailsSent']), $bouncedLeads);

        $invSent = $cnt(['linkedinInviteDone']);
        $invAcc = $cnt(['linkedinInviteAccepted']);
        $pending = 0;
        foreach ($d['byLead'] as $v) if ($v['channels']['invite'] === 'pending') $pending++;
        $msgSent = $cnt(['linkedinSent', 'linkedinInMailSent', 'linkedinVoiceNoteDone']);
        $msgReplies = $cnt(['linkedinReplied', 'linkedinVoiceNoteReplied']);
        $liReached = $leadsWith(['linkedinInviteDone', 'linkedinSent', 'linkedinInMailSent', 'linkedinVoiceNoteDone']);

        $dialerCalls = $cnt(['aircallDone']);
        $callsMade = $dialerCalls + count($callsIn);
        $conversations = $cnt(['aircallInterested', 'aircallNotInterested']);
        $positiveCalls = $cnt(['aircallInterested']);
        $phoneReached = $leadsWith(['aircallInterested', 'aircallNotInterested']);
        foreach ($callsIn as $c) {
            $s = $statuses[$c['call_status']] ?? null;
            if ($s && $s['answered']) { $conversations++; $phoneReached[$c['lead_id']] = true; }
            if ($s && $s['positive']) $positiveCalls++;
        }
        $callLeads = $leadsWith(['aircallDone', 'aircallEnded']);
        foreach ($callsIn as $c) $callLeads[$c['lead_id']] = true;

        $reached = $emailReached + $liReached + $phoneReached;
        // business results per lead (meetings from lemlist or the outcome buttons; jobs/deals/clients from Atlas or the buttons)
        $results = $this->results($d, $start, $end);
        $attr = $this->attribution($d, $results, $end);
        $responded = ['email' => $leadsWith(self::RESPONSE['email']), 'invite' => $leadsWith(self::RESPONSE['invite']), 'message' => $leadsWith(self::RESPONSE['message']), 'call' => $phoneReached];
        $channelReached = ['email' => $emailReached, 'invite' => $leadsWith(['linkedinInviteDone']), 'message' => $leadsWith(['linkedinSent', 'linkedinInMailSent', 'linkedinVoiceNoteDone']), 'call' => $callLeads];
        $rate = fn (int $r, int $a) => ['rate' => $a ? round(100 * $r / $a, 1) : null, 'responses' => $r, 'actions' => $a];
        $table = [];
        foreach (['email' => 'Email', 'invite' => 'LinkedIn invites', 'message' => 'LinkedIn messages', 'call' => 'Calls'] as $k => $label) {
            $row = ['channel' => $label, 'reached' => count($channelReached[$k]), 'responded' => count($responded[$k])];
            foreach (['meeting', 'job', 'deal', 'client'] as $r) $row[$r] = count(array_filter($results[$r], fn ($lead) => in_array($k, $attr[$lead] ?? [], true)));
            $table[$k] = $row;
        }
        $anyResponded = $responded['email'] + $responded['invite'] + $responded['message'] + $responded['call'];
        $table['total'] = ['channel' => 'Total', 'reached' => count($reached), 'responded' => count($anyResponded),
            'meeting' => count($results['meeting']), 'job' => count($results['job']), 'deal' => count($results['deal']), 'client' => count($results['client'])];
        $meetingsFrom = fn (string $k) => $table[$k]['meeting'];
        return [
            'tiles' => [
                'reached' => count($reached), 'email' => count($emailReached), 'linkedin' => count($liReached),
                'phone' => count($phoneReached), 'calls' => $callsMade, 'meetings' => count($results['meeting']),
                'jobs' => count($results['job']), 'deals' => count($results['deal']), 'clients' => count($results['client']),
            ],
            'channels' => [
                'email' => ['label' => 'Email', 'rateName' => 'Reply rate', 'rate' => $rate($emailReplies, $delivered), 'actions' => $emailSent, 'delivered' => $delivered, 'responses' => $emailReplies,
                    'reached' => count($emailReached), 'extra' => ['label' => 'Bounced', 'value' => $bounced, 'pct' => $emailSent ? round(100 * $bounced / $emailSent, 1) : null], 'meetings' => $meetingsFrom('email')],
                'invite' => ['label' => 'LinkedIn invites', 'rateName' => 'Acceptance rate', 'rate' => $rate($invAcc, $invSent), 'actions' => $invSent, 'responses' => $invAcc,
                    'reached' => count($channelReached['invite']), 'extra' => ['label' => 'Still pending', 'value' => $pending, 'pct' => null], 'meetings' => $meetingsFrom('invite')],
                'message' => ['label' => 'LinkedIn messages', 'rateName' => 'Reply rate', 'rate' => $rate($msgReplies, $msgSent), 'actions' => $msgSent, 'responses' => $msgReplies,
                    'reached' => count($channelReached['message']), 'extra' => ['label' => 'Sent to connections', 'value' => $cnt(['linkedinSent', 'linkedinVoiceNoteDone']), 'pct' => null], 'meetings' => $meetingsFrom('message')],
                'call' => ['label' => 'Calls', 'rateName' => 'Connect rate', 'rate' => $rate($conversations, $callsMade), 'actions' => $callsMade, 'responses' => $conversations,
                    'reached' => count($callLeads), 'extra' => ['label' => 'Positive calls', 'value' => $positiveCalls, 'pct' => null], 'meetings' => $meetingsFrom('call')],
            ],
            'business' => array_values($table),
            'respondedLeads' => count($anyResponded),
        ];
    }

    /** Leads with a meeting / new job / deal / new client in the window. Atlas items count via the lead's company. */
    private function results(array $d, int $start, int $end): array
    {
        $in = fn (int $t) => $t >= $start && $t < $end;
        $r = ['meeting' => [], 'job' => [], 'deal' => [], 'client' => []];
        $byCompany = [];
        foreach ($d['byLead'] as $id => $v) {
            foreach ($v['acts'] as $a) if ($a['type'] === 'meetingBooked' && $in($a['t'])) $r['meeting'][$id] = $id;
            $o = $v['outcome'];
            if ($o && $in((int) $o['at'])) {
                $map = ['Meeting' => 'meeting', 'New job' => 'job', 'Deal' => 'deal', 'New client' => 'client'];
                if (isset($map[$o['outcome']])) $r[$map[$o['outcome']]][$id] = $id;
            }
            foreach (array_unique([OutreachBusiness::companyKey((string) $v['lead']['company']), OutreachBusiness::companyKey((string) ($v['lead']['signal_company'] ?? ''))]) as $k) {
                if ($k !== '') $byCompany[$k][] = $id;
            }
        }
        if ($byCompany) {
            $keys = array_keys($byCompany);
            $inK = implode(',', array_fill(0, count($keys), '?'));
            $first = [];
            foreach ($this->store->all("SELECT company_key, MIN(at) AS first FROM business WHERE kind = 'placement' AND company_key IN ($inK) GROUP BY company_key", $keys) as $f) $first[$f['company_key']] = self::ts($f['first']);
            foreach ($this->store->all("SELECT * FROM business WHERE company_key IN ($inK)", $keys) as $b) {
                $t = self::ts($b['at']);
                if (!$in($t)) continue;
                $kind = match ($b['kind']) { 'job' => 'job', 'deal' => 'deal', default => null };
                if ($b['kind'] === 'placement' && ($first[$b['company_key']] ?? 0) === $t) $kind = 'client'; // the company's first placement
                if ($kind === null) continue;
                $lead = $byCompany[$b['company_key']][0];
                $r[$kind]['co:' . $b['company_key'] . ':' . $b['id']] = $lead; // one per Atlas item, credited to the company's lead
            }
        }
        return $r;
    }

    /**
     * Which channel(s) a lead's results are credited to. first_positive: the channel of the lead's first positive
     * response (interested / positive call), else its first response; all_touches: every channel that reached it.
     * @return array<string,string[]> lead id => channels
     */
    private function attribution(array $d, array $results, int $end): array
    {
        $mode = (string) ($this->config['LEMLIST_ATTRIBUTION'] ?? 'first_positive');
        $leads = [];
        foreach ($results as $set) foreach ($set as $lead) $leads[$lead] = true;
        $out = [];
        foreach (array_keys($leads) as $id) {
            $v = $d['byLead'][$id] ?? null;
            if (!$v) continue;
            if ($mode === 'all_touches') {
                $ch = [];
                foreach ($v['acts'] as $a) {
                    if ($a['t'] >= $end) continue;
                    $t = $a['type'];
                    if (str_starts_with($t, 'emails')) $ch['email'] = true;
                    elseif (str_starts_with($t, 'linkedinInvite')) $ch['invite'] = true;
                    elseif (str_starts_with($t, 'linkedin')) $ch['message'] = true;
                    elseif (str_starts_with($t, 'aircall')) $ch['call'] = true;
                }
                if ($v['calls']) $ch['call'] = true;
                $out[$id] = array_keys($ch);
                continue;
            }
            $firstPos = null;
            $firstResp = null;
            foreach ($v['acts'] as $a) {
                if ($a['t'] >= $end) continue;
                if ($firstPos === null && isset(self::POSITIVE[$a['type']])) $firstPos = [$a['t'], self::POSITIVE[$a['type']]];
                foreach (self::RESPONSE as $k => $types) if ($firstResp === null && in_array($a['type'], $types, true)) $firstResp = [$a['t'], $k];
            }
            foreach ($v['calls'] as $c) {
                $s = $d['statuses'][$c['call_status']] ?? null;
                if ($s && $s['positive'] && ($firstPos === null || $c['t'] < $firstPos[0])) $firstPos = [$c['t'], 'call'];
                if ($s && $s['answered'] && ($firstResp === null || $c['t'] < $firstResp[0])) $firstResp = [$c['t'], 'call'];
            }
            $pick = $firstPos ?? $firstResp;
            $out[$id] = $pick ? [$pick[1]] : [];
        }
        return $out;
    }

    private static function change(array $cur, array $prev): array
    {
        $out = [];
        foreach ($cur as $k => $v) {
            $p = $prev[$k] ?? 0;
            $out[$k] = ['value' => $v, 'prev' => $p, 'delta' => $v - $p, 'pct' => $p ? round(100 * ($v - $p) / $p) : null];
        }
        return $out;
    }

    /** Everything above the lead board, for one selection and period. */
    public function dashboard(array $ids, string $p): array
    {
        $now = ($this->now)();
        $r = OutreachPeriod::range($p, $now, $this->tz);
        $cur = $this->metrics($ids, $r['start'], $r['end']);
        $prev = $this->metrics($ids, $r['prevStart'], $r['prevEnd']);
        $campaignRows = [];
        $names = array_column($this->campaigns(), 'name', 'id');
        foreach ($ids as $id) {
            $m = $this->metrics([$id], $r['start'], $r['end']);
            $campaignRows[] = $this->campaignRow($id, $names[$id] ?? $id, $m);
        }
        $campaignRows[] = $this->campaignRow('total', 'Total', $cur);
        return [
            'period' => $r, 'tiles' => self::change($cur['tiles'], $prev['tiles']), 'channels' => $cur['channels'], 'business' => $cur['business'],
            'attribution' => (string) ($this->config['LEMLIST_ATTRIBUTION'] ?? 'first_positive'),
            'needs' => $this->needsAction($ids, $r['start']), 'funnel' => $this->funnel($ids, $r['start'], $r['end'], $cur), 'campaigns' => $campaignRows,
            'syncedAt' => $this->store->meta('last_sync_at'), 'eventAt' => $this->store->live('last_event_at'),
        ];
    }

    private function campaignRow(string $id, string $name, array $m): array
    {
        $d = $this->load($id === 'total' ? [] : [$id]);
        return ['id' => $id, 'name' => $name, 'leads' => $id === 'total' ? null : count($d['leads']), 'reached' => $m['tiles']['reached'],
            'email' => $m['channels']['email']['rate'], 'invite' => $m['channels']['invite']['rate'], 'message' => $m['channels']['message']['rate'], 'call' => $m['channels']['call']['rate'],
            'meetings' => $m['tiles']['meetings'], 'jobs' => $m['tiles']['jobs'], 'deals' => $m['tiles']['deals'], 'clients' => $m['tiles']['clients']];
    }

    /** Compare 2-3 campaigns side by side. */
    public function compare(array $ids, string $p): array
    {
        if (count($ids) < 2 || count($ids) > 3) throw new ValidationError('Compare 2 or 3 campaigns.');
        $r = OutreachPeriod::range($p, ($this->now)(), $this->tz);
        $names = array_column($this->campaigns(), 'name', 'id');
        $cols = [];
        foreach ($ids as $id) {
            $m = $this->metrics([$id], $r['start'], $r['end']);
            $cols[] = $this->campaignRow($id, $names[$id] ?? $id, $m) + ['leads' => count($this->load([$id])['leads'])];
        }
        return ['period' => $r, 'campaigns' => $cols];
    }

    private function needsAction(array $ids, int $since): array
    {
        $d = $this->load($ids);
        $items = ['replies' => [], 'meetings' => [], 'callbacks' => [], 'bounces' => []];
        $names = array_column($this->store->all('SELECT id, name FROM campaigns'), 'name', 'id');
        foreach ($d['byLead'] as $id => $v) {
            $v['campaignName'] = $names[$v['lead']['campaign_id']] ?? '';
            $last = null;
            foreach ($v['acts'] as $a) $last = $a;
            $hasOutcome = $v['outcome'] !== null;
            foreach (array_reverse($v['acts']) as $a) {
                if ($a['t'] < $since) break;
                if (in_array($a['type'], ['emailsReplied', 'linkedinReplied', 'linkedinVoiceNoteReplied'], true) && !$hasOutcome) { $items['replies'][$id] = self::brief($v, $a); break; }
            }
            foreach ($v['acts'] as $a) if ($a['type'] === 'meetingBooked' && $a['t'] >= $since && ($v['outcome']['outcome'] ?? '') !== 'Meeting') $items['meetings'][$id] = self::brief($v, $a);
            foreach ($v['acts'] as $a) if (in_array($a['type'], ['emailsBounced', 'emailsFailed'], true) && $a['t'] >= $since) $items['bounces'][$id] = self::brief($v, $a);
        }
        $alerts = array_values(array_filter($this->store->live('alerts', []) ?: [], fn ($x) => (int) $x['at'] >= $since));
        $unpushed = $this->store->meta('unpushed_dms', ['count' => 0, 'items' => []]) ?: ['count' => 0, 'items' => []];
        return ['replies' => array_values($items['replies']), 'meetings' => array_values($items['meetings']), 'callbacks' => [], 'bounces' => array_values($items['bounces']),
            'alerts' => $alerts, 'unpushed' => $unpushed];
    }

    private static function brief(array $v, array $a): array
    {
        $l = $v['lead'];
        return ['leadId' => $l['lead_id'], 'name' => trim($l['first_name'] . ' ' . $l['last_name']), 'company' => $l['company'], 'campaign' => $v['campaignName'] ?? '', 'type' => $a['type'], 'at' => $a['created_at']];
    }

    /** Signals found -> Verified -> DMs approved (workbook, all campaigns) -> Pushed -> Reached -> Responded -> Meeting -> New job/deal -> New client. */
    private function funnel(array $ids, int $start, int $end, array $m): array
    {
        $wb = $this->store->meta('workbook_counts', []) ?: [];
        $sum = function (string $k) use ($wb, $start, $end): int {
            $n = 0;
            foreach ($wb[$k] ?? [] as $date => $c) { $t = self::ts($date . 'T12:00:00Z'); if ($t >= $start - 43200 && $t < $end) $n += (int) $c; }
            return $n;
        };
        $pushed = 0;
        foreach ($this->load($ids)['leads'] as $l) if (($t = self::ts($l['added_at'])) >= $start && $t < $end) $pushed++;
        $steps = [['Signals found', $sum('signals'), 'workbook'], ['Verified', $sum('verified'), 'workbook'], ['DMs approved', $sum('approved'), 'workbook'],
            ['Pushed to lemlist', $pushed, 'selection'], ['Reached', $m['tiles']['reached'], 'selection'], ['Responded', $m['respondedLeads'], 'selection'],
            ['Meeting', $m['tiles']['meetings'], 'selection'], ['New job / deal', $m['tiles']['jobs'] + $m['tiles']['deals'], 'selection'], ['New client', $m['tiles']['clients'], 'selection']];
        $out = [];
        $prev = null;
        foreach ($steps as [$name, $n, $scope]) {
            // a step can be larger than the one before (leads pushed before the period reached now): no % then
            $out[] = ['name' => $name, 'value' => $n, 'scope' => $scope, 'pct' => $prev && $n <= $prev ? round(100 * $n / $prev, 1) : null];
            $prev = $n ?: null;
        }
        return $out;
    }

    /** Lead board: filtered, searched, one page of 50, plus counts per status for "Board by stage". */
    public function leads(array $ids, array $f, int $page): array
    {
        $d = $this->load($ids);
        $names = array_column($this->store->all('SELECT id, name FROM campaigns'), 'name', 'id');
        $needs = [];
        $n = $this->needsAction($ids, ($this->now)() - 30 * 86400);
        foreach (['replies', 'meetings', 'bounces'] as $k) foreach ($n[$k] as $x) $needs[$x['leadId']] = true;
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $rows = [];
        $byStatus = array_fill_keys(LeadLogic::STATUSES, 0);
        foreach ($d['byLead'] as $id => $v) {
            $l = $v['lead'];
            if (($f['campaign'] ?? '') !== '' && $l['campaign_id'] !== $f['campaign']) continue;
            if (($f['track'] ?? '') !== '' && ($l['signal_track'] ?? '') !== $f['track']) continue;
            if (($f['group'] ?? '') !== '' && ($l['signal_role_group'] ?? '') !== $f['group']) continue;
            if (($f['channel'] ?? '') !== '') {
                [$ch, $state] = array_pad(explode(':', (string) $f['channel'], 2), 2, '');
                if (($v['channels'][$ch] ?? null) !== $state) continue;
            }
            if (!empty($f['needs']) && !isset($needs[$id])) continue;
            if ($q !== '' && !str_contains(mb_strtolower($l['first_name'] . ' ' . $l['last_name'] . ' ' . $l['company'] . ' ' . ($l['signal_company'] ?? '')), $q)) continue;
            $byStatus[$v['status']] = ($byStatus[$v['status']] ?? 0) + 1;
            if (($f['status'] ?? '') !== '' && $v['status'] !== $f['status']) continue; // after counting: the status chips keep their counts
            $last = end($v['acts']) ?: null;
            $rows[] = ['id' => $id, 'name' => trim($l['first_name'] . ' ' . $l['last_name']) ?: '(no name)', 'title' => $l['title'], 'linkedin' => $l['linkedin'],
                'company' => $l['company'], 'track' => $l['signal_track'], 'jobTitle' => $l['signal_job_title'], 'roleGroup' => $l['signal_role_group'],
                'campaign' => $names[$l['campaign_id']] ?? $l['campaign_id'], 'campaignId' => $l['campaign_id'],
                'level' => $v['level'], 'channels' => $v['channels'], 'status' => $v['status'], 'needsAction' => isset($needs[$id]),
                'lastActivity' => $last ? ['type' => $last['type'], 'label' => self::activityLabel($last['type']), 'at' => $last['created_at']] : null, 'outcome' => $v['outcome']['outcome'] ?? null];
        }
        usort($rows, fn ($a, $b) => strcmp((string) ($b['lastActivity']['at'] ?? ''), (string) ($a['lastActivity']['at'] ?? '')));
        $pages = max(1, (int) ceil(count($rows) / self::PAGE));
        $page = max(1, min($page, $pages));
        $groups = array_values(array_unique(array_filter(array_map(fn ($v) => $v['lead']['signal_role_group'], $d['byLead']))));
        sort($groups);
        return ['rows' => array_slice($rows, ($page - 1) * self::PAGE, self::PAGE), 'total' => count($rows), 'page' => $page, 'pages' => $pages,
            'byStatus' => $byStatus, 'board' => $this->board($rows), 'roleGroups' => $groups];
    }

    /** "Board by stage": up to 12 cards per status column. */
    private function board(array $rows): array
    {
        $cols = [];
        foreach (LeadLogic::STATUSES as $s) $cols[$s] = ['status' => $s, 'count' => 0, 'cards' => []];
        foreach ($rows as $r) {
            $cols[$r['status']]['count']++;
            if (count($cols[$r['status']]['cards']) < 12) $cols[$r['status']]['cards'][] = ['id' => $r['id'], 'name' => $r['name'], 'company' => $r['company'], 'track' => $r['track'], 'level' => $r['level']['label'],
                'last' => $r['lastActivity']['label'] ?? null, 'at' => $r['lastActivity']['at'] ?? null];
        }
        return array_values($cols);
    }

    /** One lead's timeline: signal found -> approved -> pushed -> every step (date, channel) -> current. */
    public function lead(string $id): array
    {
        $l = $this->store->all('SELECT * FROM leads WHERE lead_id = ?', [$id])[0] ?? throw new NotFoundError('Lead not found');
        $d = $this->load([$l['campaign_id']]);
        $v = $d['byLead'][$id];
        $steps = [];
        foreach ($d['steps'][$l['campaign_id']] ?? [] as $s) $steps[$s['step_id']] = $s;
        $events = [];
        if ($l['signal_date_found']) $events[] = ['at' => $l['signal_date_found'], 'label' => 'Signal found', 'detail' => trim(($l['signal_job_title'] ?? '') . ' · ' . ($l['signal_company'] ?? ''), ' ·'), 'kind' => 'signal'];
        if ($l['signal_approved_at']) $events[] = ['at' => $l['signal_approved_at'], 'label' => 'Decision maker approved (' . ($l['signal_track'] === 'HR' ? 'HR Decision Makers' : 'Decision Makers') . ')', 'detail' => 'row ' . $l['signal_row'], 'kind' => 'approval'];
        if ($l['added_at']) $events[] = ['at' => $l['added_at'], 'label' => 'Pushed to lemlist', 'detail' => $this->store->one('SELECT name FROM campaigns WHERE id = ?', [$l['campaign_id']]) ?: '', 'kind' => 'pushed'];
        foreach ($v['acts'] as $a) {
            $s = $steps[$a['step_id'] ?? ''] ?? null;
            $events[] = ['at' => $a['created_at'], 'label' => self::activityLabel($a['type']), 'detail' => $s ? ('Step ' . ((int) $s['seq_step'] + 1) . ' · ' . LeadLogic::stepType($s['type']) . ((int) $s['main'] === 1 ? '' : ' (branch)')) : '',
                'kind' => self::channelOf($a['type'])];
        }
        foreach ($v['calls'] as $c) $events[] = ['at' => gmdate('c', (int) $c['t']), 'label' => 'Call logged: ' . ($d['statuses'][$c['call_status']]['label'] ?? $c['call_status']), 'detail' => 'by ' . $c['by_user'], 'kind' => 'call'];
        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        $teamId = (string) $this->store->meta('team_id', '');
        return ['id' => $id, 'name' => trim($l['first_name'] . ' ' . $l['last_name']), 'title' => $l['title'], 'company' => $l['company'], 'linkedin' => $l['linkedin'],
            'campaign' => $this->store->one('SELECT name FROM campaigns WHERE id = ?', [$l['campaign_id']]) ?: $l['campaign_id'],
            'lemlistUrl' => 'https://app.lemlist.com/' . ($teamId !== '' ? "teams/$teamId/" : '') . 'campaigns/' . rawurlencode($l['campaign_id']),
            'signal' => ['track' => $l['signal_track'], 'jobTitle' => $l['signal_job_title'], 'company' => $l['signal_company'], 'jobUrl' => $l['signal_job_url'], 'roleGroup' => $l['signal_role_group']],
            'atlas' => $l['atlas_pool'] !== null ? ['pool' => (int) $l['atlas_pool'], 'matching' => $l['atlas_matching'] !== null ? (int) $l['atlas_matching'] : null] : null,
            'level' => $v['level'], 'channels' => $v['channels'], 'status' => $v['status'], 'outcome' => $v['outcome'], 'timeline' => $events, 'outcomes' => self::OUTCOMES];
    }

    public static function activityLabel(string $t): string
    {
        static $map = ['emailsSent' => 'Email sent', 'emailsOpened' => 'Email opened', 'emailsClicked' => 'Email link clicked', 'emailsReplied' => 'Email reply', 'emailsBounced' => 'Email bounced',
            'emailsFailed' => 'Email failed', 'emailsUnsubscribed' => 'Unsubscribed', 'linkedinInviteDone' => 'LinkedIn invite sent', 'linkedinInviteAccepted' => 'LinkedIn invite accepted',
            'linkedinSent' => 'LinkedIn message sent', 'linkedinOpened' => 'LinkedIn message opened', 'linkedinReplied' => 'LinkedIn reply', 'linkedinVisitDone' => 'LinkedIn profile visited',
            'aircallDone' => 'Call task done', 'aircallEnded' => 'Call ended', 'aircallInterested' => 'Call: interested', 'aircallNotInterested' => 'Call: not interested',
            'meetingBooked' => 'Meeting booked', 'conditionChosen' => 'Condition', 'paused' => 'Paused', 'resumed' => 'Resumed', 'stopped' => 'Stopped', 'manualDone' => 'Manual task done',
            'emailsInterested' => 'Marked interested', 'linkedinInterested' => 'Marked interested', 'emailsNotInterested' => 'Marked not interested', 'outOfOffice' => 'Out of office'];
        return $map[$t] ?? $t;
    }

    public static function channelOf(string $t): string
    {
        return match (true) {
            str_starts_with($t, 'emails') => 'email',
            str_starts_with($t, 'linkedinInvite') => 'invite',
            str_starts_with($t, 'linkedin') => 'message',
            str_starts_with($t, 'aircall') => 'call',
            $t === 'meetingBooked' => 'meeting',
            default => 'other',
        };
    }

    /** Outcome buttons: stored in the app only (never written to lemlist in this phase). */
    public function setOutcome(mixed $leadId, mixed $outcome, string $user): array
    {
        $leadId = is_string($leadId) ? $leadId : '';
        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $leadId) || !$this->store->one('SELECT 1 FROM leads WHERE lead_id = ?', [$leadId])) throw new NotFoundError('Lead not found');
        $now = ($this->now)();
        if ($outcome === null || $outcome === '') {
            $this->store->db->prepare('DELETE FROM outcomes WHERE lead_id = ?')->execute([$leadId]);
            Audit::event('outreach_outcome', ['user' => $user, 'leadId' => $leadId, 'outcome' => null]);
            return ['outcome' => null];
        }
        if (!in_array($outcome, self::OUTCOMES, true)) throw new ValidationError('Unknown outcome');
        $this->store->db->prepare('INSERT INTO outcomes (lead_id, outcome, by_user, at) VALUES (?,?,?,?) ON CONFLICT(lead_id) DO UPDATE SET outcome = excluded.outcome, by_user = excluded.by_user, at = excluded.at')
            ->execute([$leadId, $outcome, $user, $now]);
        $this->cache = [];
        Audit::event('outreach_outcome', ['user' => $user, 'leadId' => $leadId, 'outcome' => $outcome]);
        return ['outcome' => ['outcome' => $outcome, 'by_user' => $user, 'at' => $now]];
    }
}
