<?php
// "In the market": Prospeo people search next to the Atlas results (https://prospeo.io/api-docs).
//
//   ProspeoGuard   allowlist: POST /search-suggestions, POST /search-person, GET /account-information, POST /enrich-person.
//                  /enrich-person only while a confirmed "Reveal contact" request is being handled.
//   ProspeoClient  X-KEY header, 15 s timeout, 429 handling, audit log: endpoint, status, credits (never names or contacts)
//   CreditLedger   one daily credit cap for the whole app (PROSPEO_DAILY_CREDIT_CAP, default 200), checked BEFORE each call
//   MarketSearch   requirements -> editable filters -> search (25 per page, 1 credit) -> cards with a rule-based fit score
//                  ("title & company only") and "Already in Atlas" (Atlas GET /people/search by exact LinkedIn URL)
//   MockProspeo    fake API for PROSPEO_BACKEND=mock and the tests
// Nothing Prospeo returns about people is written to disk: only {searchHash -> person ids} for 24 h and credit counts.
declare(strict_types=1);

namespace SignalDesk;

final class ProspeoGuard
{
    public const ALLOWED = ['POST /search-suggestions', 'POST /search-person', 'GET /account-information', 'POST /enrich-person'];

    public static function assert(string $method, string $path, bool $revealing): void
    {
        $op = strtoupper($method) . ' ' . $path;
        if (!in_array($op, self::ALLOWED, true)) throw new ForbiddenWriteError("Refused: $op is not on the Prospeo allowlist");
        if ($path === '/enrich-person' && !$revealing) throw new ForbiddenWriteError('Refused: enrich-person is only called from a confirmed "Reveal contact"');
    }
}

final class ProspeoClient
{
    public const BASE = 'https://api.prospeo.io';
    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{status:int,headers:array,body:string} */
    public $transport = null;
    /** @var callable fn(int $ms) */
    public $sleep;
    private bool $revealing = false;

    public function __construct(private string $apiKey)
    {
        $this->sleep = fn (int $ms) => usleep($ms * 1000);
    }

    /** Run $fn with /enrich-person allowed (used only by MarketSearch::reveal after the user confirmed). */
    public function whileRevealing(callable $fn): mixed
    {
        $this->revealing = true;
        try {
            return $fn();
        } finally {
            $this->revealing = false;
        }
    }

    /** @return array decoded body; Prospeo "error" answers are thrown as AppErrors except NO_RESULTS / NO_MATCH (returned). */
    public function call(string $method, string $path, ?array $body = null, int $credits = 0): array
    {
        ProspeoGuard::assert($method, $path, $this->revealing);
        $headers = ['X-KEY: ' . $this->apiKey, 'Content-Type: application/json', 'Accept: application/json'];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $t0 = microtime(true);
            $res = $this->transport
                ? ($this->transport)($method, self::BASE . $path, $headers, $body === null ? null : json_encode($body))
                : $this->curl($method, self::BASE . $path, $headers, $body === null ? null : json_encode($body));
            $s = (int) $res['status'];
            $h = array_change_key_case($res['headers'] ?? [], CASE_LOWER);
            $d = json_decode((string) $res['body'], true);
            $code = is_array($d) ? (string) ($d['error_code'] ?? '') : '';
            Audit::event('prospeo_call', ['method' => $method, 'endpoint' => $path, 'status' => $s, 'code' => $code ?: null, 'ms' => (int) round((microtime(true) - $t0) * 1000)]);
            if ($s === 429) {
                $wait = (int) ($h['x-minute-reset-seconds'] ?? $h['retry-after'] ?? 2);
                if ($attempt === 1 && $wait <= 5) { ($this->sleep)(max(1, $wait) * 1000); continue; }
                throw new AppError(503, 'PROSPEO_BUSY', 'Prospeo rate limit reached. Try again in a minute.');
            }
            if ($s === 0) throw new UpstreamError('Could not reach Prospeo');
            if (!is_array($d)) throw new UpstreamError("Prospeo returned an unexpected response ($s)");
            if (empty($d['error']) && $s < 300) return $d;
            return match ($code) {
                'NO_RESULTS', 'NO_MATCH' => $d,
                'INVALID_API_KEY' => throw new UpstreamError('Prospeo refused the API key (check PROSPEO_API_KEY in config.php)'),
                'INSUFFICIENT_CREDITS' => throw new AppError(409, 'PROSPEO_NO_CREDITS', 'Your Prospeo account has no credits left.'),
                'INVALID_FILTERS' => throw new ValidationError('Prospeo refused the filters: ' . (string) ($d['filter_error'] ?? 'check them')),
                'PLAN_REQUIRED' => throw new ValidationError('That filter needs a higher Prospeo plan.'),
                'SERVICE_TEMPORARILY_UNAVAILABLE', 'INTERNAL_ERROR' => throw new AppError(503, 'PROSPEO_BUSY', 'Prospeo is temporarily unavailable. Try again shortly.'),
                default => throw new UpstreamError('Prospeo error ' . ($code ?: $s)),
            };
        }
        throw new UpstreamError('Prospeo did not answer');
    }

    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            }];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = (string) $body;
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = $out === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $out === false ? '' : (string) $out];
    }
}

