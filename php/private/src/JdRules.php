<?php
// Rule-based JD matching (no paid AI): dictionary, parser, scorer and the "Deep match in Claude" prompt.
//
//   JdDictionary  storage/jd-dictionary.json (editable over SFTP), copied from private/jd-dictionary.default.json:
//                 titles + synonyms (EN/NL/FR), ~480 skills/certifications (also the title-index vocabulary), seniority
//                 words, languages, Belgian locations, clearance and contract words, must/nice section headings.
//   JdParser      JD text -> requirements {role, seniority, minYears, mustHave, niceToHave, languages, location, clearance, contractType}
//   RuleScorer    0-100 against an index entry: title 35, must-haves 35, nice-to-haves 10, seniority 10, location 10,
//                 with the matched and missing items.
//   DeepPrompt    text for Claude (Atlas connector) with the JD, the requirements, the pool and person IDs only.
declare(strict_types=1);

namespace SignalDesk;

final class JdDictionary
{
    private static ?array $default = null;

    public static function defaultFile(): string
    {
        return dirname(__DIR__) . '/jd-dictionary.default.json';
    }

    /** The shipped default (also the vocabulary when no storage is involved, e.g. tests). */
    public static function default(): array
    {
        return self::$default ??= self::upgrade(json_decode((string) file_get_contents(self::defaultFile()), true) ?: []);
    }

    public const TEMPLATE = [
        '_help' => 'Your additions to private/jd-dictionary.default.json (which is updated with the app). Everything here is merged into the default: '
            . 'new titles/skills/locations are added, lists (synonyms, aliases, words) are combined. To switch off a default entry, list its name under "remove", '
            . 'e.g. "remove": {"skills": ["go"]}. Skills are also the title-index vocabulary: rebuild the index (Lists page) after changing them.',
        'titles' => ['Example Title (delete me)' => ['synonyms' => ['example synonym']]],
        'skills' => ['example skill (delete me)' => ['aliases' => ['example alias'], 'category' => 'dev']],
        'locations' => [],
        'sections' => ['must' => [], 'nice' => []],
        'remove' => ['titles' => [], 'skills' => [], 'locations' => []],
    ];

