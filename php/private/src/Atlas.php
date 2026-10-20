<?php
// Candidate Desk: Atlas (recruitwithatlas.com) candidate lists, READ-ONLY.
//
//   AtlasGuard        GET-only allowlist: any other method or endpoint is refused before a request is made.
//   AtlasClient       curl, Bearer key, timeouts, 429 handling (retryAfterSec / RateLimit-Reset / Retry-After),
//                     audit log of method + endpoint pattern + status only (no ids, no personal data).
//   ListMap           storage/atlas-list-map.json (editable): Role Sub-Group / title keywords / Role Group -> list family.
//   AtlasListsSource  CandidateSource backed by the lists. Only aggregate counts (and list names/ids) are cached,
//                     for 6 hours. Candidate names, roles and locations are fetched live and never written to disk.
//   MockAtlas         fake Atlas API for ATLAS_BACKEND=mock and the tests.
declare(strict_types=1);

namespace SignalDesk;

final class AtlasRateLimited extends AppError
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(429, 'ATLAS_RATE_LIMITED', "Atlas rate limit reached. Try again in about $retryAfter seconds.");
    }
}

/** Read-only allowlist for the Atlas API. Mirrors Guard.php: refuse first, never "try and see". */
final class AtlasGuard
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    /** pattern label => regex. Only these GET endpoints may ever be called. */
    private const ALLOWED = [
        '/api/v1/lists' => '#^/api/v1/lists$#',
        '/api/v1/lists/{id}/members' => '#^/api/v1/lists/' . self::UUID . '/members$#',
        '/api/v1/people/{id}' => '#^/api/v1/people/' . self::UUID . '$#',
        '/api/v1/files' => '#^/api/v1/files$#',
        '/api/v1/files/{id}/download' => '#^/api/v1/files/' . self::UUID . '/download$#',
        '/api/v1/users' => '#^/api/v1/users$#',
    ];

    /** @return string the endpoint pattern (ids replaced by {id}), safe for logs */
    public static function assert(string $method, string $path): string
    {
        if (strtoupper($method) !== 'GET') {
            throw new ForbiddenWriteError("Refused: Signal Desk never sends $method requests to Atlas (read-only)");
        }
        foreach (self::ALLOWED as $label => $re) {
            if (preg_match($re, $path)) return $label;
        }
        throw new ForbiddenWriteError('Refused: this Atlas endpoint is not on the read-only allowlist');
    }

    public static function isUuid(string $s): bool
    {
        return (bool) preg_match('/^' . self::UUID . '$/', $s);
    }
}

final class AtlasClient
{
    /** @var callable|null fn(string $url, array $headers): array{status:int,headers:array<string,string>,body:string} */
    public $transport = null;
    /** @var callable fn(int $ms) */
    public $sleep;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $maxWaitSec = 10,
        private int $maxAttempts = 3,
    ) {
        $this->sleep = fn (int $ms) => usleep($ms * 1000);
    }

    /** GET an allowlisted endpoint. Returns the decoded JSON body. */
    public function get(string $path, array $query = []): array
    {
        $pattern = AtlasGuard::assert('GET', $path);
        $url = rtrim($this->baseUrl, '/') . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'];
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $t0 = microtime(true);
            $res = $this->transport ? ($this->transport)($url, $headers) : $this->curl($url, $headers);
            $s = $res['status'];
            $h = array_change_key_case($res['headers'] ?? [], CASE_LOWER);
            $remaining = isset($h['ratelimit-remaining']) ? (int) $h['ratelimit-remaining'] : null;
            Audit::event('atlas_call', ['method' => 'GET', 'endpoint' => $pattern, 'status' => $s, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'remaining' => $remaining]);

            if ($s >= 200 && $s < 300) {
                if ($remaining !== null && $remaining < 5) ($this->sleep)(1000); // slow down before the window runs out
                $data = json_decode($res['body'], true);
                if (!is_array($data)) throw new UpstreamError('Atlas returned an unexpected response');
                return $data;
            }
            if ($s === 429) {
                $body = json_decode($res['body'], true);
                $wait = (int) ($body['retryAfterSec'] ?? $h['ratelimit-reset'] ?? $h['retry-after'] ?? 0);
                if ($wait <= 0) $wait = 2 ** ($attempt - 1);
                if ($wait > $this->maxWaitSec || $attempt === $this->maxAttempts) throw new AtlasRateLimited($wait);
                ($this->sleep)($wait * 1000);
                continue;
            }
            if ($s >= 500 && $attempt < $this->maxAttempts) {
                ($this->sleep)(500 * $attempt);
                continue;
            }
            if ($s === 401 || $s === 403) throw new UpstreamError('Atlas refused the API key (check ATLAS_API_KEY in config.php)');
            if ($s === 404) throw new NotFoundError('Not found in Atlas');
            throw new UpstreamError("Atlas error $s");
        }
        throw new UpstreamError('Atlas did not answer');
    }

    private function curl(string $url, array $headers): array
    {
        $ch = curl_init($url);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true, // GET only, by construction
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            curl_close($ch);
            Audit::event('atlas_call', ['method' => 'GET', 'endpoint' => 'network error', 'status' => 0]);
            throw new UpstreamError('Could not reach Atlas');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $out];
    }
}

/** Small JSON file cache with a TTL. Used ONLY for aggregate data (counts, list names/ids/member counts). */
final class TtlFileCache
{
    /** @var callable */
    public $now;

    public function __construct(private string $dir, private int $ttl)
    {
        $this->now = fn () => time();
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . md5($key) . '.json';
    }