/** One daily credit cap for the whole app, checked before every paid call (storage/prospeo-credits.json). */
final class CreditLedger
{
    /** @var callable(): int */
    public $now;

    public function __construct(private string $file, private int $cap)
    {
        $this->now = fn () => time();
    }

    private function day(): string
    {
        return gmdate('Y-m-d', ($this->now)());
    }

    public function cap(): int
    {
        return $this->cap;
    }

    public function used(): int
    {
        $d = JsonStore::read($this->file, []) ?: [];
        return (int) ($d[$this->day()] ?? 0);
    }

    public function assertCan(int $credits, string $what): void
    {
        $used = $this->used();
        if ($used + $credits > $this->cap) {
            Audit::event('prospeo_blocked', ['reason' => 'daily cap', 'used' => $used, 'cap' => $this->cap, 'needed' => $credits]);
            throw new AppError(429, 'PROSPEO_DAILY_CAP', "Today's Prospeo credit cap is reached ($used of {$this->cap} used; $what needs $credits). It resets at midnight (UTC).");
        }
    }

    public function add(int $credits): void
    {
        if ($credits <= 0) return;
        $day = $this->day();
        JsonStore::update($this->file, [], function ($d) use ($day, $credits) {
            $d = is_array($d) ? array_intersect_key($d, [$day => 1]) : [];
            $d[$day] = (int) ($d[$day] ?? 0) + $credits;
            return $d;
        });
    }
}

final class MarketSearch
{
    public const SENIORITY = ['Founder/Owner', 'C-Suite', 'Partner', 'Vice President', 'Head', 'Director', 'Manager', 'Senior', 'Entry', 'Intern'];
    /** our level -> Prospeo seniorities, years of experience */
    public const LEVELS = [
        'junior' => [['Entry'], 0, 3], 'medior' => [['Entry', 'Senior'], 2, 7], 'senior' => [['Senior'], 5, 60],
        'lead' => [['Senior', 'Manager'], 8, 60], 'head' => [['Head', 'Director', 'Manager'], 10, 60], 'c-level' => [['C-Suite', 'Vice President'], 12, 60],
    ];
    public const ACCOUNT_TTL = 600;
    public const SEARCH_CACHE_TTL = 24 * 3600;
    public const REVEAL_COST = ['email' => 1, 'mobile' => 10];

    public function __construct(
        private ProspeoClient $client,
        private CreditLedger $ledger,
        private string $storageDir,
        private ?AtlasListsSource $atlas,
        private array $dict,
    ) {}

    /** Remaining credits (account-information is free; cached 10 minutes) and today's use against the cap. */
    public function status(): array
    {
        $file = $this->storageDir . '/prospeo-account.json';
        $c = JsonStore::read($file, null);
        if (!is_array($c) || ($c['t'] ?? 0) < time() - self::ACCOUNT_TTL) {
            $r = $this->client->call('GET', '/account-information')['response'] ?? [];
            $c = ['t' => time(), 'remaining' => (int) ($r['remaining_credits'] ?? 0), 'plan' => (string) ($r['current_plan'] ?? '')];
            JsonStore::write($file, $c);
        }
        return ['remaining' => $c['remaining'], 'plan' => $c['plan'], 'usedToday' => $this->ledger->used(), 'cap' => $this->ledger->cap()];
    }

