<?php
// Title index for the Atlas candidate pools (storage/atlas-title-index.json).
//
//   Skills        technologies / certifications from the JD dictionary; only vocabulary hits are stored, never free text
//   ReadPacer     spaces Atlas reads out so a build stays under MAX_PER_MINUTE (well below Atlas's 1,200/min)
//   TitleIndex    the built index: per person ONLY {personId, title, normalisedTitle, pastTitles, skills, city, country,
//                 hasCv, listIds, updatedAt}. No names, emails, phones, notes or descriptions.
//   IndexBuilder  nightly, resumable build (cron: private/bin/atlas-index.php, or "Rebuild index" on /lists)
declare(strict_types=1);

namespace SignalDesk;

final class Skills
{
    /**
     * Canonical skills found in $text (each once, in order of appearance). The vocabulary is the "skills" part of the
     * JD dictionary (storage/jd-dictionary.json, default private/jd-dictionary.default.json).
     * @return string[]
     */
    public static function extract(string $text, ?array $dict = null): array
    {
        $dict ??= JdDictionary::default();
        $hits = Phrases::findAll($text, JdParser::skillMap($dict), 'skills:' . md5(json_encode($dict['skills'])));
        return array_values(array_unique(array_column($hits, 0)));
    }
}

/** Spaces reads out: at most $perMinute per minute (a fixed gap between calls). Clock and sleep are injectable. */
final class ReadPacer
{
    /** @var callable(): float */
    public $now;
    /** @var callable(int): void milliseconds */
    public $sleep;
    private float $last = -INF;
    private float $gap;
    public int $reads = 0;

    public function __construct(int $perMinute)
    {
        $this->gap = 60.0 / max(1, $perMinute);
        $this->now = fn () => microtime(true);
        $this->sleep = fn (int $ms) => usleep($ms * 1000);
    }

    public function wait(): void
    {
        $t = ($this->now)();
        $due = $this->last + $this->gap;
        if ($t < $due) {
            ($this->sleep)((int) ceil(($due - $t) * 1000));
            $t = $due;
        }
        $this->last = $t;
        $this->reads++;
    }
}

final class TitleIndex
{
    public const VERSION = 2;
    public const FIELDS = ['personId', 'title', 'normalisedTitle', 'pastTitles', 'skills', 'city', 'country', 'hasCv', 'listIds', 'updatedAt'];
    /** @var array<string,?TitleIndex> per-request cache by file */
    private static array $loaded = [];

    /** @param array<int,array> $entries */
    private function __construct(public readonly array $entries, public readonly int $builtAt, public readonly array $pools) {}

    public static function load(string $file): ?self
    {
        if (array_key_exists($file, self::$loaded)) return self::$loaded[$file];
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $idx = is_array($d) && is_array($d['entries'] ?? null) ? new self($d['entries'], (int) ($d['builtAt'] ?? 0), (array) ($d['pools'] ?? [])) : null;
        return self::$loaded[$file] = $idx;
    }

    public static function forget(): void
    {
        self::$loaded = [];
    }

    /** Index entries on any of these lists (each person once). */
    public function inLists(array $listIds): array
    {
        $want = array_flip($listIds);
        return array_values(array_filter($this->entries, function ($e) use ($want) {
            foreach ($e['listIds'] ?? [] as $l) if (isset($want[$l])) return true;
            return false;
        }));
    }

    /**
     * Entries whose current (normalised) title contains one of the terms as whole words, newest first.
     * @param string[] $terms normalised phrases
     */
    public static function matching(array $entries, array $terms): array
    {
        $terms = array_values(array_filter($terms, fn ($t) => $t !== ''));
        if (!$terms) return [];
        $out = array_values(array_filter($entries, function ($e) use ($terms) {
            $t = (string) ($e['normalisedTitle'] ?? '');
            foreach ($terms as $term) if (preg_match('~(?<![\p{L}\p{N}])' . preg_quote($term, '~') . '(?![\p{L}\p{N}])~u', $t)) return true;
            return false;
        }));
        return self::newestFirst($out);
    }