    public function get(string $key): mixed
    {
        $c = JsonStore::read($this->file($key), null);
        if (!is_array($c) || ($c['time'] ?? 0) <= ($this->now)() - $this->ttl) return null;
        return $c['data'];
    }

    public function set(string $key, mixed $data): void
    {
        JsonStore::write($this->file($key), ['time' => ($this->now)(), 'data' => $data]);
    }

    /** Remove every cached entry (used by "Refresh from Atlas"). */
    public function clear(): int
    {
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) if (@unlink($f)) $n++;
        return $n;
    }
}

/**
 * storage/atlas-list-map.json: which list families match a signal. Editable on the /lists page or over SFTP.
 * Format (version 2): families{ name: { lists:[{id,name,type,status?,auto?}], roleSubGroups[], keywords[], roleGroups[],
 * auto?, reviewed? } }, ignoredLists[{id,name}], seniorityWords[].
 */
final class ListMap
{
    public const LIST_TYPES = ['permanent', 'freelance', 'contract', 'interim', 'temporary', 'fixed-term'];

    /** @return array{map:array,file:string,error:?string} */
    public static function load(string $storageDir, string $defaultFile): array
    {
        $file = $storageDir . '/atlas-list-map.json';
        if (!is_file($file) && is_file($defaultFile)) @copy($defaultFile, $file);
        $error = null;
        $map = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($map)) {
            $error = is_file($file) ? 'atlas-list-map.json is not valid JSON; using the default map' : null;
            $map = self::upgrade(json_decode((string) @file_get_contents($defaultFile), true) ?: ['families' => []]);
        } else {
            $map = self::upgrade($map);
            if ($e = self::validate($map)) {
                $error = "atlas-list-map.json: $e; using the default map";
                $map = self::upgrade(json_decode((string) @file_get_contents($defaultFile), true) ?: ['families' => []]);
            }
        }
        return ['map' => $map, 'file' => is_file($file) ? $file : $defaultFile, 'error' => $error];
    }

    /** Version-1 files (permanent/freelance keys) become version 2 (lists[]). Hand-edited old files keep working. */
    public static function upgrade(array $m): array
    {
        foreach ($m['families'] ?? [] as $name => $f) {
            if (!is_array($f)) continue;
            if (!isset($f['lists'])) {
                $lists = [];
                foreach (['permanent', 'freelance'] as $t) {
                    if (!empty($f[$t]) && is_array($f[$t])) $lists[] = ['id' => (string) ($f[$t]['id'] ?? ''), 'name' => (string) ($f[$t]['name'] ?? ''), 'type' => $t];
                }
                $f['lists'] = $lists;
            }
            unset($f['permanent'], $f['freelance']);
            foreach (['roleSubGroups', 'keywords', 'roleGroups'] as $k) $f[$k] = is_array($f[$k] ?? null) ? array_values($f[$k]) : [];
            $m['families'][$name] = $f;
        }
        $m['ignoredLists'] = is_array($m['ignoredLists'] ?? null) ? $m['ignoredLists'] : [];
        $m['version'] = 2;
        return $m;
    }

    public static function validate(array $m): ?string
    {
        if (!isset($m['families']) || !is_array($m['families']) || !$m['families']) return '"families" is missing or empty';
        foreach ($m['families'] as $name => $f) {
            if (!is_array($f)) return "family \"$name\" is not an object";
            if (!is_array($f['lists'] ?? null) || !$f['lists']) return "family \"$name\" has no lists";
            foreach ($f['lists'] as $l) {
                if (!is_array($l) || (($l['id'] ?? '') === '' && ($l['name'] ?? '') === '')) return "family \"$name\": every list needs an id or a name";
            }
            foreach (['roleSubGroups', 'keywords', 'roleGroups'] as $k) {
                if (isset($f[$k]) && !is_array($f[$k])) return "family \"$name\": \"$k\" must be a list";
            }
        }
        return null;
    }

    /** Pretty-printed, atomic, locked write of storage/atlas-list-map.json (the app's own storage only). */
    public static function save(string $file, array $map): void
    {
        JsonStore::locked($file . '.lock', function () use ($file, $map) {
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            if (!@rename($tmp, $file)) {
                copy($tmp, $file);
                @unlink($tmp);
            }
        });
    }

    /** "Security Architects – Permanent" -> ["Security Architects", "permanent"]; no known suffix -> [name, "other"]. */
    public static function parseListName(string $name): array
    {
        $re = '~^(.*\S)\s*[–—-]\s*(permanent|perm|freelancers?|freelance|contractors?|contract|interim|temporary|temp|fixed[- ]term)\s*$~iu';
        if (!preg_match($re, trim($name), $m)) return [trim($name), 'other'];
        $t = strtolower($m[2]);
        $type = match (true) {
            str_starts_with($t, 'perm') => 'permanent',
            str_starts_with($t, 'freelance') => 'freelance',
            str_starts_with($t, 'contract') => 'contract',
            $t === 'interim' => 'interim',
            str_starts_with($t, 'temp') => 'temporary',
            default => 'fixed-term',
        };
        return [trim($m[1]), $type];
    }

    /** Compare family names loosely: case, dash style and spacing don't matter. */
    public static function familyKey(string $s): string
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        return trim(preg_replace('~\s+~u', ' ', str_replace(['–', '—'], '-', $s)) ?? $s);
    }

    /** "Consultants" -> "consultant", "Specialists" -> "specialist", "Libraries" -> "library". */
    public static function singular(string $word): string
    {
        $w = strtolower($word);
        if (preg_match('/ies$/', $w)) return substr($w, 0, -3) . 'y';
        if (preg_match('/(ss|us|is)$/', $w)) return $w;
        if (preg_match('/(ch|sh|x)es$/', $w)) return substr($w, 0, -2);
        if (str_ends_with($w, 's') && strlen($w) > 3) return substr($w, 0, -1);
        return $w;
    }

    /** Keyword for an auto-created family: last " – " segment, lower-cased, last word singular. "SAP Consultants" -> "sap consultant". */
    public static function autoKeyword(string $family): string
    {
        $parts = preg_split('~\s+[–—-]\s+~u', trim($family)) ?: [$family];
        $seg = trim((string) end($parts));
        $words = preg_split('~\s+~u', $seg) ?: [$seg];
        $last = array_pop($words);
        return trim(strtolower(implode(' ', $words)) . ' ' . self::singular((string) $last));
    }

    /** Lower-case, strip gender markers like (m/f/x), (h/f), (m/w/d), punctuation; optionally drop seniority words. */
    public static function normaliseTitle(string $title, array $seniorityWords = [], bool $dropSeniority = true): string
    {
        $t = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
        $g = '[mfhvwxd]';
        $t = preg_replace('~[(\[]\s*(?:all genders?|' . $g . '(?:\s*[/,|]\s*' . $g . '){1,3})\s*[)\]]~u', ' ', $t) ?? $t;
        $t = preg_replace('~(?<![a-z0-9])' . $g . '\s*/\s*' . $g . '(?:\s*/\s*' . $g . ')?(?![a-z0-9])~u', ' ', $t) ?? $t;
        $t = preg_replace('~[^\p{L}\p{N}#+./&\s-]+~u', ' ', $t) ?? $t; // keep c#, c++, .net, asp.net, r&d
        $t = preg_replace('~(?<=\s|^)[-/.&]+(?=\s|$)~u', ' ', $t) ?? $t; // lone punctuation
        $t = trim(preg_replace('~\s+~u', ' ', $t) ?? $t);
        if ($dropSeniority && $seniorityWords) {
            $words = array_map(fn ($w) => preg_quote(strtolower($w), '~'), $seniorityWords);
            $t = trim(preg_replace('~\s+~', ' ', preg_replace('~(?<![\p{L}\p{N}])(?:' . implode('|', $words) . ')(?![\p{L}\p{N}])~u', ' ', $t) ?? $t) ?? $t);
        }
        return $t;
    }

    private static function code(string $s): ?string
    {
        return preg_match('/^\s*([A-Za-z]\d{0,2})\b/', $s, $m) ? strtoupper($m[1]) : null;
    }

    private static function containsPhrase(string $haystack, string $phrase): bool
    {
        return $phrase !== '' && (bool) preg_match('~(?<![\p{L}\p{N}])' . preg_quote($phrase, '~') . '(?![\p{L}\p{N}])~u', $haystack);
    }

    /**
     * Families for a signal: 1) Role Sub-Group, 2) title keywords, 3) Role Group. First rule with a hit decides.
     * With $byListName (Candidates search), families whose own name or list names contain the search are added too.
     * @return array{families:string[],reason:string,normalisedTitle:string}
     */
    public static function match(array $map, string $title, string $subGroup = '', string $roleGroup = '', bool $byListName = false): array
    {
        $families = $map['families'] ?? [];
        $norm = self::normaliseTitle($title, $map['seniorityWords'] ?? []);
        $hit = function (string $field, callable $test) use ($families): array {
            $out = [];
            foreach ($families as $name => $f) {
                foreach ($f[$field] ?? [] as $entry) {
                    if (is_string($entry) && $entry !== '' && $test($entry)) {
                        $out[] = (string) $name;
                        break;
                    }
                }
            }
            return $out;
        };
        $found = [];
        $reason = 'none';
        $sub = trim($subGroup);
        if ($sub !== '') {
            $subCode = self::code($sub);
            $found = $hit('roleSubGroups', function ($e) use ($sub, $subCode) {
                if (strcasecmp(trim($e), $sub) === 0) return true;
                $ec = self::code($e);
                return $ec !== null && strlen($ec) > 1 && $ec === $subCode && (strlen(trim($e)) <= 3 || stripos($sub, trim($e)) === 0);
            });
            if ($found) $reason = 'Role Sub-Group';
        }
        if (!$found && $norm !== '') {
            $found = $hit('keywords', fn ($k) => self::containsPhrase($norm, self::normaliseTitle($k, [], false)));
            if ($found) $reason = 'title keywords';
        }
        if (!$found && trim($roleGroup) !== '') {
            $grp = trim($roleGroup);
            $found = $hit('roleGroups', fn ($e) => strcasecmp(trim($e), $grp) === 0);
            if ($found) $reason = 'Role Group';
        }
        if ($byListName && $norm !== '') {
            $words = explode(' ', $norm);
            $singularQuery = trim(implode(' ', array_slice($words, 0, -1)) . ' ' . self::singular((string) end($words)));
            $named = [];
            foreach ($families as $name => $f) {
                $names = [(string) $name];
                foreach ($f['lists'] ?? [] as $l) $names[] = (string) ($l['name'] ?? '');
                foreach ($names as $n) {
                    $nn = self::normaliseTitle($n, [], false);
                    $singularName = implode(' ', array_map([self::class, 'singular'], explode(' ', $nn)));
                    if (self::containsPhrase($nn, $norm) || self::containsPhrase($singularName, $singularQuery)) {
                        $named[] = (string) $name;
                        break;
                    }
                }
            }
            $extra = array_values(array_diff($named, $found));
            if ($extra) {
                $found = array_merge($found, $extra);
                $reason = $reason === 'none' ? 'list name' : "$reason + list name";
            }
        }
        // keep map order
        $order = array_flip(array_map('strval', array_keys($families)));
        usort($found, fn ($a, $b) => $order[$a] <=> $order[$b]);
        return ['families' => array_values(array_unique($found)), 'reason' => $reason, 'normalisedTitle' => $norm];
    }

    /**
     * Compare the map with Atlas's lists (by id). New lists join the family with the same name, or create an
     * "auto" family (reviewed: false). Lists no longer in Atlas are marked "missing"; reappearing ones are restored.
     * @param array<string,array{name:string,memberCount:int}> $atlasLists
     * @return array{map:array,changes:array<int,string>}
     */
    public static function reconcile(array $map, array $atlasLists, string $today): array
    {
        $changes = [];
        $known = [];
        foreach ($map['families'] as $fam => &$f) {
            foreach ($f['lists'] as &$l) {
                $id = (string) ($l['id'] ?? '');
                if ($id === '' && ($l['name'] ?? '') !== '') {
                    foreach ($atlasLists as $aid => $a) {
                        if (strcasecmp($a['name'], (string) $l['name']) === 0 && !isset($known[$aid])) {
                            $l['id'] = $id = (string) $aid;
                            $changes[] = "id filled in for \"{$l['name']}\"";
                            break;
                        }
                    }
                }
                if ($id !== '') $known[$id] = true;
                if ($id !== '' && !isset($atlasLists[$id])) {
                    if (($l['status'] ?? '') !== 'missing') {
                        $l['status'] = 'missing';
                        $l['missingSince'] = $today;
                        $changes[] = "list \"{$l['name']}\" not found in Atlas: marked missing";
                    }
                } elseif ($id !== '') {
                    if (($l['status'] ?? '') === 'missing') {
                        unset($l['status'], $l['missingSince']);
                        $changes[] = "list \"{$l['name']}\" is back in Atlas";
                    }
                    if ($atlasLists[$id]['name'] !== ($l['name'] ?? '')) {
                        $changes[] = "list renamed in Atlas: \"" . ($l['name'] ?? '') . "\" -> \"{$atlasLists[$id]['name']}\"";
                        $l['name'] = $atlasLists[$id]['name'];
                    }
                }
            }
            unset($l);
        }
        unset($f);
        foreach ($map['ignoredLists'] as $ig) if (!empty($ig['id'])) $known[(string) $ig['id']] = true;

        $byKey = [];
        foreach ($map['families'] as $fam => $f) $byKey[self::familyKey((string) $fam)] = (string) $fam;
        foreach ($atlasLists as $id => $a) {
            if (isset($known[$id])) continue;
            [$family, $type] = self::parseListName($a['name']);
            $entry = ['id' => (string) $id, 'name' => $a['name'], 'type' => $type, 'auto' => true, 'addedAt' => $today];
            $key = self::familyKey($family);
            if (isset($byKey[$key])) {
                $map['families'][$byKey[$key]]['lists'][] = $entry;
                $changes[] = "new list \"{$a['name']}\" added to family \"{$byKey[$key]}\"";
            } else {
                $map['families'][$family] = ['lists' => [$entry], 'roleSubGroups' => [], 'keywords' => [self::autoKeyword($family)], 'roleGroups' => [],
                    'auto' => true, 'reviewed' => false, 'addedAt' => $today];
                $byKey[$key] = $family;
                $changes[] = "new family \"$family\" created from list \"{$a['name']}\" (please review)";
            }
            $known[$id] = true;
        }
        return ['map' => $map, 'changes' => $changes];
    }
}