    /** "Brussels, Belgium" -> Prospeo's exact location name (search-suggestions is free; names cached 7 days, no personal data). */
    public function resolveLocation(string $place): ?string
    {
        $place = trim($place);
        if (mb_strlen($place) < 2) return null;
        $file = $this->storageDir . '/prospeo-locations.json';
        $cache = JsonStore::read($file, []) ?: [];
        $key = Phrases::lower($place);
        if (isset($cache[$key]) && ($cache[$key]['t'] ?? 0) > time() - 7 * 86400) return $cache[$key]['name'];
        $r = $this->client->call('POST', '/search-suggestions', ['location_search' => mb_substr($place, 0, 100)]);
        $name = null;
        $sugg = is_array($r['location_suggestions'] ?? null) ? $r['location_suggestions'] : [];
        $city = Phrases::lower(trim(explode(',', $place)[0]));
        foreach ($sugg as $s) if (str_contains(Phrases::lower((string) ($s['name'] ?? '')), $city)) { $name = (string) $s['name']; break; }
        $name ??= isset($sugg[0]['name']) ? (string) $sugg[0]['name'] : null;
        JsonStore::update($file, [], fn ($d) => array_merge(is_array($d) ? $d : [], [$key => ['name' => $name, 't' => time()]]));
        return $name;
    }

    /** Requirements -> the editable filter form (shown to the user before anything is searched). */
    public function filtersFor(array $req): array
    {
        $titles = [];
        $role = trim($req['role']);
        if ($role !== '') $titles[] = $role;
        foreach ($this->dict['titles'] as $canon => $t) {
            $all = array_merge([(string) $canon], array_map('strval', $t['synonyms'] ?? []));
            if (in_array(Phrases::lower($role), array_map([Phrases::class, 'lower'], $all), true)) {
                foreach ($all as $s) if (mb_strlen($s) > 3) $titles[] = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8'); // EN/NL/FR: Belgian profiles use all three
            }
        }
        $titles = array_slice(array_values(array_unique($titles, SORT_REGULAR)), 0, 10);
        $level = self::LEVELS[$req['seniority']] ?? null;
        $min = $req['minYears'] ?? ($level[1] ?? null);
        $loc = $req['location'] !== '' ? $req['location'] : 'Belgium';
        $resolved = $this->resolveLocation($loc) ?? $this->resolveLocation('Belgium');
        return [
            'titles' => $titles,
            'locations' => $resolved ? [$resolved] : [],
            'seniority' => $level[0] ?? [],
            'yearsMin' => $min,
            'yearsMax' => $min === null ? null : 60,
            'keywords' => array_slice($req['mustHave'], 0, 5),
        ];
    }

    /** The user's (edited) filter form -> Prospeo's filter object. Unknown keys and invalid values are dropped. */
    public static function toProspeo(mixed $f): array
    {
        $f = is_array($f) ? $f : [];
        $strs = function (mixed $v, int $max, int $minLen, int $maxLen): array {
            $out = [];
            foreach (is_array($v) ? $v : (is_string($v) ? (preg_split('~\R|,~', $v) ?: []) : []) as $s) {
                $s = is_string($s) ? trim(preg_replace('~\s+~u', ' ', $s) ?? $s) : '';
                if (mb_strlen($s) >= $minLen && mb_strlen($s) <= $maxLen && !in_array($s, $out, true)) $out[] = $s;
            }
            return array_slice($out, 0, $max);
        };
        $out = [];
        if ($t = $strs($f['titles'] ?? [], 10, 2, 100)) $out['person_job_title'] = ['include' => $t, 'match_mode' => 'SIMILAR'];
        if ($l = $strs($f['locations'] ?? [], 5, 2, 120)) $out['person_location_search'] = ['include' => $l];
        $sen = array_values(array_intersect(self::SENIORITY, is_array($f['seniority'] ?? null) ? $f['seniority'] : []));
        if ($sen) $out['person_seniority'] = ['include' => $sen];
        $min = is_numeric($f['yearsMin'] ?? null) ? max(0, min(60, (int) $f['yearsMin'])) : null;
        $max = is_numeric($f['yearsMax'] ?? null) ? max(1, min(60, (int) $f['yearsMax'])) : null;
        if ($min !== null || $max !== null) $out['person_year_of_experience'] = ['min' => $min ?? 0, 'max' => max($max ?? 60, $min ?? 0, 1)];
        if ($k = $strs($f['keywords'] ?? [], 5, 1, 100)) $out['person_search'] = ['include' => $k];
        if (!isset($out['person_job_title']) && !isset($out['person_search'])) throw new ValidationError('Add at least one job title or keyword.');
        return $out;
    }

