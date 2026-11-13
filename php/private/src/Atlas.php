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
        '/api/v1/people/search' => '#^/api/v1/people/search$#', // exact e-mail / phone / LinkedIn URL lookup only
        '/api/v1/files' => '#^/api/v1/files$#',
        '/api/v1/files/{id}/download' => '#^/api/v1/files/' . self::UUID . '/download$#',
        '/api/v1/users' => '#^/api/v1/users$#',
        // Outreach "From reach to business" (approved 2026-11-13): new jobs, deals, new clients. GET only.
        '/api/v1/projects' => '#^/api/v1/projects$#',
        '/api/v1/opportunities' => '#^/api/v1/opportunities$#',
        '/api/v1/opportunities/{id}' => '#^/api/v1/opportunities/' . self::UUID . '$#',
        '/api/v1/placements' => '#^/api/v1/placements$#',
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
    /** "pool" = one list holding every candidate of a family (no Permanent/Freelance split); the others still work. */
    public const LIST_TYPES = ['pool', 'permanent', 'freelance', 'contract', 'interim', 'temporary', 'fixed-term'];

    /** @return array{map:array,file:string,error:?string,migrated?:array} */
    public static function load(string $storageDir, string $defaultFile): array
    {
        $file = $storageDir . '/atlas-list-map.json';
        if (!is_file($file) && is_file($defaultFile)) @copy($defaultFile, $file);
        $error = null;
        $migrated = [];
        $default = is_file($defaultFile) ? json_decode((string) file_get_contents($defaultFile), true) : null;
        $map = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($map)) {
            $error = is_file($file) ? 'atlas-list-map.json is not valid JSON; using the default map' : null;
            $map = self::upgrade(is_array($default) ? $default : ['families' => []]);
        } else {
            $map = self::upgrade($map);
            if (is_array($default) && ($default['mapRevision'] ?? '') !== '' && ($map['mapRevision'] ?? '') !== $default['mapRevision']) {
                $r = self::migrate($map, self::upgrade($default));
                @copy($file, preg_replace('/\.json$/', '', $file) . '.before-' . $default['mapRevision'] . '.json');
                $map = $r['map'];
                $migrated = $r['changes'];
                self::save($file, $map);
                Audit::event('atlas_map_migrated', ['revision' => $default['mapRevision'], 'changes' => count($migrated)]);
            }
            if ($e = self::validate($map)) {
                $error = "atlas-list-map.json: $e; using the default map";
                $map = self::upgrade(is_array($default) ? $default : ['families' => []]);
            }
        }
        return ['map' => $map, 'file' => is_file($file) ? $file : $defaultFile, 'error' => $error, 'migrated' => $migrated];
    }

    /**
     * Move a stored map to a new default revision (e.g. 46 Permanent/Freelance lists -> 6 pools). The new default
     * wins; keywords and Role Sub-Groups the user ADDED to an old family (not known anywhere in the new default)
     * move to that family's pool (retiredFamilies); new families found in Atlas meanwhile are kept;
     * the old lists become retiredLists (never "missing", never re-created as new families).
     * @return array{map:array,changes:array<int,string>}
     */
    public static function migrate(array $old, array $default): array
    {
        $map = $default;
        $changes = [];
        $known = [];
        foreach ($default['families'] as $f) foreach (['keywords', 'roleSubGroups', 'roleGroups'] as $k) foreach ($f[$k] as $v) $known[$k][self::familyKey((string) $v)] = true;
        $retired = [];
        foreach ($map['retiredLists'] as $l) $retired[(string) ($l['id'] ?? '')] = true;
        $poolIds = [];
        foreach ($default['families'] as $f) foreach ($f['lists'] as $l) $poolIds[(string) ($l['id'] ?? '')] = true;
        $retiredFamilies = is_array($default['retiredFamilies'] ?? null) ? $default['retiredFamilies'] : [];
        foreach ($old['families'] as $name => $f) {
            $name = (string) $name;
            $target = isset($map['families'][$name]) ? $name : ($retiredFamilies[$name] ?? null);
            $live = array_filter($f['lists'], fn ($l) => !isset($retired[(string) ($l['id'] ?? '')]) && !isset($poolIds[(string) ($l['id'] ?? '')]));
            if ($target === null || !isset($map['families'][$target])) {
                if ($live) {
                    $map['families'][$name] = $f; // a family found in Atlas after the default was written: keep it
                    $changes[] = "family \"$name\" kept";
                }
                continue;
            }
            foreach (['keywords', 'roleSubGroups', 'roleGroups'] as $k) {
                foreach ($f[$k] as $v) {
                    if (!is_string($v) || isset($known[$k][self::familyKey($v)])) continue;
                    $map['families'][$target][$k][] = $v;
                    $known[$k][self::familyKey($v)] = true;
                    $changes[] = "your " . ($k === 'keywords' ? 'keyword' : ($k === 'roleSubGroups' ? 'Role Sub-Group' : 'Role Group')) . " \"$v\" moved from \"$name\" to \"$target\"";
                }
            }
            foreach ($live as $l) { // a list added to an old family after the default was written: keep it under the pool
                $map['families'][$target]['lists'][] = $l;
                $changes[] = "list \"{$l['name']}\" moved to \"$target\"";
            }
            foreach ($f['lists'] as $l) {
                $id = (string) ($l['id'] ?? '');
                if ($id !== '' && isset($retired[$id]) === false && !in_array($l, $live, true)) {
                    $map['retiredLists'][] = ['id' => $id, 'name' => (string) ($l['name'] ?? '')];
                    $retired[$id] = true;
                }
            }
        }
        $map['ignoredLists'] = array_values(array_unique(array_merge($default['ignoredLists'], $old['ignoredLists']), SORT_REGULAR));
        return ['map' => $map, 'changes' => $changes];
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
        $m['retiredLists'] = is_array($m['retiredLists'] ?? null) ? $m['retiredLists'] : [];
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

    /**
     * "Security Architects – Permanent" -> ["Security Architects", "permanent"]; "Candidates – Data & AI" -> ["Data & AI", "pool"]
     * (the "Candidates –" prefix is dropped); no known suffix -> [name, "pool"].
     */
    public static function parseListName(string $name): array
    {
        $name = preg_replace('~^\s*candidates\s*[–—-]\s*~iu', '', $name) ?? $name;
        $re = '~^(.*\S)\s*[–—-]\s*(permanent|perm|freelancers?|freelance|contractors?|contract|interim|temporary|temp|fixed[- ]term)\s*$~iu';
        if (!preg_match($re, trim($name), $m)) return [trim($name), 'pool'];
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
        foreach (array_merge($map['ignoredLists'], $map['retiredLists'] ?? []) as $ig) if (!empty($ig['id'])) $known[(string) $ig['id']] = true;

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

    /** Badge numbers: pool size (m) and matching titles in the pool (n, null while the title index is not built). */
    public function summary(string $title, string $subGroup = '', string $roleGroup = '', bool $byListName = false): array;

    /** Matched pools + numbers + one page of people (names and details fetched live). */
    public function search(string $title, string $subGroup, string $roleGroup, int $page, bool $all = false): array;

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
    public const INDEX_WARN_AGE = 36 * 3600;

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
        private ?string $storageDir = null,
    ) {
        $this->now = fn () => time();
    }

    public function map(): array
    {
        return $this->map;
    }

    public function client(): AtlasClient
    {
        return $this->client;
    }

    public function profileUrl(string $personId): string
    {
        return str_replace('{id}', rawurlencode($personId), $this->profileUrlTemplate);
    }

    public function match(string $title, string $subGroup = '', string $roleGroup = ''): array
    {
        $this->discover();
        return ListMap::match($this->map, $title, $subGroup, $roleGroup);
    }

    /** The title index builder for this source's storage (null in tests that don't use storage). */
    public function indexBuilder(): ?IndexBuilder
    {
        return $this->storageDir ? new IndexBuilder($this->client, $this->map, $this->storageDir, $this->dictionary()) : null;
    }

    /** The JD dictionary (storage/jd-dictionary.json): skills vocabulary of the index, rule-based JD parsing. */
    public function dictionary(): array
    {
        return $this->storageDir ? JdDictionary::load($this->storageDir)['dict'] : JdDictionary::default();
    }

    public function index(): ?TitleIndex
    {
        return $this->storageDir ? TitleIndex::load($this->storageDir . '/atlas-title-index.json') : null;
    }

    /** "ready" | "building" | "missing" */
    private function indexState(): string
    {
        if ($this->index()) return 'ready';
        $st = $this->storageDir ? (JsonStore::read($this->storageDir . '/atlas-index-build.json', []) ?: []) : [];
        return ($st['status'] ?? '') === 'running' ? 'building' : 'missing';
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
            $out[] = ['type' => (string) ($ref['type'] ?? 'pool'), 'id' => $id, 'name' => $id !== '' ? $all[$id]['name'] : (string) ($ref['name'] ?? ''),
                'memberCount' => $id !== '' ? $all[$id]['memberCount'] : 0, 'found' => $id !== ''];
        }
        return $out;
    }

    /** Member ids of a list, newest first (transient: never stored). @return array<int,array{0:string,1:string}> */
    private function memberIds(string $listId): array
    {
        $out = [];
        for ($page = 1; $page <= 200; $page++) {
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

    private static function listsView(array $lists): array
    {
        return array_map(fn ($l) => ['family' => $l['family'], 'type' => $l['type'], 'name' => $l['name'], 'count' => $l['memberCount'], 'found' => $l['found']], $lists);
    }

    private static function countsKey(array $families): string
    {
        $f = array_values(array_unique($families));
        sort($f);
        return 'counts:' . implode('|', $f);
    }

    /**
     * Pool size (people on the families' lists, each once) and per-type counts. One list -> its member count;
     * several -> deduplicated union of members. Cached 6 h: numbers and list names only.
     */
    public function counts(array $families): array
    {
        if (!$families) return ['n' => 0, 'types' => [], 'lists' => []];
        $this->discover();
        $key = self::countsKey($families);
        $cached = $this->cache->get($key);
        if (is_array($cached)) return $cached;
        $lists = [];
        foreach ($families as $fam) foreach ($this->familyLists($fam) as $l) $lists[] = $l + ['family' => $fam];
        $found = array_values(array_filter($lists, fn ($l) => $l['found']));
        if (count($found) === 1) {
            $counts = ['n' => $found[0]['memberCount'], 'types' => [$found[0]['type'] => $found[0]['memberCount']], 'lists' => self::listsView($lists)];
        } else {
            $members = $this->union($families, $lists);
            $types = [];
            foreach ($members as $m) foreach (array_keys($m['types']) as $t) $types[$t] = ($types[$t] ?? 0) + 1;
            $counts = ['n' => count($members), 'types' => $types, 'lists' => self::listsView($lists)];
        }
        $this->cache->set($key, $counts);
        return $counts;
    }

    private function foundListIds(array $families): array
    {
        $ids = [];
        foreach ($families as $fam) foreach ($this->familyLists($fam) as $l) if ($l['found']) $ids[] = $l['id'];
        return array_values(array_unique($ids));
    }

    /** Title words to look for in the pool: the family keywords found in the title, else the whole title. */
    public static function searchTerms(array $map, string $normTitle, array $families): array
    {
        if ($normTitle === '') return [];
        $terms = [];
        foreach ($families as $fam) {
            foreach ($map['families'][$fam]['keywords'] ?? [] as $k) {
                $k = ListMap::normaliseTitle((string) $k, [], false);
                if ($k !== '' && preg_match('~(?<![\p{L}\p{N}])' . preg_quote($k, '~') . '(?![\p{L}\p{N}])~u', $normTitle)) $terms[] = $k;
            }
        }
        return $terms ? array_values(array_unique($terms)) : [$normTitle];
    }

    /**
     * Badge / header numbers: m = people in the matched pool(s), n = of those, how many have a matching current title
     * (from the title index; null while it is not built yet).
     */
    public function summary(string $title, string $subGroup = '', string $roleGroup = '', bool $byListName = false): array
    {
        $this->discover();
        $m = ListMap::match($this->map, $title, $subGroup, $roleGroup, $byListName);
        $base = ['families' => $m['families'], 'reason' => $m['reason'], 'normalisedTitle' => $m['normalisedTitle']];
        if (!$m['families']) return $base + ['none' => true, 'terms' => [], 'm' => 0, 'n' => 0, 'index' => $this->indexState(), 'lists' => []];
        $terms = self::searchTerms($this->map, $m['normalisedTitle'], $m['families']);
        $idx = $this->index();
        if ($idx) {
            $ids = $this->foundListIds($m['families']);
            $pool = $idx->inLists($ids);
            $lists = [];
            foreach ($m['families'] as $fam) foreach ($this->familyLists($fam) as $l) $lists[] = $l + ['family' => $fam];
            return $base + ['terms' => $terms, 'm' => count($pool), 'n' => $m['normalisedTitle'] === '' ? null : count(TitleIndex::matching($pool, $terms)),
                'index' => 'ready', 'indexBuiltAt' => gmdate('c', $idx->builtAt), 'lists' => self::listsView($lists)];
        }
        $c = $this->counts($m['families']);
        return $base + ['terms' => $terms, 'm' => $c['n'], 'n' => null, 'index' => $this->indexState(), 'lists' => $c['lists']];
    }

    /**
     * "Already in Atlas": which of these LinkedIn profile URLs exist in Atlas (one read-only GET /people/search with the
     * exact URLs). @param string[] $urls like "linkedin.com/in/jane-doe" @return array<string,string> slug => Atlas person id
     */
    public function findByLinkedIn(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (!$urls) return [];
        $out = [];
        foreach (array_chunk($urls, 25) as $chunk) {
            try {
                $r = $this->client->get('/api/v1/people/search', ['linkedinUrls' => implode(',', $chunk)]);
            } catch (NotFoundError) {
                continue;
            }
            foreach ($r['data'] ?? [] as $p) {
                foreach ($p['identities'] ?? [] as $idn) {
                    if (preg_match('~linkedin\.com/in/([^/?#]+)~i', (string) ($idn['value'] ?? ''), $m) && AtlasGuard::isUuid((string) ($p['id'] ?? ''))) {
                        $out[Phrases::lower(rawurldecode($m[1]))] = (string) $p['id'];
                    }
                }
            }
        }
        return $out;
    }

    /** One person's card, fetched live from Atlas (never stored). */
    public function personRow(string $pid, array $extra = []): array
    {
        $row = ['id' => $pid, 'profileUrl' => $this->profileUrl($pid)] + $extra;
        try {
            $p = $this->client->get("/api/v1/people/$pid")['data'] ?? [];
            $addr = $p['address'] ?? [];
            $loc = trim(implode(', ', array_filter([$addr['city'] ?? null, $addr['country'] ?? null])));
            return $row + [
                'name' => trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? '')) ?: '(no name)',
                'role' => (string) ($p['headline']['role'] ?? ''), 'company' => (string) ($p['headline']['company'] ?? ''),
                'location' => $loc !== '' ? $loc : (string) ($addr['formattedAddress'] ?? ''),
                'updatedAt' => substr((string) ($p['updatedAt'] ?? ''), 0, 10),
            ];
        } catch (NotFoundError) {
            return $row + ['name' => '(no longer in Atlas)', 'role' => '', 'company' => '', 'location' => '', 'updatedAt' => ''];
        }
    }

    /**
     * Candidates page. With the title index: people in the pool whose current title matches (or the whole pool with
     * $all), newest first. Without it: the whole pool from the lists, with "title index building…".
     */
    public function search(string $title, string $subGroup, string $roleGroup, int $page, bool $all = false): array
    {
        $s = $this->summary($title, $subGroup, $roleGroup, true);
        if (!empty($s['none'])) return $s + ['members' => [], 'page' => 1, 'totalPages' => 0, 'showing' => 'none'];
        $idx = $this->index();
        if ($idx) {
            $pool = $idx->inLists($this->foundListIds($s['families']));
            $showing = ($all || $s['n'] === null) ? 'pool' : 'matching';
            $entries = $showing === 'pool' ? TitleIndex::newestFirst($pool) : TitleIndex::matching($pool, $s['terms']);
            $ids = array_map(fn ($e) => [(string) $e['personId'], ['hasCv' => (bool) ($e['hasCv'] ?? false), 'skills' => $e['skills'] ?? []]], $entries);
        } else {
            $lists = [];
            $members = $this->union($s['families'], $lists);
            uasort($members, fn ($a, $b) => strcmp($b['addedAt'], $a['addedAt']));
            $ids = array_map(fn ($pid) => [(string) $pid, []], array_keys($members));
            $showing = 'pool';
        }
        $totalPages = max(1, (int) ceil(count($ids) / self::PAGE_SIZE));
        $page = max(1, min($page, $totalPages));
        $rows = [];
        foreach (array_slice($ids, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE) as [$pid, $extra]) $rows[] = $this->personRow($pid, $extra);
        return $s + ['members' => $rows, 'page' => $page, 'totalPages' => $totalPages, 'showing' => $showing];
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

    /** /lists page: every family with its lists, member counts (from the list cache), review state, index status. */
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
                $lists[] = ['id' => $id, 'name' => (string) ($l['name'] ?? ''), 'type' => (string) ($l['type'] ?? 'pool'),
                    'count' => $inAtlas ? $all[$id]['memberCount'] : null, 'missing' => !$inAtlas || ($l['status'] ?? '') === 'missing',
                    'auto' => !empty($l['auto']), 'addedAt' => $l['addedAt'] ?? null];
            }
            $families[] = ['name' => (string) $name, 'lists' => $lists, 'keywords' => $f['keywords'], 'roleSubGroups' => $f['roleSubGroups'],
                'roleGroups' => $f['roleGroups'], 'auto' => !empty($f['auto']), 'reviewed' => !array_key_exists('reviewed', $f) || $f['reviewed'] === true];
        }
        $st = $this->state();
        $b = $this->indexBuilder();
        return ['families' => $families, 'ignoredLists' => $this->map['ignoredLists'],
            'retiredLists' => array_map(fn ($l) => ['name' => (string) ($l['name'] ?? '')], $this->map['retiredLists'] ?? []),
            'discoveredAt' => isset($st['discoveredAt']) ? gmdate('c', (int) $st['discoveredAt']) : null,
            'refreshedAt' => isset($st['refreshedAt']) ? gmdate('c', (int) $st['refreshedAt']) : null,
            'index' => $b ? $b->status() : null];
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
            if (count($out) > 100) throw new ValidationError("$what: at most 100 entries");
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
            $found = [];
            $unreviewed = 0;
            foreach ($this->map['families'] ?? [] as $fam => $f) {
                if (($f['reviewed'] ?? true) === false) $unreviewed++;
                foreach ($this->familyLists((string) $fam) as $l) $l['found'] ? $found[] = $l['name'] : $missing[] = $l['name'];
            }
            $pools = count($found);
            $rows[] = ['Atlas pools found', $missing ? 'WARN' : 'OK', "$pools found" . ($missing ? '; missing in Atlas or not visible to this key: ' . implode('; ', $missing) : '')];
            if ($unreviewed) $rows[] = ['Atlas families to review', 'WARN', "$unreviewed new family(ies) created automatically: review them on /lists"];
        } catch (\Throwable $e) {
            $rows[] = ['Atlas pools found', 'FAIL', $e->getMessage()];
        }
        if ($this->storageDir) {
            $idx = $this->index();
            $b = $this->indexBuilder()->status();
            if (!$idx) {
                $rows[] = ['Atlas title index', 'WARN', $b['state'] === 'running' ? "building: {$b['done']} of {$b['total']} people" : 'not built yet: run private/bin/atlas-index.php (cron) or "Rebuild index" on /lists'];
            } else {
                $age = ($this->now)() - $idx->builtAt;
                $rows[] = ['Atlas title index age', $age > self::INDEX_WARN_AGE ? 'WARN' : 'OK', 'built ' . gmdate('Y-m-d H:i', $idx->builtAt) . ' UTC (' . round($age / 3600, 1) . ' h ago)' . ($age > self::INDEX_WARN_AGE ? ': older than 36 h, check the cron job' : '')];
                $rows[] = ['Atlas title index entries', count($idx->entries) ? 'OK' : 'WARN', count($idx->entries) . ' people in ' . count($idx->pools) . ' pool(s)'];
                $first = $idx->entries[0] ?? [];
                $missingFields = array_values(array_diff(TitleIndex::FIELDS, array_keys($first)));
                $rows[] = ['Atlas title index fields', !$first || $missingFields ? 'WARN' : 'OK', !$first ? 'no entries' : ($missingFields ? 'missing: ' . implode(', ', $missingFields) . ' (rebuild the index)' : implode(', ', TitleIndex::FIELDS))];
            }
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
    public array $searches = [];

    public static function uuid(string $seed): string
    {
        $h = md5($seed);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    /** Fictional current titles and skills per pool (by family name). */
    private const TITLES = [
        'Cyber Security' => ['Senior Security Architect', 'SOC Analyst', 'Penetration Tester', 'IAM Engineer', 'GRC Consultant', 'CISO', 'Security Engineer', 'Cyber Security Consultant'],
        'Infrastructure & Cloud' => ['Cloud Engineer', 'Network Engineer', 'DevOps Engineer', 'Systems Administrator', 'Azure Cloud Architect'],
        'Defence & Public Sector' => ['Cyber Range Engineer', 'Defence Analyst', 'Research Scientist', 'Public Sector IT Consultant'],
        'Software & Architecture' => ['Software Architect', '.NET Developer', 'Full Stack Developer', 'Solution Architect', 'Java Developer'],
        'Leadership & Management' => ['CTO', 'IT Manager', 'Head of IT', 'Engineering Manager'],
        'Data & AI' => ['Data Engineer', 'ML Engineer', 'Data Architect', 'Data Scientist', 'GenAI Engineer'],
    ];
    private const SKILLS = [
        'Cyber Security' => 'Sentinel, Splunk, ISO 27001, NIS2, CISSP, penetration testing, CyberArk, Okta, incident response',
        'Infrastructure & Cloud' => 'Azure, AWS, Kubernetes, Terraform, Cisco, VMware, Linux, M365',
        'Defence & Public Sector' => 'NATO, security clearance, cyber range exercises, Python, risk management',
        'Software & Architecture' => 'C#, .NET, Java, React, TypeScript, TOGAF, Azure, Docker',
        'Leadership & Management' => 'ITIL, Prince2, budget ownership, agile, vendor management',
        'Data & AI' => 'Databricks, Python, Spark, Power BI, machine learning, LLM, PyTorch, Snowflake',
    ];

    public function __construct(array $map)
    {
        $map = ListMap::upgrade($map);
        $cities = ['Brussels, Belgium', 'Antwerp, Belgium', 'Ghent, Belgium', 'Leuven, Belgium', 'Liège, Belgium', 'Mechelen, Belgium', 'Rotterdam, Netherlands', 'Lille, France'];
        $pool = [];
        for ($i = 1; $i <= 160; $i++) {
            $id = self::uuid("person-$i");
            [$city, $country] = explode(', ', $cities[$i % count($cities)]);
            $this->people[$id] = ['id' => $id, 'firstName' => 'Example', 'lastName' => sprintf('Person %03d', $i),
                'identities' => [['type' => 'email', 'value' => sprintf('example.person%03d@mail.example', $i), 'isPersonal' => true, 'isPrimary' => true],
                    ['type' => 'linkedin', 'value' => sprintf('https://www.linkedin.com/in/example-person-%03d', $i), 'isPersonal' => false, 'isPrimary' => false],
                    ['type' => 'phone', 'value' => sprintf('+32 470 00 %02d %02d', intdiv($i, 100), $i % 100), 'isPersonal' => true, 'isPrimary' => false]],
                'headline' => ['role' => '', 'company' => sprintf('Example Company %02d', $i % 37)],
                'address' => ['city' => $city, 'country' => $country, 'formattedAddress' => "$city, $country"],
                'experience' => [], 'education' => [['institutionName' => 'Example University', 'degree' => 'MSc', 'fieldOfStudy' => 'Computer Science']],
                'compensation' => [['type' => 'expected', 'expectedSalaryMin' => 60000 + $i * 100]],
                'customAttributes' => [['attributeId' => 'a1', 'attributeName' => 'Private notes', 'attributeType' => 'text', 'values' => ["Secret note about person $i"]]],
                'updatedAt' => sprintf('2026-%02d-%02dT10:00:00Z', 1 + $i % 9, 1 + $i % 27), 'hasCv' => $i % 3 === 0];
            $pool[] = $id;
        }
        $n = 0;
        foreach ($map['families'] ?? [] as $fam => $f) {
            $n++;
            $titles = self::TITLES[$fam] ?? [preg_replace('/^Microsoft – /', '', (string) $fam)];
            $skills = self::SKILLS[$fam] ?? 'Python, SQL';
            $size = $n === 1 ? 55 : 15 + ($n * 7) % 20; // Cyber Security is by far the largest pool, as in Atlas
            $start = $n === 1 ? 0 : ($n * 23) % 120;
            $members = array_slice($pool, $start, $size);
            foreach ($f['lists'] as $li => $l) {
                $listMembers = $li === 0 ? $members : array_merge(array_slice($members, 0, 2), array_slice($pool, ($start + $size) % 150, 2 + $n % 4));
                $name = (string) ($l['name'] ?? "$fam – " . ucfirst((string) ($l['type'] ?? 'pool')));
                $lid = ($l['id'] ?? '') !== '' ? (string) $l['id'] : self::uuid("list-$name");
                $this->lists[$lid] = ['name' => $name, 'members' => $listMembers];
                foreach ($listMembers as $k => $pid) {
                    if ($this->people[$pid]['headline']['role'] !== '') continue;
                    $title = $titles[$k % count($titles)];
                    $this->people[$pid]['headline']['role'] = $title;
                    $this->people[$pid]['experience'] = [
                        ['companyName' => $this->people[$pid]['headline']['company'], 'role' => $title, 'description' => "Hands-on with $skills. Contact me on example.person@mail.example", 'startDate' => '2022-01-01', 'endDate' => null],
                        ['companyName' => 'Earlier Example BV', 'role' => 'IT Consultant', 'description' => 'Linux and SQL support', 'startDate' => '2018-03-01', 'endDate' => '2021-12-31'],
                    ];
                }
            }
        }
        foreach ($map['retiredLists'] ?? [] as $k => $rl) { // the old Permanent/Freelance lists may still exist in Atlas
            if (($rl['id'] ?? '') !== '' && $k % 2 === 0) $this->lists[(string) $rl['id']] = ['name' => (string) $rl['name'], 'members' => array_slice($pool, $k, 3)];
        }
        foreach ($map['ignoredLists'] ?? [] as $ig) {
            $this->lists[(string) ($ig['id'] ?: self::uuid('list-ignored'))] = ['name' => (string) $ig['name'], 'members' => array_slice($pool, 0, 5)];
        }
    }

    /** Mock CV text for the fake download links (ATLAS_BACKEND=mock): fictional, no contact details. */
    public static function cvText(string $url): string
    {
        $n = hexdec(substr(md5($url), 0, 4)) % 3;
        $skills = ['Azure, Kubernetes, Terraform, ISO 27001, Sentinel, Splunk', 'Python, Databricks, Spark, Power BI, machine learning', 'C#, .NET, Java, React, TOGAF, Docker'][$n];
        return "Curriculum vitae (example)\n\nProfile\nExperienced IT professional based in Belgium with eight years of hands-on project work for large organisations.\n\n"
            . "Skills\n$skills, agile delivery, stakeholder management.\n\nLanguages\nDutch (native), French (fluent), English (fluent).\n\n"
            . "Experience\n2022 - present: lead role in a security and cloud programme.\n2018 - 2021: consultant on infrastructure and data projects.\n";
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
        if ($path === '/api/v1/people/search') {
            $want = array_filter(array_map(fn ($u) => preg_match('~linkedin\.com/in/([^/?#]+)~i', $u, $mm) ? strtolower($mm[1]) : null, explode(',', (string) ($q['linkedinUrls'] ?? ''))));
            $rows = [];
            foreach ($this->people as $p) {
                foreach ($p['identities'] as $idn) {
                    if ($idn['type'] === 'linkedin' && preg_match('~/in/([^/?#]+)~', $idn['value'], $mm) && in_array(strtolower($mm[1]), $want, true)) {
                        $rows[] = ['id' => $p['id'], 'firstName' => $p['firstName'], 'lastName' => $p['lastName'], 'headlineRole' => $p['headline']['role'], 'headlineCompanyName' => $p['headline']['company'],
                            'identities' => [['identityId' => 'i-' . $p['id'], 'type' => 'linkedin', 'value' => $idn['value'], 'active' => true, 'inactiveReason' => null]]];
                    }
                }
            }
            $this->searches[] = $q['linkedinUrls'] ?? '';
            return $ok(['status' => 'success', 'data' => $rows]);
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
            $loaded['error'] === null && is_file($storageMap) ? $storageMap : null, $c['STORAGE_DIR'] . '/atlas-state.json', $c['STORAGE_DIR']);
    }
}