interface CandidateSource
{
    /** @return array{families:string[],reason:string,normalisedTitle:string} */
    public function match(string $title, string $subGroup = '', string $roleGroup = ''): array;

    /** Aggregate counts for families: n (deduplicated), p (permanent), f (freelance), and per-list counts. */
    public function counts(array $families): array;

    /** Matched families (incl. list-name matches) + counts + one page of deduplicated members (fetched live). */
    public function search(string $title, string $subGroup, string $roleGroup, int $page): array;

    /** Short-lived CV download URL for a person, fetched on demand, or null. */
    public function cvUrl(string $personId): ?string;

    /** @return array<int,array{0:string,1:string,2:string}> health rows */
    public function health(): array;
}

final class AtlasListsSource implements CandidateSource
{
    public const PAGE_SIZE = 25;
    public const DISCOVERY_INTERVAL = 3600; // at most once an hour
    public const REFRESH_INTERVAL = 60;     // "Refresh from Atlas" at most once a minute

    /** @var callable */
    public $now;
    private bool $discoveryChecked = false;

    public function __construct(
        private AtlasClient $client,
        private array $map,
        private TtlFileCache $cache,
        private string $profileUrlTemplate = 'https://my.recruitwithatlas.com/people-v2?profile={id}',
        private ?string $mapFile = null,
        private ?string $stateFile = null,
    ) {
        $this->now = fn () => time();
    }