    private static function slug(string $url): ?string
    {
        return preg_match('~linkedin\.com/in/([^/?#]+)~i', $url, $m) ? Phrases::lower(rawurldecode($m[1])) : null;
    }

    /** One page (25 people, 1 credit unless Prospeo says it is free) with fit scores and "Already in Atlas". */
    public function search(mixed $filters, int $page, mixed $requirements, string $user): array
    {
        $api = self::toProspeo($filters);
        $hash = substr(hash('sha256', json_encode([$api, $page])), 0, 24);
        $this->ledger->assertCan(1, 'a search page');
        $r = $this->client->call('POST', '/search-person', ['page' => $page, 'filters' => $api]);
        $results = ($r['error_code'] ?? '') === 'NO_RESULTS' ? [] : (is_array($r['results'] ?? null) ? $r['results'] : []);
        $credits = $results && empty($r['free']) ? 1 : 0;
        $this->ledger->add($credits);
        $pg = $r['pagination'] ?? [];
        if ((int) ($pg['current_page'] ?? $page) !== $page && $results) Audit::event('prospeo_page_mismatch', ['asked' => $page, 'got' => (int) $pg['current_page']]);

        // "Already in Atlas": one read-only Atlas lookup by exact LinkedIn URL for the whole page
        $inAtlas = [];
        if ($this->atlas) {
            $urls = [];
            foreach ($results as $x) if ($s = self::slug((string) ($x['person']['linkedin_url'] ?? ''))) $urls[] = 'linkedin.com/in/' . $s;
            try {
                $inAtlas = $this->atlas->findByLinkedIn($urls);
            } catch (AppError) {
                $inAtlas = [];
            }
        }
        $scorer = new RuleScorer(JdMatcher::requirements($requirements), $this->dict, $this->atlas?->map()['seniorityWords'] ?? []);
        $cards = [];
        $ids = [];
        foreach ($results as $x) {
            $p = $x['person'] ?? [];
            $co = $x['company'] ?? [];
            $pid = (string) ($p['person_id'] ?? '');
            if ($pid === '') continue;
            $ids[] = $pid;
            $title = (string) ($p['current_job_title'] ?? $p['headline'] ?? '');
            $entry = ['title' => $title, 'normalisedTitle' => ListMap::normaliseTitle($title, $this->atlas?->map()['seniorityWords'] ?? []), 'pastTitles' => [],
                'skills' => Skills::extract($title . "\n" . ($p['headline'] ?? '') . "\n" . ($co['name'] ?? ''), $this->dict),
                'city' => (string) ($p['location']['city'] ?? ''), 'country' => (string) ($p['location']['country'] ?? '')];
            $slug = self::slug((string) ($p['linkedin_url'] ?? ''));
            $atlasId = $slug !== null ? ($inAtlas[$slug] ?? null) : null;
            $cards[] = [
                'personId' => $pid, 'name' => (string) ($p['full_name'] ?? trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))),
                'title' => $title, 'company' => (string) ($co['name'] ?? ''),
                'location' => trim(implode(', ', array_filter([$p['location']['city'] ?? null, $p['location']['country'] ?? null]))),
                'linkedinUrl' => preg_match('~^https://([a-z]+\.)?linkedin\.com/~i', (string) ($p['linkedin_url'] ?? '')) ? (string) $p['linkedin_url'] : '',
                'fit' => $scorer->score($entry) + ['basis' => 'title & company only'],
                'inAtlas' => $atlasId ? ['id' => $atlasId, 'profileUrl' => $this->atlas->profileUrl($atlasId)] : null,
            ];
        }
        usort($cards, fn ($a, $b) => $b['fit']['score'] <=> $a['fit']['score']);
        // remember which people this session may reveal (ids only), and {searchHash -> ids} for 24 h (no names)
        $_SESSION['prospeo_ids'] = array_slice(array_merge($_SESSION['prospeo_ids'] ?? [], array_fill_keys($ids, true)), -500, 500, true);
        $now = time();
        JsonStore::update($this->storageDir . '/prospeo-search-cache.json', [], function ($d) use ($hash, $ids, $pg, $now) {
            $d = array_filter(is_array($d) ? $d : [], fn ($e) => ($e['t'] ?? 0) > $now - self::SEARCH_CACHE_TTL);
            $d[$hash] = ['ids' => $ids, 'total' => (int) ($pg['total_count'] ?? 0), 't' => $now];
            return $d;
        });
        Audit::event('prospeo_search', ['user' => $user, 'page' => $page, 'results' => count($cards), 'inAtlas' => count(array_filter($cards, fn ($c) => $c['inAtlas'])), 'credits' => $credits, 'searchHash' => $hash]);
        return ['results' => $cards, 'page' => $page, 'totalPages' => min(1000, (int) ($pg['total_page'] ?? ($results ? 1 : 0))), 'total' => (int) ($pg['total_count'] ?? count($cards)),
            'credits' => ['charged' => $credits, 'free' => !empty($r['free'])] + ['usedToday' => $this->ledger->used(), 'cap' => $this->ledger->cap()]];
    }

    /**
     * "Reveal contact": enrich-person for one person from this session's search results, after the user confirmed the
     * credit cost. Email only (1 credit) unless $what = "mobile" (10 credits, its own confirm). The result goes to the
     * browser only: it is never stored or logged.
     */
    public function reveal(string $personId, string $what, bool $confirmed, string $user): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{4,80}$/', $personId)) throw new ValidationError('Invalid person id');
        if (!isset(self::REVEAL_COST[$what])) throw new ValidationError('what must be email or mobile');
        if (empty($_SESSION['prospeo_ids'][$personId])) throw new AppError(403, 'NOT_IN_RESULTS', 'Search for this person first.');
        $cost = self::REVEAL_COST[$what];
        if (!$confirmed) throw new AppError(400, 'CONFIRM_REQUIRED', "Revealing the $what costs up to $cost credit" . ($cost > 1 ? 's' : '') . '. Confirm to continue.');
        $this->ledger->assertCan($cost, "revealing a $what");
        $r = $this->client->whileRevealing(fn () => $this->client->call('POST', '/enrich-person', [
            'data' => ['person_id' => $personId], 'only_verified_email' => true, 'enrich_mobile' => $what === 'mobile',
        ]));
        $p = $r['person'] ?? [];
        $email = (string) ($p['email']['email'] ?? '');
        $mobile = $what === 'mobile' ? (string) ($p['mobile']['mobile'] ?? '') : '';
        $found = ($r['error_code'] ?? '') !== 'NO_MATCH' && ($what === 'mobile' ? $mobile !== '' : $email !== '');
        $charged = !$found || !empty($r['free_enrichment']) ? 0 : ($what === 'mobile' ? 10 : 1);
        $this->ledger->add($charged);
        Audit::event('prospeo_reveal', ['user' => $user, 'what' => $what, 'found' => $found, 'credits' => $charged]);
        return ['found' => $found, 'email' => $email !== '' ? $email : null, 'emailStatus' => (string) ($p['email']['status'] ?? ''),
            'mobile' => $what === 'mobile' && $mobile !== '' ? $mobile : null, 'credits' => $charged, 'usedToday' => $this->ledger->used()];
    }
}