    public static function newestFirst(array $entries): array
    {
        usort($entries, fn ($a, $b) => strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? '')));
        return $entries;
    }
}

/**
 * Builds storage/atlas-title-index.json from the pools' members. Resumable: progress is saved to
 * storage/atlas-index-build.json (ids and titles only) and the next run (cron or button) carries on.
 */
final class IndexBuilder
{
    public const MAX_PER_MINUTE = 500;        // Atlas allows 1,200 reads/min per agency; stay well under 600
    public const REBUILD_AFTER = 20 * 3600;   // cron rebuilds once the index is older than this
    public const BUTTON_INTERVAL = 3600;      // "Rebuild index" starts a build at most once an hour
    public const SAVE_EVERY = 25;
    public const LOG_EVERY = 250;

    /** @var callable(): int */
    public $now;
    public ReadPacer $pacer;

    public function __construct(
        private AtlasClient $client,
        private array $map,
        private string $storageDir,
        private ?array $dict = null,
    ) {
        $this->now = fn () => time();
        $this->pacer = new ReadPacer(self::MAX_PER_MINUTE);
    }

    public function indexFile(): string
    {
        return $this->storageDir . '/atlas-title-index.json';
    }

    private function stateFile(): string
    {
        return $this->storageDir . '/atlas-index-build.json';
    }