    public function map(): array
    {
        return $this->map;
    }

    public function match(string $title, string $subGroup = '', string $roleGroup = ''): array
    {
        $this->discover();
        return ListMap::match($this->map, $title, $subGroup, $roleGroup);
    }

    private function state(): array
    {
        return $this->stateFile ? (JsonStore::read($this->stateFile, []) ?: []) : [];
    }

    private function setState(array $patch): void
    {
        if ($this->stateFile) JsonStore::update($this->stateFile, [], fn ($s) => array_merge(is_array($s) ? $s : [], $patch));
    }

    /** All candidate lists the key can see: id => [name, memberCount], fresh from Atlas (paged). */
    private function fetchLists(): array
    {
        $out = [];
        for ($page = 1; $page <= 20; $page++) {
            $r = $this->client->get('/api/v1/lists', ['entityType' => 'candidate', 'page' => $page, 'pageSize' => 100]);
            foreach ($r['data'] ?? [] as $l) $out[(string) $l['id']] = ['name' => (string) $l['name'], 'memberCount' => (int) ($l['memberCount'] ?? 0)];
            if ($page >= (int) ($r['pagination']['totalPages'] ?? 1)) break;
        }
        return $out;
    }

    /** Lists, cached 6 h (aggregate metadata only). */
    private function lists(): array
    {
        $cached = $this->cache->get('lists');
        if (is_array($cached)) return $cached;
        $lists = $this->fetchLists();
        $this->cache->set('lists', $lists);
        return $lists;
    }