    /**
     * The default merged with storage/jd-dictionary.json (your additions; created from TEMPLATE on first use).
     * @return array{dict:array,file:string,error:?string}
     */
    public static function load(string $storageDir, ?string $defaultFile = null): array
    {
        $defaultFile ??= self::defaultFile();
        $default = json_decode((string) @file_get_contents($defaultFile), true) ?: [];
        $file = $storageDir . '/jd-dictionary.json';
        if (!is_file($file) && is_dir($storageDir)) @file_put_contents($file, json_encode(self::TEMPLATE, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $mine = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $error = null;
        if (!is_array($mine)) {
            $error = 'jd-dictionary.json is not valid JSON; using the default dictionary';
            $mine = [];
        }
        unset($mine['_help'], $default['_help']);
        foreach (['titles', 'skills', 'locations'] as $k) unset($mine[$k]['Example Title (delete me)'], $mine[$k]['example skill (delete me)']);
        $remove = is_array($mine['remove'] ?? null) ? $mine['remove'] : [];
        unset($mine['remove']);
        $d = self::merge($default, $mine);
        foreach ($remove as $section => $names) {
            if (is_array($d[$section] ?? null) && is_array($names)) foreach ($names as $n) unset($d[$section][(string) $n]);
        }
        return ['dict' => self::upgrade($d), 'file' => $file, 'error' => $error];
    }

    /** Lists are combined (no duplicates), maps merged key by key, other values: yours win. */
    public static function merge(mixed $a, mixed $b): mixed
    {
        if (!is_array($a) || !is_array($b)) return $b ?? $a;
        if (array_is_list($a) && array_is_list($b)) return array_values(array_unique(array_merge($a, $b), SORT_REGULAR));
        foreach ($b as $k => $v) $a[$k] = array_key_exists($k, $a) ? self::merge($a[$k], $v) : $v;
        return $a;
    }

    private static function upgrade(array $d): array
    {
        foreach (['titles', 'skills', 'seniority', 'languages', 'locations', 'contract', 'sections', 'jdLanguageHints'] as $k) $d[$k] = is_array($d[$k] ?? null) ? $d[$k] : [];
        foreach (['remote', 'clearance', 'niceLineMarkers'] as $k) $d[$k] = is_array($d[$k] ?? null) ? $d[$k] : [];
        return $d;
    }
}

/** Whole-phrase matching shared by the parser, the scorer and Skills. */
final class Phrases
{
    /** @var array<string,array{0:string,1:array<string,string>}> compiled alternations by key */
    private static array $compiled = [];

    public static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** Boundaries: not inside a word, and "go" does not match "go-live". */
    public static function wrap(string $inner): string
    {
        return '~(?<![\p{L}\p{N}#+.])(?<!\p{L}-)(?:' . $inner . ')(?![\p{L}\p{N}#+])(?!-\p{L})~u';
    }

    public static function has(string $text, string $phrase): bool
    {
        return $phrase !== '' && (bool) preg_match(self::wrap(preg_quote(self::lower($phrase), '~')), self::lower($text));
    }

    /**
     * Find every phrase of $map (phrase => canonical) in $text, in order of appearance, longest phrase first at a position.
     * @return array<int,array{0:string,1:int,2:string}> [canonical, offset, phrase]
     */
    public static function findAll(string $text, array $map, string $key): array
    {
        if (!$map) return [];
        if (!isset(self::$compiled[$key])) {
            $phrases = array_keys($map);
            usort($phrases, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
            $alt = implode('|', array_map(fn ($p) => preg_quote(self::lower((string) $p), '~'), $phrases));
            $lowerMap = [];
            foreach ($map as $p => $c) $lowerMap[self::lower((string) $p)] = $c;
            self::$compiled[$key] = [self::wrap($alt), $lowerMap];
        }
        [$re, $lowerMap] = self::$compiled[$key];
        if (!preg_match_all($re, self::lower($text), $m, PREG_OFFSET_CAPTURE)) return [];
        $out = [];
        foreach ($m[0] as [$hit, $off]) $out[] = [$lowerMap[$hit] ?? $hit, $off, $hit];
        return $out;
    }

    /** First canonical found, or null. */
    public static function first(string $text, array $map, string $key): ?string
    {
        return self::findAll($text, $map, $key)[0][0] ?? null;
    }
}

final class JdParser
{
    public const LEVELS = ['junior', 'medior', 'senior', 'lead', 'head', 'c-level'];
    public const MAX_SKILLS = 10;

    public function __construct(private array $dict) {}

    private function key(string $what): string
    {
        return $what . ':' . md5(json_encode($this->dict[$what === 'skills' ? 'skills' : 'titles'] ?? []) . json_encode($this->dict[$what] ?? []));
    }

    /** phrase => canonical maps from the dictionary */
    public static function skillMap(array $dict): array
    {
        $m = [];
        foreach ($dict['skills'] as $canon => $s) {
            $m[(string) $canon] = (string) $canon;
            foreach ($s['aliases'] ?? [] as $a) $m[(string) $a] = (string) $canon;
        }
        return $m;
    }

    public static function titleMap(array $dict): array
    {
        $m = [];
        foreach ($dict['titles'] as $canon => $t) {
            $m[(string) $canon] = (string) $canon;
            foreach ($t['synonyms'] ?? [] as $s) $m[(string) $s] = (string) $canon;
        }
        return $m;
    }

    private static function listMap(array $groups): array
    {
        $m = [];
        foreach ($groups as $canon => $words) foreach ((array) $words as $w) $m[(string) $w] = (string) $canon;
        return $m;
    }

    public static function locationMap(array $dict): array
    {
        $m = [];
        foreach ($dict['locations'] as $canon => $l) {
            $m[(string) $canon] = (string) $canon;
            foreach ($l['aliases'] ?? [] as $a) $m[(string) $a] = (string) $canon;
        }
        return $m;
    }

    /** Highest seniority level whose words appear in $text, or null. */
    public static function level(string $text, array $dict): ?string
    {
        $found = array_column(Phrases::findAll($text, self::listMap($dict['seniority']), 'sen:' . md5(json_encode($dict['seniority']))), 0);
        $best = null;
        foreach ($found as $lvl) if ($best === null || array_search($lvl, self::LEVELS, true) > array_search($best, self::LEVELS, true)) $best = $lvl;
        return $best;
    }

    public static function levelFromYears(int $y): string
    {
        return $y < 2 ? 'junior' : ($y < 5 ? 'medior' : ($y < 10 ? 'senior' : 'lead'));
    }

    /** Section a heading line opens: must | nice | other | null (not a heading). Longest matching phrase wins. */
    private function heading(string $line): ?array
    {
        $parts = explode(':', $line, 2);
        $head = trim(preg_replace('~^[\s\-–—•*·#>\d.)]+~u', '', $parts[0]) ?? $parts[0]);
        if ($head === '' || mb_strlen($head) > 60 || count(preg_split('~\s+~u', $head) ?: []) > 7) return null;
        if (count($parts) === 1 && preg_match('~[.!]\s*$~u', $head)) return null; // a sentence, not a heading ("Wat bieden wij?" is one)
        $best = null;
        $bestLen = 0;
        foreach (['must', 'nice', 'other', 'skip'] as $kind) {
            foreach ($this->dict['sections'][$kind] ?? [] as $w) {
                $w = (string) $w;
                if (mb_strlen($w) > $bestLen && Phrases::has($head, $w)) { $best = $kind; $bestLen = mb_strlen($w); }
            }
        }
        if ($best === null) return null;
        // without a colon the line must be (nearly) only the heading: "Jouw profiel", "Wat bieden wij?", not "CCNP is een pluspunt"
        if (count($parts) === 1 && !preg_match('~[:?]\s*$~u', $line) && mb_strlen($head) > $bestLen + 12) return null;
        if (count($parts) === 1 && preg_match('~^[\s\-–—•*·]~u', $line)) return null; // a bullet is content, not a heading
        return [$best, count($parts) === 2 ? trim($parts[1]) : ''];
    }

    public function jdLanguage(string $text): string
    {
        $words = array_count_values(preg_split('~[^\p{L}]+~u', Phrases::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $best = 'en';
        $bestN = -1;
        foreach ($this->dict['jdLanguageHints'] as $lang => $hints) {
            $n = 0;
            foreach ($hints as $h) $n += $words[$h] ?? 0;
            if ($n > $bestN) { $best = (string) $lang; $bestN = $n; }
        }
        return $best;
    }

    /** @return array{requirements:array,jdLanguage:string,sections:bool} */
    public function parse(string $text): array
    {
        $d = $this->dict;
        $lines = array_values(array_filter(array_map(fn ($l) => trim((string) $l), preg_split('~\R~u', $text) ?: []), fn ($l) => $l !== ''));
        $skills = self::skillMap($d);
        $skillKey = 'skills:' . md5(json_encode($d['skills']));
        $titles = self::titleMap($d);
        $titleKey = 'titles:' . md5(json_encode($d['titles']));
        $niceMarkers = array_fill_keys(array_map('strval', $d['niceLineMarkers']), 'nice');

        // role: a known title in the first lines (first line wins), else the cleaned first line
        $role = '';
        foreach (array_slice($lines, 0, 5) as $l) {
            $hits = Phrases::findAll($l, $titles, $titleKey);
            if ($hits) {
                usort($hits, fn ($a, $b) => mb_strlen($b[2]) <=> mb_strlen($a[2]));
                $role = $hits[0][0];
                break;
            }
        }
        $first = $lines[0] ?? '';
        if ($role === '' && $first !== '' && mb_strlen($first) <= 80 && count(preg_split('~\s+~u', $first) ?: []) <= 8) {
            $role = ucwords(ListMap::normaliseTitle($first, array_merge(...array_values(array_map(fn ($w) => (array) $w, $d['seniority']))) ?: [], true));
        }

        // skills by section
        $buckets = ['must' => [], 'nice' => [], 'loose' => []];
        $mode = null;
        $sawMust = false;
        // each sentence on its own: "Must have: A, B. Nice to have: C. French is a plus."
        $clauses = [];
        foreach ($lines as $l) foreach (preg_split('~(?<=[.;!?])\s+(?=\p{Lu})~u', $l) ?: [$l] as $c) if (trim($c) !== '') $clauses[] = trim($c);
        foreach ($clauses as $l) {
            $content = $l;
            $lineMode = $mode;
            if ($h = $this->heading($l)) {
                [$kind, $rest] = $h;
                if ($rest === '') { $mode = $kind === 'other' ? null : $kind; continue; }
                $lineMode = $kind === 'other' ? null : $kind;
                $content = $rest;
            }
            if (Phrases::findAll($content, $niceMarkers, 'nice:' . md5(json_encode($niceMarkers)))) $lineMode = 'nice';
            if ($lineMode === 'skip') continue; // what we offer, about us, how to apply
            $bucket = $lineMode ?? 'loose';
            if ($bucket === 'must') $sawMust = true;
            foreach (Phrases::findAll($content, $skills, $skillKey) as [$canon]) {
                if (!in_array($canon, $buckets[$bucket], true)) $buckets[$bucket][] = $canon;
            }
        }
        if ($sawMust) {
            $must = $buckets['must'];
            $nice = array_merge($buckets['nice'], $buckets['loose']);
        } else {
            $nice = $buckets['nice'];
            $must = array_values(array_diff($buckets['loose'], $nice));
        }
        $nice = array_values(array_unique(array_diff($nice, $must)));

        // seniority: words in the title line(s), else years of experience
        $years = 0;
        if (preg_match_all('~(\d{1,2})\s*\+?\s*(?:-\s*\d{1,2}\s*)?(?:years?|yrs|jaar|jaren|ans|années?)~iu', $text, $ym)) {
            foreach ($ym[1] as $y) if ((int) $y <= 30) $years = max($years, (int) $y);
        }
        $level = self::level(implode(' ', array_slice($lines, 0, 1)), $d) ?? ($years ? self::levelFromYears($years) : '');

        // languages, location, clearance, contract
        $langs = array_values(array_unique(array_column(Phrases::findAll($text, self::listMap($d['languages']), 'lang:' . md5(json_encode($d['languages']))), 0)));
        $locMap = self::locationMap($d);
        $locKey = 'loc:' . md5(json_encode($d['locations']));
        $loc = null;
        foreach ($lines as $l) {
            if (preg_match('~\b(location|locatie|standplaats|plaats van tewerkstelling|werkplek|lieu|lieu de travail|based in|localisation)\b~iu', $l)) {
                $loc = Phrases::first($l, $locMap, $locKey);
                if ($loc) break;
            }
        }
        $loc ??= Phrases::first($text, $locMap, $locKey);
        $location = $loc === null ? '' : ($loc === 'Belgium' ? 'Belgium' : "$loc, Belgium");
        $clearance = '';
        $cl = Phrases::findAll($text, array_fill_keys(array_map('strval', $d['clearance']), 'x'), 'cl:' . md5(json_encode($d['clearance'])));
        if ($cl) {
            $lvl = preg_match('~\b(cosmic top secret|nato secret|eu secret|nato confidential|secret|confidential)\b~iu', $text, $cm) ? ' (' . strtoupper($cm[1]) . ')' : '';
            $clearance = 'Security clearance' . $lvl;
        }
        $kinds = array_values(array_unique(array_column(Phrases::findAll($text, self::listMap($d['contract']), 'ct:' . md5(json_encode($d['contract']))), 0)));
        $contract = in_array('freelance', $kinds, true) && in_array('permanent', $kinds, true) ? 'permanent or freelance' : ($kinds[0] ?? '');

        $req = [
            'role' => $role, 'seniority' => $level, 'minYears' => $years ?: null,
            'mustHave' => array_slice($must, 0, self::MAX_SKILLS), 'niceToHave' => array_slice($nice, 0, self::MAX_SKILLS),
            'languages' => $langs, 'location' => $location, 'clearance' => $clearance, 'contractType' => $contract,
        ];
        return ['requirements' => $req, 'jdLanguage' => $this->jdLanguage($text), 'sections' => $sawMust];
    }
}

final class RuleScorer
{
    public const WEIGHTS = ['title' => 35, 'must' => 35, 'nice' => 10, 'seniority' => 10, 'location' => 10];
    private const STOP = ['and', 'of', 'the', 'en', 'de', 'et', 'for', 'in', 'van', 'du', 'des', 'la', 'le', '-', '&', '/'];

    private array $titlePhrases;
    private array $skillMap;
    private string $skillKey;
    private array $locMap;
    private string $locKey;
    private ?array $jobLoc;
    private array $must;
    private array $nice;

    public function __construct(private array $req, private array $dict, private array $seniorityWords = [])
    {
        $this->skillMap = JdParser::skillMap($dict);
        $this->skillKey = 'skills:' . md5(json_encode($dict['skills']));
        $this->locMap = JdParser::locationMap($dict);
        $this->locKey = 'loc:' . md5(json_encode($dict['locations']));
        $role = (string) ($req['role'] ?? '');
        $phrases = [ListMap::normaliseTitle($role, $seniorityWords)];
        foreach ($dict['titles'] as $canon => $t) {
            $all = array_merge([(string) $canon], array_map('strval', $t['synonyms'] ?? []));
            foreach ($all as $s) {
                if (strcasecmp(ListMap::normaliseTitle($s, $seniorityWords), $phrases[0]) === 0) {
                    foreach ($all as $x) $phrases[] = ListMap::normaliseTitle($x, $seniorityWords);
                    break;
                }
            }
        }
        $this->titlePhrases = array_values(array_unique(array_filter($phrases)));
        $loc = trim(explode(',', (string) ($req['location'] ?? ''))[0]);
        $canon = $loc === '' ? null : Phrases::first($loc, $this->locMap, $this->locKey);
        $this->jobLoc = $loc === '' ? null : ['name' => $loc, 'canon' => $canon, 'province' => $canon ? (string) ($dict['locations'][$canon]['province'] ?? '') : ''];
        $this->must = $this->items($req['mustHave'] ?? []);
        $this->nice = $this->items($req['niceToHave'] ?? []);
    }

    /** requirement text => [label, canonical skills] */
    private function items(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $item = trim((string) $item);
            if ($item === '') continue;
            $canon = array_values(array_unique(array_column(Phrases::findAll($item, $this->skillMap, $this->skillKey), 0)));
            $out[] = [$item, $canon];
        }
        return $out;
    }

    private static function words(string $s): array
    {
        return array_values(array_filter(preg_split('~\s+~u', $s) ?: [], fn ($w) => mb_strlen($w) > 1 && !in_array($w, self::STOP, true)));
    }

    private function hasItem(array $entry, array $item): bool
    {
        [$label, $canon] = $item;
        $skills = $entry['skills'] ?? [];
        foreach ($canon as $c) if (in_array($c, $skills, true)) return true;
        $text = ($entry['title'] ?? '') . ' | ' . implode(' | ', $entry['pastTitles'] ?? []);
        return Phrases::has($text, $label) || ($canon && Phrases::has($text, $canon[0]));
    }

    /** @return array{score:int,parts:array<string,int>,matched:string[],missing:string[]} */
    public function score(array $entry): array
    {
        $W = self::WEIGHTS;
        $matched = [];
        $missing = [];
        // title
        $t = (string) ($entry['normalisedTitle'] ?? '');
        $title = 0.0;
        if ($this->titlePhrases) {
            foreach ($this->titlePhrases as $p) if ($t !== '' && Phrases::has($t, $p)) { $title = $W['title']; break; }
            if (!$title) {
                foreach ($this->titlePhrases as $p) {
                    $pw = self::words($p);
                    if ($pw && $t !== '') $title = max($title, 25 * count(array_intersect($pw, self::words($t))) / count($pw));
                }
                foreach ($entry['pastTitles'] ?? [] as $pt) {
                    foreach ($this->titlePhrases as $p) if (Phrases::has(ListMap::normaliseTitle((string) $pt, $this->seniorityWords), $p)) { $title = max($title, 20); $matched[] = 'past title: ' . $pt; break 2; }
                }
            }
            if ($title >= $W['title']) $matched[] = 'title: ' . ($entry['title'] ?? '');
            elseif ($title > 0 && !preg_grep('~^past title~', $matched)) $matched[] = 'similar title: ' . ($entry['title'] ?? '');
            elseif ($title == 0) $missing[] = 'title: ' . ($this->req['role'] ?? '');
        } else {
            $title = $W['title'] / 2;
        }
        // skills
        $part = function (array $items, int $weight, string $what) use ($entry, &$matched, &$missing): float {
            if (!$items) return $weight / 2;
            $hit = 0;
            foreach ($items as $it) {
                if ($this->hasItem($entry, $it)) { $hit++; $matched[] = $it[0]; } else { $missing[] = ($what === 'nice' ? 'nice to have: ' : '') . $it[0]; }
            }
            return $weight * $hit / count($items);
        };
        $must = $part($this->must, $W['must'], 'must');
        $nice = $part($this->nice, $W['nice'], 'nice');
        // seniority
        $want = (string) ($this->req['seniority'] ?? '');
        $have = JdParser::level((string) ($entry['title'] ?? ''), $this->dict);
        if ($want === '' || !in_array($want, JdParser::LEVELS, true) || $have === null) {
            $sen = $W['seniority'] / 2;
        } else {
            $diff = abs(array_search($want, JdParser::LEVELS, true) - array_search($have, JdParser::LEVELS, true));
            $sen = [10, 6, 3][$diff] ?? 0;
            $diff === 0 ? $matched[] = "seniority: $have" : $missing[] = "seniority: $have (job: $want)";
        }
        // location
        $city = (string) ($entry['city'] ?? '');
        $country = Phrases::lower((string) ($entry['country'] ?? ''));
        $inBelgium = Phrases::first($country, $this->locMap, $this->locKey) === 'Belgium';
        if (!$this->jobLoc) {
            $loc = $W['location'] / 2;
        } else {
            $entryCanon = $city !== '' ? Phrases::first($city, $this->locMap, $this->locKey) : null;
            $j = $this->jobLoc;
            if ($j['canon'] === 'Belgium') $loc = $inBelgium ? 10 : 0;
            elseif ($entryCanon !== null && $entryCanon === $j['canon']) $loc = 10;
            elseif ($entryCanon !== null && $j['province'] !== '' && ($this->dict['locations'][$entryCanon]['province'] ?? '') === $j['province']) $loc = 7;
            elseif ($inBelgium) $loc = 5;
            elseif ($city === '' && $country === '') $loc = 3;
            else $loc = 0;
            $where = trim($city . ($country !== '' ? ', ' . ($entry['country'] ?? '') : ''), ', ');
            $loc >= 7 ? $matched[] = "location: $where" : $missing[] = 'location: ' . ($where ?: 'unknown') . ' (job: ' . $j['name'] . ')';
        }
        $parts = ['title' => (int) round($title), 'must' => (int) round($must), 'nice' => (int) round($nice), 'seniority' => (int) round($sen), 'location' => (int) round($loc)];
        return ['score' => min(100, array_sum($parts)), 'parts' => $parts, 'matched' => $matched, 'missing' => $missing]; // the parts on screen add up
    }
}

final class DeepPrompt
{
    public const IDS = 20;
    public const MAX_JD = 12000;

    /**
     * Prompt for Claude with the Atlas connector. Contains the JD (contact details masked), the requirements, the pool
     * and Atlas person IDs only: never candidate names or other personal data.
     */
    public static function build(string $jdText, array $req, array $pools, array $ranked): string
    {
        $jd = JdMatcher::scrub(mb_substr(trim($jdText), 0, self::MAX_JD));
        $lines = [];
        foreach (array_slice($ranked, 0, self::IDS) as $i => [$pid, $score]) $lines[] = sprintf('%d. %s (rule score %d)', $i + 1, $pid, $score);
        $r = fn (string $k) => is_array($req[$k] ?? null) ? (implode(', ', $req[$k]) ?: '—') : ((string) ($req[$k] ?? '') ?: '—');
        return "I recruit IT people in Belgium for SwooScale. Please do a deep match for the job below using the Atlas connector (read-only).\n\n"
            . "How:\n"
            . "1. Look up each Atlas person ID listed below with getCandidateProfile (up to 10 IDs per call). Use searchPeople only if you need to find one of these same people again. Don't search for or add anyone else.\n"
            . "2. Score each candidate 0–100 against the requirements, using only what Atlas shows (experience, skills, location, CV, interview notes).\n"
            . "3. For each candidate give: score, the must-haves met with short evidence, the gaps, and risks (seniority, location, languages, contract type, availability).\n"
            . "4. Return a table ranked best first, then a 3-line summary of who to call first and why.\n"
            . "Don't change anything in Atlas and don't contact anyone. Ignore age, gender, nationality and other protected characteristics.\n\n"
            . 'Pool: ' . (implode(' + ', $pools) ?: 'all pools') . "\n\n"
            . "Requirements (parsed by Signal Desk; check them against the job description):\n"
            . '- Role: ' . $r('role') . "\n- Seniority: " . $r('seniority') . ((int) ($req['minYears'] ?? 0) ? ' (' . (int) $req['minYears'] . '+ years)' : '') . "\n"
            . '- Must have: ' . $r('mustHave') . "\n- Nice to have: " . $r('niceToHave') . "\n- Languages: " . $r('languages') . "\n"
            . '- Location: ' . $r('location') . "\n- Clearance: " . $r('clearance') . "\n- Contract: " . $r('contractType') . "\n\n"
            . 'Atlas person IDs (top ' . count($lines) . " from Signal Desk's rule-based ranking, best first):\n" . implode("\n", $lines) . "\n\n"
            . "Job description:\n\"\"\"\n$jd\n\"\"\"\n";
    }
}