    /** Pool list ids of the map (every family's lists except missing ones). */
    public function poolIds(): array
    {
        $ids = [];
        foreach ($this->map['families'] as $f) {
            foreach ($f['lists'] as $l) {
                if (($l['id'] ?? '') !== '' && ($l['status'] ?? '') !== 'missing') $ids[] = (string) $l['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    private function get(string $path, array $query = []): array
    {
        $this->pacer->wait();
        return $this->client->get($path, $query);
    }

    /** Status for /lists and /health (no personal data). */
    public function status(): array
    {
        $st = JsonStore::read($this->stateFile(), []) ?: [];
        $idx = TitleIndex::load($this->indexFile());
        return [
            'state' => ($st['status'] ?? 'idle') === 'running' ? 'running' : 'idle',
            'phase' => $st['phase'] ?? null,
            'done' => (int) ($st['pos'] ?? 0), 'total' => count($st['order'] ?? []),
            'poolsScanned' => (int) ($st['poolPos'] ?? 0), 'pools' => count($st['pools'] ?? []),
            'startedAt' => isset($st['startedAt']) ? gmdate('c', (int) $st['startedAt']) : null,
            'lastStartedAt' => isset($st['lastStartedAt']) ? (int) $st['lastStartedAt'] : null,
            'builtAt' => $idx ? gmdate('c', $idx->builtAt) : null, 'entries' => $idx ? count($idx->entries) : 0,
            'lastError' => $st['lastError'] ?? null,
        ];
    }

    /**
     * Run (part of) a build for up to $maxSeconds.
     *   cron:   starts a build when the index is missing or older than REBUILD_AFTER, else carries on a running one
     *   button: ($force) starts a build now, at most once an hour; carries on a running one otherwise
     * @return array status + 'result' (fresh|busy|running|paused|done|error)
     */
    public function run(int $maxSeconds, bool $force = false, string $trigger = 'cron'): array
    {
        $lock = @fopen($this->storageDir . '/atlas-index.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return ['result' => 'busy'] + $this->status();
        }
        try {
            return $this->runLocked($maxSeconds, $force, $trigger);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function runLocked(int $maxSeconds, bool $force, string $trigger): array
    {
        $now = ($this->now)();
        $deadline = $now + max(1, $maxSeconds);
        $st = JsonStore::read($this->stateFile(), []) ?: [];
        if (($st['status'] ?? 'idle') !== 'running') {
            $idx = TitleIndex::load($this->indexFile());
            if ($force) {
                $last = (int) ($st['lastStartedAt'] ?? 0);
                if ($now - $last < self::BUTTON_INTERVAL) {
                    throw new AppError(429, 'REBUILD_TOO_SOON', 'The index was rebuilt less than an hour ago. Try again in ' . (int) ceil((self::BUTTON_INTERVAL - ($now - $last)) / 60) . ' minutes.');
                }
            } elseif ($idx && $now - $idx->builtAt < self::REBUILD_AFTER) {
                return ['result' => 'fresh'] + $this->status();
            }
            $pools = $this->poolIds();
            $st = ['status' => 'running', 'trigger' => $trigger, 'startedAt' => $now, 'lastStartedAt' => $now, 'phase' => 'members',
                'pools' => $pools, 'poolPos' => 0, 'page' => 1, 'poolCounts' => [], 'members' => [], 'order' => [], 'pos' => 0, 'entries' => [], 'reads' => 0];
            JsonStore::write($this->stateFile(), $st);
            Audit::event('atlas_index', ['event' => 'start', 'trigger' => $trigger, 'pools' => count($pools)]);
        }
        $readsAtStart = $this->pacer->reads;
        $result = 'running';
        try {
            while (($this->now)() < $deadline) {
                if ($st['phase'] === 'members') {
                    if ($st['poolPos'] >= count($st['pools'])) {
                        $st['order'] = array_keys($st['members']);
                        $st['phase'] = 'people';
                        Audit::event('atlas_index', ['event' => 'members', 'people' => count($st['order'])]);
                        continue;
                    }
                    $listId = $st['pools'][$st['poolPos']];
                    try {
                        $r = $this->get("/api/v1/lists/$listId/members", ['page' => $st['page'], 'pageSize' => 100]);
                    } catch (NotFoundError) {
                        $r = ['data' => [], 'pagination' => ['totalPages' => 0]];
                    }
                    foreach ($r['data'] ?? [] as $m) {
                        $pid = (string) ($m['personId'] ?? $m['person']['id'] ?? '');
                        if (!AtlasGuard::isUuid($pid)) continue;
                        $st['members'][$pid][] = $listId;
                        $st['poolCounts'][$listId] = ($st['poolCounts'][$listId] ?? 0) + 1;
                    }
                    if ($st['page'] >= (int) ($r['pagination']['totalPages'] ?? 1)) {
                        $st['poolPos']++;
                        $st['page'] = 1;
                    } else {
                        $st['page']++;
                    }
                    continue;
                }
                // phase "people"
                if ($st['pos'] >= count($st['order'])) {
                    $this->finish($st);
                    $result = 'done';
                    break;
                }
                $pid = $st['order'][$st['pos']];
                $entry = $this->entryFor($pid, array_values(array_unique($st['members'][$pid] ?? [])));
                if ($entry) $st['entries'][$pid] = $entry;
                $st['pos']++;
                if ($st['pos'] % self::SAVE_EVERY === 0) JsonStore::write($this->stateFile(), $st);
                if ($st['pos'] % self::LOG_EVERY === 0) Audit::event('atlas_index', ['event' => 'progress', 'done' => $st['pos'], 'total' => count($st['order'])]);
            }
        } catch (AtlasRateLimited $e) {
            $result = 'paused';
            Audit::event('atlas_index', ['event' => 'paused', 'reason' => 'rate limit', 'retryAfter' => $e->retryAfter, 'done' => $st['pos']]);
        } catch (AppError $e) {
            $result = 'error';
            $st['lastError'] = $e->getMessage();
            Audit::event('atlas_index', ['event' => 'error', 'message' => $e->getMessage(), 'done' => $st['pos']]);
        }
        if ($result !== 'done') {
            $st['reads'] = (int) ($st['reads'] ?? 0) + ($this->pacer->reads - $readsAtStart);
            JsonStore::write($this->stateFile(), $st);
        }
        return ['result' => $result] + $this->status();
    }

    /** One person's index entry: GET /people/{id} (headline, experience roles, address) + whether a CV file exists. */
    private function entryFor(string $pid, array $listIds): ?array
    {
        try {
            $p = $this->get("/api/v1/people/$pid")['data'] ?? [];
        } catch (NotFoundError) {
            return null; // removed from Atlas since the member pages were read
        }
        $files = $this->get('/api/v1/files', ['personId' => $pid, 'type' => 'resume', 'page' => 1, 'pageSize' => 1]);
        return self::entryFrom($pid, $p, (int) ($files['pagination']['total'] ?? count($files['data'] ?? [])) > 0, $listIds, null, $this->map['seniorityWords'] ?? [], $this->dict);
    }

    /**
     * Keep ONLY the index fields. Names, emails, phones, identities, compensation, notes and free-text descriptions are
     * read in memory to find skills and then dropped.
     */
    public static function entryFrom(string $pid, array $p, bool $hasCv, array $listIds, ?callable $normalise = null, array $seniority = [], ?array $dict = null): array
    {
        $cut = fn (string $s, int $n) => function_exists('mb_substr') ? mb_substr(trim($s), 0, $n) : substr(trim($s), 0, $n);
        $title = $cut((string) ($p['headline']['role'] ?? ''), 120);
        $exp = is_array($p['experience'] ?? null) ? $p['experience'] : [];
        usort($exp, fn ($a, $b) => strcmp((string) ($b['startDate'] ?? ''), (string) ($a['startDate'] ?? '')));
        $past = [];
        $text = [$title];
        foreach ($exp as $e) {
            $role = $cut((string) ($e['role'] ?? ''), 120);
            $text[] = $role . ' ' . (string) ($e['description'] ?? '');
            if ($role === '' || strcasecmp($role, $title) === 0) continue;
            if (!in_array(strtolower($role), array_map('strtolower', $past), true) && count($past) < 5) $past[] = $role;
        }
        foreach ($p['customAttributes'] ?? [] as $a) {
            if (!preg_match('/skill|tech|certif|tool|expertise/i', (string) ($a['attributeName'] ?? ''))) continue;
            foreach ($a['values'] ?? [] as $v) $text[] = is_array($v) ? (string) ($v['optionValue'] ?? '') : (string) $v;
        }
        $normalise ??= ListMap::normaliseTitle(...);
        return [
            'personId' => $pid,
            'title' => $title,
            'normalisedTitle' => $normalise($title, $seniority),
            'pastTitles' => $past,
            'skills' => Skills::extract(implode("\n", $text), $dict),
            'city' => $cut((string) ($p['address']['city'] ?? ''), 80),
            'country' => $cut((string) ($p['address']['country'] ?? ''), 80),
            'hasCv' => $hasCv,
            'listIds' => $listIds,
            'updatedAt' => substr((string) ($p['updatedAt'] ?? ''), 0, 10),
        ];
    }

    private function finish(array $st): void
    {
        $now = ($this->now)();
        $index = ['version' => TitleIndex::VERSION, 'builtAt' => $now, 'startedAt' => $st['startedAt'], 'fields' => TitleIndex::FIELDS,
            'pools' => $st['poolCounts'], 'entries' => array_values($st['entries'])];
        $tmp = $this->indexFile() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!@rename($tmp, $this->indexFile())) {
            copy($tmp, $this->indexFile());
            @unlink($tmp);
        }
        TitleIndex::forget();
        JsonStore::write($this->stateFile(), ['status' => 'idle', 'lastStartedAt' => $st['lastStartedAt'], 'lastBuiltAt' => $now]);
        Audit::event('atlas_index', ['event' => 'done', 'entries' => count($st['entries']), 'pools' => count($st['poolCounts']),
            'seconds' => $now - (int) $st['startedAt'], 'reads' => (int) ($st['reads'] ?? 0) + $this->pacer->reads]);
    }
}