    /**
     * Auto-discovery: at most once an hour (or when forced), compare Atlas's lists with the map by id and update
     * storage/atlas-list-map.json. Never throws: a failure is logged and the current map is kept.
     * @return array<int,string> changes
     */
    public function discover(bool $force = false): array
    {
        if ($this->discoveryChecked && !$force) return [];
        $this->discoveryChecked = true;
        $now = ($this->now)();
        if (!$force && ($now - (int) ($this->state()['discoveredAt'] ?? 0)) < self::DISCOVERY_INTERVAL) return [];
        try {
            $this->setState(['discoveredAt' => $now]); // claim the slot first, so concurrent requests don't all call Atlas
            $lists = $this->fetchLists();
            $this->cache->set('lists', $lists);
            $r = ListMap::reconcile($this->map, $lists, gmdate('Y-m-d', $now));
            if ($r['changes']) {
                $this->map = $r['map'];
                if ($this->mapFile) ListMap::save($this->mapFile, $this->map);
                Audit::event('atlas_map_auto', ['changes' => $r['changes']]);
            }
            return $r['changes'];
        } catch (\Throwable $e) {
            Audit::event('atlas_discovery_failed', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /** "Refresh from Atlas": clear the count/list caches and run discovery now. At most once a minute. */
    public function refresh(string $user): array
    {
        $now = ($this->now)();
        $last = (int) ($this->state()['refreshedAt'] ?? 0);
        if ($now - $last < self::REFRESH_INTERVAL) {
            throw new AppError(429, 'REFRESH_TOO_SOON', 'Refreshed less than a minute ago. Try again in ' . (self::REFRESH_INTERVAL - ($now - $last)) . ' seconds.');
        }
        $this->setState(['refreshedAt' => $now]);
        $cleared = $this->cache->clear();
        $this->discoveryChecked = false;
        $changes = $this->discover(true);
        Audit::event('atlas_refresh', ['user' => $user, 'cacheFilesCleared' => $cleared, 'mapChanges' => count($changes)]);
        return ['cleared' => $cleared, 'changes' => $changes];
    }

    /** A family's lists that exist in Atlas (missing lists are left out of the counts). */
    private function familyLists(string $family): array
    {
        $f = $this->map['families'][$family] ?? null;
        if (!$f) return [];
        $all = $this->lists();
        $out = [];
        foreach ($f['lists'] ?? [] as $ref) {
            $id = (string) ($ref['id'] ?? '');
            if ($id === '' || !isset($all[$id])) {
                $id = '';
                foreach ($all as $lid => $l) if (strcasecmp($l['name'], (string) ($ref['name'] ?? '')) === 0) $id = (string) $lid;
            }
            $out[] = ['type' => (string) ($ref['type'] ?? 'other'), 'id' => $id, 'name' => $id !== '' ? $all[$id]['name'] : (string) ($ref['name'] ?? ''),
                'memberCount' => $id !== '' ? $all[$id]['memberCount'] : 0, 'found' => $id !== ''];
        }
        return $out;
    }

    /** Member ids of a list, newest first (transient: never stored). @return array<int,array{0:string,1:string}> */
    private function memberIds(string $listId): array
    {
        $out = [];
        for ($page = 1; $page <= 100; $page++) {
            try {
                $r = $this->client->get("/api/v1/lists/$listId/members", ['page' => $page, 'pageSize' => 100]);
            } catch (NotFoundError) {
                return $out; // deleted in Atlas since the list cache was filled: count it as empty
            }
            foreach ($r['data'] ?? [] as $m) $out[] = [(string) ($m['personId'] ?? $m['person']['id'] ?? ''), (string) ($m['addedAt'] ?? '')];
            if ($page >= (int) ($r['pagination']['totalPages'] ?? 1)) break;
        }
        return $out;
    }

    /** Deduplicated members across the families' lists: personId => [addedAt, types[]] (in memory only). */
    private function union(array $families, array &$lists): array
    {
        $members = [];
        $lists = [];
        foreach ($families as $fam) {
            foreach ($this->familyLists($fam) as $l) {
                $l['family'] = $fam;
                $lists[] = $l;
                if (!$l['found']) continue;
                foreach ($this->memberIds($l['id']) as [$pid, $added]) {
                    if ($pid === '') continue;
                    $m = $members[$pid] ?? ['addedAt' => '', 'types' => []];
                    if ($added > $m['addedAt']) $m['addedAt'] = $added;
                    $m['types'][$l['type']] = true;
                    $members[$pid] = $m;
                }
            }
        }
        return $members;
    }

    private static function countsFrom(array $members, array $lists): array
    {
        $p = 0;
        $f = 0;
        foreach ($members as $m) {
            if (isset($m['types']['permanent'])) $p++;
            if (isset($m['types']['freelance'])) $f++;
        }
        return [
            'n' => count($members), 'p' => $p, 'f' => $f,
            'lists' => array_map(fn ($l) => ['family' => $l['family'], 'type' => $l['type'], 'name' => $l['name'], 'count' => $l['memberCount'], 'found' => $l['found']], $lists),
        ];
    }

    private static function countsKey(array $families): string
    {
        $f = array_values(array_unique($families));
        sort($f);
        return 'counts:' . implode('|', $f);
    }

    /** n / p / f (deduplicated) for these families. Cached 6 h: numbers and list names only. */
    public function counts(array $families): array
    {
        if (!$families) return ['n' => 0, 'p' => 0, 'f' => 0, 'lists' => []];
        $this->discover();
        $key = self::countsKey($families);
        $cached = $this->cache->get($key);
        if (is_array($cached)) return $cached;
        $lists = [];
        $counts = self::countsFrom($this->union($families, $lists), $lists);
        $this->cache->set($key, $counts);
        return $counts;
    }

    public function search(string $title, string $subGroup, string $roleGroup, int $page): array
    {
        $this->discover();
        $m = ListMap::match($this->map, $title, $subGroup, $roleGroup, true);
        $base = ['families' => $m['families'], 'reason' => $m['reason'], 'normalisedTitle' => $m['normalisedTitle']];
        if (!$m['families']) return $base + ['counts' => ['n' => 0, 'p' => 0, 'f' => 0, 'lists' => []], 'members' => [], 'page' => 1, 'totalPages' => 0];

        $lists = [];
        $members = $this->union($m['families'], $lists);
        $counts = self::countsFrom($members, $lists);
        $this->cache->set(self::countsKey($m['families']), $counts); // keep badges in step with the page
        uasort($members, fn ($a, $b) => strcmp($b['addedAt'], $a['addedAt']));
        $totalPages = max(1, (int) ceil(count($members) / self::PAGE_SIZE));
        $page = max(1, min($page, $totalPages));
        $slice = array_slice($members, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, true);

        $rows = [];
        foreach ($slice as $pid => $mm) {
            $row = ['id' => $pid, 'types' => array_keys($mm['types']), 'addedAt' => $mm['addedAt'],
                'profileUrl' => str_replace('{id}', rawurlencode($pid), $this->profileUrlTemplate)];
            try {
                $p = $this->client->get("/api/v1/people/$pid")['data'] ?? [];
                $addr = $p['address'] ?? [];
                $loc = trim(implode(', ', array_filter([$addr['city'] ?? null, $addr['country'] ?? null])));
                $rows[] = $row + [
                    'name' => trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? '')) ?: '(no name)',
                    'role' => (string) ($p['headline']['role'] ?? ''), 'company' => (string) ($p['headline']['company'] ?? ''),
                    'location' => $loc !== '' ? $loc : (string) ($addr['formattedAddress'] ?? ''),
                    'updatedAt' => substr((string) ($p['updatedAt'] ?? ''), 0, 10),
                ];
            } catch (NotFoundError) {
                $rows[] = $row + ['name' => '(no longer in Atlas)', 'role' => '', 'company' => '', 'location' => '', 'updatedAt' => ''];
            }
        }
        return $base + ['counts' => $counts, 'members' => $rows, 'page' => $page, 'totalPages' => $totalPages];
    }

    public function cvUrl(string $personId): ?string
    {
        if (!AtlasGuard::isUuid($personId)) throw new ValidationError('Invalid person id');
        $files = $this->client->get('/api/v1/files', ['personId' => $personId, 'type' => 'resume', 'page' => 1, 'pageSize' => 20])['data'] ?? [];
        if (!$files) return null;
        usort($files, fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $url = $this->client->get('/api/v1/files/' . $files[0]['id'] . '/download')['data']['url'] ?? null;
        return (is_string($url) && preg_match('~^https://~i', $url)) ? $url : null;
    }

    /** /lists page: every family with its lists, member counts (from the list cache), review state. */
    public function mapView(): array
    {
        $this->discover();
        $all = $this->lists();
        $families = [];
        foreach ($this->map['families'] as $name => $f) {
            $lists = [];
            foreach ($f['lists'] as $l) {
                $id = (string) ($l['id'] ?? '');
                $inAtlas = $id !== '' && isset($all[$id]);
                $lists[] = ['id' => $id, 'name' => (string) ($l['name'] ?? ''), 'type' => (string) ($l['type'] ?? 'other'),
                    'count' => $inAtlas ? $all[$id]['memberCount'] : null, 'missing' => !$inAtlas || ($l['status'] ?? '') === 'missing',
                    'auto' => !empty($l['auto']), 'addedAt' => $l['addedAt'] ?? null];
            }
            $families[] = ['name' => (string) $name, 'lists' => $lists, 'keywords' => $f['keywords'], 'roleSubGroups' => $f['roleSubGroups'],
                'roleGroups' => $f['roleGroups'], 'auto' => !empty($f['auto']), 'reviewed' => !array_key_exists('reviewed', $f) || $f['reviewed'] === true];
        }
        $st = $this->state();
        return ['families' => $families, 'ignoredLists' => $this->map['ignoredLists'],
            'discoveredAt' => isset($st['discoveredAt']) ? gmdate('c', (int) $st['discoveredAt']) : null,
            'refreshedAt' => isset($st['refreshedAt']) ? gmdate('c', (int) $st['refreshedAt']) : null];
    }

    /** Save edits to one family's keywords / Role Sub-Groups (/lists page). Only the app's own storage is written. */
    public function saveFamily(string $family, mixed $keywords, mixed $roleSubGroups, string $user): array
    {
        if (!isset($this->map['families'][$family])) throw new NotFoundError('Unknown family');
        $clean = function (mixed $v, string $what): array {
            if (!is_array($v)) throw new ValidationError("$what must be a list");
            $out = [];
            foreach ($v as $s) {
                if (!is_string($s)) throw new ValidationError("$what must be text");
                $s = trim(preg_replace('~\s+~u', ' ', $s) ?? $s);
                if ($s === '') continue;
                if (strlen($s) > 100) throw new ValidationError("$what: \"" . substr($s, 0, 30) . "…\" is too long");
                if (!in_array($s, $out, true)) $out[] = $s;
            }
            if (count($out) > 60) throw new ValidationError("$what: at most 60 entries");
            return $out;
        };
        // lower-case first, then de-duplicate ("SAP Consultant" and "sap consultant" are the same keyword)
        $kw = array_values(array_unique(array_map(fn ($k) => function_exists('mb_strtolower') ? mb_strtolower($k, 'UTF-8') : strtolower($k), $clean($keywords, 'Keywords'))));
        $sg = $clean($roleSubGroups, 'Role Sub-Groups');
        if (!$this->mapFile) throw new AppError(500, 'NO_MAP_FILE', 'The list map file is not available');
        JsonStore::locked($this->mapFile . '.edit.lock', function () use ($family, $kw, $sg) {
            $fresh = ListMap::load(dirname($this->mapFile), '')['map'];
            if (isset($fresh['families'][$family])) $this->map = $fresh; // keep any auto-discovery changes made meanwhile
            $this->map['families'][$family]['keywords'] = $kw;
            $this->map['families'][$family]['roleSubGroups'] = $sg;
            $this->map['families'][$family]['reviewed'] = true;
            ListMap::save($this->mapFile, $this->map);
        });
        $this->cache->clear(); // matching changed: counts per family set may differ
        Audit::event('atlas_map_edit', ['user' => $user, 'family' => $family, 'keywords' => count($kw), 'roleSubGroups' => count($sg)]);
        return ['family' => $family, 'keywords' => $kw, 'roleSubGroups' => $sg, 'reviewed' => true];
    }

    public function health(): array
    {
        $rows = [];
        try {
            $this->client->get('/api/v1/users', ['page' => 1, 'pageSize' => 1]);
            $rows[] = ['Atlas API reachable', 'OK', 'GET /api/v1/users answered'];
        } catch (\Throwable $e) {
            $rows[] = ['Atlas API reachable', 'FAIL', $e->getMessage()];
            return $rows;
        }
        try {
            $missing = [];
            $found = 0;
            $unreviewed = 0;
            foreach ($this->map['families'] ?? [] as $fam => $f) {
                if (($f['reviewed'] ?? true) === false) $unreviewed++;
                foreach ($this->familyLists((string) $fam) as $l) $l['found'] ? $found++ : $missing[] = $l['name'];
            }
            $rows[] = ['Atlas lists in the map', $missing ? 'WARN' : 'OK', "$found found" . ($missing ? '; missing in Atlas or not visible to this key: ' . implode('; ', $missing) : '')];
            if ($unreviewed) $rows[] = ['Atlas families to review', 'WARN', "$unreviewed new family(ies) created automatically: review them on /lists"];
        } catch (\Throwable $e) {
            $rows[] = ['Atlas lists in the map', 'FAIL', $e->getMessage()];
        }
        return $rows;
    }
}

/** Fake Atlas API (same JSON shapes as the real one) for ATLAS_BACKEND=mock and the tests. All data is fictional. */
final class MockAtlas
{
    /** @var array<string,array{name:string,members:array}> */
    public array $lists = [];
    private array $people = [];
    public int $calls = 0;

    public static function uuid(string $seed): string
    {
        $h = md5($seed);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    public function __construct(array $map)
    {
        $map = ListMap::upgrade($map);
        $cities = ['Brussels, Belgium', 'Antwerp, Belgium', 'Ghent, Belgium', 'Leuven, Belgium', 'Liège, Belgium', 'Mechelen, Belgium'];
        $pool = [];
        for ($i = 1; $i <= 160; $i++) {
            $id = self::uuid("person-$i");
            [$city, $country] = explode(', ', $cities[$i % count($cities)]);
            $this->people[$id] = ['id' => $id, 'firstName' => 'Example', 'lastName' => sprintf('Person %03d', $i),
                'headline' => ['role' => '', 'company' => sprintf('Example Company %02d', $i % 37)],
                'address' => ['city' => $city, 'country' => $country, 'formattedAddress' => "$city, $country"],
                'updatedAt' => sprintf('2026-%02d-%02dT10:00:00Z', 1 + $i % 9, 1 + $i % 27), 'hasCv' => $i % 3 === 0];
            $pool[] = $id;
        }
        $n = 0;
        foreach ($map['families'] ?? [] as $fam => $f) {
            $n++;
            $size = 4 + ($n * 7) % 23;
            $start = ($n * 13) % 140;
            $perm = array_slice($pool, $start, $size);
            $free = array_merge(array_slice($perm, 0, 2), array_slice($pool, ($start + $size) % 150, 2 + $n % 4)); // 2 people on both lists
            foreach ($f['lists'] as $l) {
                $members = ($l['type'] ?? '') === 'freelance' ? $free : $perm;
                $name = (string) ($l['name'] ?? "$fam – " . ucfirst((string) ($l['type'] ?? 'other')));
                $lid = ($l['id'] ?? '') !== '' ? (string) $l['id'] : self::uuid("list-$name");
                $this->lists[$lid] = ['name' => $name, 'members' => $members];
                foreach ($members as $pid) if ($this->people[$pid]['headline']['role'] === '') $this->people[$pid]['headline']['role'] = preg_replace('/^Microsoft – /', '', (string) $fam);
            }
        }
        foreach ($map['ignoredLists'] ?? [] as $ig) {
            $this->lists[(string) ($ig['id'] ?: self::uuid('list-ignored'))] = ['name' => (string) $ig['name'], 'members' => array_slice($pool, 0, 5)];
        }
    }

    /** Test helper: a list created in Atlas. */
    public function addList(string $name, int $members = 3): string
    {
        $id = self::uuid("list-$name");
        $this->lists[$id] = ['name' => $name, 'members' => array_slice(array_keys($this->people), 150, $members)];
        return $id;
    }

    /** Transport for AtlasClient: fn(url, headers) -> [status, headers, body]. GET only (the client never sends anything else). */
    public function __invoke(string $url, array $headers): array
    {
        $this->calls++;
        $u = parse_url($url);
        $path = $u['path'] ?? '';
        parse_str($u['query'] ?? '', $q);
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = max(1, min(100, (int) ($q['pageSize'] ?? 25)));
        $paged = function (array $items) use ($page, $size): array {
            $total = count($items);
            return ['status' => 'success', 'data' => array_values(array_slice($items, ($page - 1) * $size, $size)),
                'pagination' => ['page' => $page, 'pageSize' => $size, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $size))]];
        };
        $ok = fn (array $body) => ['status' => 200, 'headers' => ['RateLimit-Limit' => '1200', 'RateLimit-Remaining' => '1100', 'RateLimit-Reset' => '30'], 'body' => json_encode($body)];
        $notFound = ['status' => 404, 'headers' => [], 'body' => '{"status":"error","error":"Not found"}'];

        if ($path === '/api/v1/lists') {
            $rows = [];
            foreach ($this->lists as $id => $l) $rows[] = ['id' => $id, 'name' => $l['name'], 'entityType' => 'candidate', 'visibility' => 'public', 'memberCount' => count($l['members'])];
            return $ok($paged($rows));
        }
        if (preg_match('~^/api/v1/lists/([^/]+)/members$~', $path, $m)) {
            if (!isset($this->lists[$m[1]])) return $notFound;
            $rows = [];
            foreach ($this->lists[$m[1]]['members'] as $i => $pid) {
                $p = $this->people[$pid];
                $rows[] = ['id' => self::uuid("member-{$m[1]}-$pid"), 'personId' => $pid, 'addedAt' => sprintf('2026-10-%02dT09:%02d:00Z', 1 + ($i * 3) % 19, $i % 60),
                    'person' => ['id' => $pid, 'firstName' => $p['firstName'], 'lastName' => $p['lastName']]];
            }
            return $ok($paged($rows));
        }
        if (preg_match('~^/api/v1/people/([^/]+)$~', $path, $m)) {
            if (!isset($this->people[$m[1]])) return $notFound;
            $p = $this->people[$m[1]];
            unset($p['hasCv']);
            return $ok(['status' => 'success', 'data' => $p]);
        }
        if ($path === '/api/v1/files') {
            $p = $this->people[(string) ($q['personId'] ?? '')] ?? null;
            $files = ($p && $this->people[$p['id']]['hasCv']) ? [['id' => self::uuid('cv-' . $p['id']), 'type' => 'resume', 'filename' => 'cv.pdf', 'created_at' => '2026-09-01T00:00:00Z']] : [];
            return $ok($paged($files));
        }
        if (preg_match('~^/api/v1/files/([^/]+)/download$~', $path, $m)) {
            return $ok(['status' => 'success', 'data' => ['url' => 'https://files.example.test/cv/' . $m[1] . '.pdf?signature=mock', 'expires_at' => '2026-10-20T12:05:00Z']]);
        }
        if ($path === '/api/v1/users') return $ok($paged([['id' => self::uuid('user-1'), 'name' => 'Mock User']]));
        return $notFound;
    }
}

/** Builds the configured CandidateSource, or null when Atlas is switched off / not configured. */
final class Atlas
{
    public const DEFAULT_BASE_URL = 'https://api.recruitwithatlas.com';
    public const DEFAULT_PROFILE_URL = 'https://my.recruitwithatlas.com/people-v2?profile={id}';
    public const CACHE_TTL = 6 * 3600;