/** Fake Prospeo (same JSON shapes) for PROSPEO_BACKEND=mock and the tests. All people are fictional. */
final class MockProspeo
{
    public array $calls = [];
    public int $remaining = 4800;
    private array $seen = [];

    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $b = json_decode((string) $body, true) ?: [];
        $this->calls[] = [$method, $path, $b];
        $ok = fn (array $d, int $s = 200) => ['status' => $s, 'headers' => ['x-minute-request-left' => '59'], 'body' => json_encode($d)];
        if (!in_array('X-KEY: mock', $headers, true) && !preg_grep('/^X-KEY: test/', $headers)) return $ok(['error' => true, 'error_code' => 'INVALID_API_KEY'], 401);
        if ($path === '/account-information') return $ok(['error' => false, 'response' => ['current_plan' => 'STARTER', 'remaining_credits' => $this->remaining, 'used_credits' => 200]]);
        if ($path === '/search-suggestions') {
            $q = (string) ($b['location_search'] ?? '');
            $city = trim(explode(',', $q)[0]);
            $list = stripos($city, 'belg') !== false ? [['name' => 'Belgium', 'type' => 'COUNTRY']] : [['name' => ucfirst($city) . ', Belgium', 'type' => 'CITY'], ['name' => 'Belgium', 'type' => 'COUNTRY']];
            return $ok(['error' => false, 'location_suggestions' => $list, 'job_title_suggestions' => null]);
        }
        if ($path === '/search-person') {
            $titles = $b['filters']['person_job_title']['include'] ?? ['Engineer'];
            if (in_array('Nothing Matches', $titles, true)) return $ok(['error' => true, 'error_code' => 'NO_RESULTS'], 400);
            $page = (int) ($b['page'] ?? 1);
            $key = md5(json_encode($b));
            $free = isset($this->seen[$key]);
            $this->seen[$key] = true;
            $cities = ['Brussels', 'Antwerp', 'Ghent', 'Leuven', 'Liège', 'Rotterdam'];
            $results = [];
            for ($i = ($page - 1) * 25 + 1; $i <= min(60, $page * 25); $i++) {
                $li = $i % 5 === 0 ? sprintf('https://www.linkedin.com/in/example-person-%03d', $i) : sprintf('https://www.linkedin.com/in/market-person-%02d/', $i);
                $results[] = ['person' => ['person_id' => 'mp_' . substr(md5("p$i"), 0, 12), 'first_name' => 'Market', 'last_name' => sprintf('Person %02d', $i),
                    'full_name' => sprintf('Market Person %02d', $i), 'linkedin_url' => $li, 'current_job_title' => ($i % 3 === 0 ? 'Senior ' : '') . $titles[$i % count($titles)],
                    'headline' => $titles[0] . ' | Azure | Sentinel', 'location' => ['city' => $cities[$i % 6], 'country' => $i % 6 === 5 ? 'Netherlands' : 'Belgium'],
                    'email' => ['status' => 'VERIFIED', 'revealed' => false, 'email' => null], 'mobile' => ['status' => 'VERIFIED', 'revealed' => false, 'mobile' => null]],
                    'company' => ['company_id' => "c$i", 'name' => sprintf('Market Company %02d', $i % 17)]];
            }
            return $ok(['error' => false, 'free' => $free, 'results' => $results, 'pagination' => ['current_page' => $page, 'per_page' => 25, 'total_page' => 3, 'total_count' => 60]]);
        }
        if ($path === '/enrich-person') {
            $pid = (string) ($b['data']['person_id'] ?? '');
            if ($pid === 'mp_nomatch0000') return $ok(['error' => true, 'error_code' => 'NO_MATCH'], 400);
            $n = hexdec(substr(md5($pid), 0, 2));
            return $ok(['error' => false, 'free_enrichment' => false, 'person' => [
                'email' => ['status' => 'VERIFIED', 'revealed' => true, 'email' => "market.person.$n@example.test"],
                'mobile' => !empty($b['enrich_mobile']) ? ['status' => 'VERIFIED', 'revealed' => true, 'mobile' => sprintf('+32 400 00 00 %02d', $n % 100)] : ['status' => 'VERIFIED', 'revealed' => false, 'mobile' => null],
            ], 'company' => null]);
        }
        return $ok(['error' => true, 'error_code' => 'INVALID_REQUEST'], 400);
    }
}

final class Prospeo
{
    public const DAILY_CAP_DEFAULT = 200;

    public static function mode(array $c): string
    {
        $b = strtolower((string) ($c['PROSPEO_BACKEND'] ?? ''));
        if ($b === 'mock') return 'mock';
        if ($b === 'off') return 'off';
        return ($c['PROSPEO_API_KEY'] ?? '') !== '' ? 'prospeo' : 'off';
    }

    public static function client(array $c): ?ProspeoClient
    {
        $mode = self::mode($c);
        if ($mode === 'off') return null;
        $client = new ProspeoClient($mode === 'mock' ? 'mock' : (string) $c['PROSPEO_API_KEY']);
        if ($mode === 'mock') {
            static $mock = null;
            $mock ??= new MockProspeo();
            $client->transport = $mock;
        }
        return $client;
    }

    public static function ledger(array $c): CreditLedger
    {
        $cap = ($c['PROSPEO_DAILY_CREDIT_CAP'] ?? '') !== '' ? (int) $c['PROSPEO_DAILY_CREDIT_CAP'] : self::DAILY_CAP_DEFAULT;
        return new CreditLedger($c['STORAGE_DIR'] . '/prospeo-credits.json', $cap);
    }
}