    public static function mode(array $c): string
    {
        $b = strtolower((string) ($c['ATLAS_BACKEND'] ?? ''));
        if ($b === 'mock') return 'mock';
        if ($b === 'off') return 'off';
        return ($c['ATLAS_API_KEY'] ?? '') !== '' ? 'atlas' : 'off';
    }

    public static function source(array $c, string $privateDir): ?AtlasListsSource
    {
        $mode = self::mode($c);
        if ($mode === 'off') return null;
        $loaded = ListMap::load($c['STORAGE_DIR'], $privateDir . '/atlas-list-map.default.json');
        $storageMap = $c['STORAGE_DIR'] . '/atlas-list-map.json';
        $client = new AtlasClient($mode === 'mock' ? 'https://mock.atlas.test' : (($c['ATLAS_BASE_URL'] ?? '') ?: self::DEFAULT_BASE_URL), $mode === 'mock' ? 'mock' : (string) $c['ATLAS_API_KEY']);
        if ($mode === 'mock') {
            static $mock = null; // one fake Atlas per PHP request
            $mock ??= new MockAtlas(json_decode((string) @file_get_contents($privateDir . '/atlas-list-map.default.json'), true) ?: $loaded['map']);
            $client->transport = $mock;
        }
        return new AtlasListsSource($client, $loaded['map'], new TtlFileCache($c['STORAGE_DIR'] . '/atlas-cache', self::CACHE_TTL),
            ($c['ATLAS_PROFILE_URL'] ?? '') ?: self::DEFAULT_PROFILE_URL,
            $loaded['error'] === null && is_file($storageMap) ? $storageMap : null, $c['STORAGE_DIR'] . '/atlas-state.json');
    }
}
