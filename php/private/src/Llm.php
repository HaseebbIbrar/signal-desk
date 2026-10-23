<?php
// JD matching: the language-model side (Anthropic Messages API over HTTPS, no SDK: the app has no Composer).
//
//   Llm             one structured-JSON call (output_config.format = JSON schema)
//   AnthropicLlm    POST https://api.anthropic.com/v1/messages; spend guard checked before, recorded after;
//                   audit log: purpose, model, status, tokens, cost. Never the prompt or the answer.
//   MockLlm         deterministic fake for ANTHROPIC_BACKEND=mock and the tests (no network)
//   SpendGuard      monthly euro budget (ANTHROPIC_MONTHLY_LIMIT_EUR) in storage/anthropic-spend.json
//   DailyLimit      per-user daily counter (20 JD matches a day) in storage/jd-usage.json
declare(strict_types=1);

namespace SignalDesk;

interface Llm
{
    /**
     * One call that must return JSON matching $schema.
     * @return array{data:array,model:string,inputTokens:int,outputTokens:int,eur:float}
     */
    public function json(string $purpose, string $system, string $user, array $schema, int $maxTokens, string $effort): array;

    /** @return array{0:string,1:string} [OK|WARN|FAIL, detail] without spending tokens */
    public function ping(): array;

    public function model(): string;
}

final class SpendGuard
{
    /** @var callable(): int */
    public $now;

    public function __construct(private string $file, private float $limitEur, private float $usdToEur = 0.92)
    {
        $this->now = fn () => time();
    }

    private function month(): string
    {
        return gmdate('Y-m', ($this->now)());
    }

    public function spent(): float
    {
        $d = JsonStore::read($this->file, []) ?: [];
        return (float) ($d[$this->month()]['eur'] ?? 0);
    }

    public function limit(): float
    {
        return $this->limitEur;
    }

    public function usdToEur(float $usd): float
    {
        return $usd * $this->usdToEur;
    }

    /** Refuse BEFORE the call when this month's spend plus a worst-case estimate would pass the limit. */
    public function assertCanSpend(float $estimateEur): void
    {
        $spent = $this->spent();
        if ($spent + $estimateEur > $this->limitEur) {
            Audit::event('llm_blocked', ['reason' => 'monthly limit', 'spentEur' => round($spent, 4), 'limitEur' => $this->limitEur]);
            throw new AppError(429, 'SPEND_LIMIT', sprintf('The monthly AI budget is used up (€%.2f of €%.2f). It resets on the 1st, or raise ANTHROPIC_MONTHLY_LIMIT_EUR in config.php.', $spent, $this->limitEur));
        }
    }

    public function record(float $eur): void
    {
        $m = $this->month();
        JsonStore::update($this->file, [], function ($d) use ($m, $eur) {
            $d = is_array($d) ? $d : [];
            $d[$m] = ['eur' => round((float) ($d[$m]['eur'] ?? 0) + $eur, 6), 'calls' => (int) ($d[$m]['calls'] ?? 0) + 1];
            return $d;
        });
    }
}

final class DailyLimit
{
    /** @var callable(): int */
    public $now;

    public function __construct(private string $file, private int $limit, private string $what = 'JD matches')
    {
        $this->now = fn () => time();
    }

    private function day(): string
    {
        return gmdate('Y-m-d', ($this->now)());
    }

    public function used(string $user): int
    {
        $d = JsonStore::read($this->file, []) ?: [];
        return (int) ($d[$this->day()][strtolower($user)] ?? 0);
    }

    public function remaining(string $user): int
    {
        return max(0, $this->limit - $this->used($user));
    }

    /** Count one use for $user today, or refuse when the limit is reached. */
    public function take(string $user): int
    {
        $day = $this->day();
        $u = strtolower($user);
        $refused = false;
        $n = 0;
        JsonStore::update($this->file, [], function ($d) use ($day, $u, &$refused, &$n) {
            $d = is_array($d) ? array_intersect_key($d, [$day => 1]) : []; // keep today only
            $n = (int) ($d[$day][$u] ?? 0);
            if ($n >= $this->limit) {
                $refused = true;
                return $d;
            }
            $d[$day][$u] = ++$n;
            return $d;
        });
        if ($refused) throw new AppError(429, 'DAILY_LIMIT', "You have used today's {$this->limit} {$this->what}. The limit resets at midnight (UTC).");
        return $this->limit - $n;
    }
}

final class AnthropicLlm implements Llm
{
    public const API = 'https://api.anthropic.com/v1/messages';
    public const VERSION = '2026-10-21';
    public const DEFAULT_MODEL = 'claude-opus-5-5';
    /** USD per million tokens [input, output]. Unknown models are charged at the most expensive rate. */
    public const PRICES = [
        'claude-fable-5-1' => [10.0, 50.0], 'claude-fable-5' => [10.0, 50.0], 'claude-opus-5-5' => [4.0, 20.0], 'claude-opus-5' => [5.0, 25.0],
        'claude-opus-4-8' => [5.0, 25.0], 'claude-opus-4-7' => [5.0, 25.0], 'claude-opus-4-6' => [5.0, 25.0], 'claude-sonnet-5-5' => [2.0, 10.0],
        'claude-sonnet-5' => [2.0, 10.0], 'claude-sonnet-4-6' => [3.0, 15.0], 'claude-haiku-5-5' => [0.1, 0.5], 'claude-haiku-4-5' => [1.0, 5.0],
    ];
    /** Models that accept server-side refusal fallbacks ("fallbacks": "default"). */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{status:int,body:string} */
    public $transport = null;
    /** @var callable fn(int $ms) */
    public $sleep;
    private bool $useFallbacks;

    public function __construct(private string $apiKey, private string $modelId, private SpendGuard $spend)
    {
        $this->modelId = $modelId !== '' ? $modelId : self::DEFAULT_MODEL;
        $this->useFallbacks = in_array($this->modelId, self::FALLBACK_MODELS, true);
        $this->sleep = fn (int $ms) => usleep($ms * 1000);
    }

    public function model(): string
    {
        return $this->modelId;
    }

    public static function price(string $model): array
    {
        return self::PRICES[$model] ?? [10.0, 50.0];
    }

    public static function costUsd(string $model, array $usage): float
    {
        [$in, $out] = self::price($model);
        $input = (int) ($usage['input_tokens'] ?? 0) + 1.25 * (int) ($usage['cache_creation_input_tokens'] ?? 0) + 0.1 * (int) ($usage['cache_read_input_tokens'] ?? 0);
        return ($input * $in + (int) ($usage['output_tokens'] ?? 0) * $out) / 1_000_000;
    }

    private function headers(): array
    {
        $h = ['x-api-key: ' . $this->apiKey, 'anthropic-version: ' . self::VERSION, 'content-type: application/json'];
        if ($this->useFallbacks) $h[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        return $h;
    }

    public function json(string $purpose, string $system, string $user, array $schema, int $maxTokens, string $effort): array
    {
        [$priceIn, $priceOut] = self::price($this->modelId);
        $estimate = $this->spend->usdToEur(((strlen($system) + strlen($user)) / 3 * $priceIn + $maxTokens * 0.6 * $priceOut) / 1_000_000);
        $this->spend->assertCanSpend($estimate);
        $body = [
            'model' => $this->modelId, 'max_tokens' => $maxTokens, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        ];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($this->useFallbacks) $body['fallbacks'] = 'default'; else unset($body['fallbacks']);
            $t0 = microtime(true);
            $res = $this->send('POST', self::API, $this->headers(), json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $s = $res['status'];
            $d = json_decode($res['body'], true);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            if ($s === 200 && is_array($d)) {
                $model = (string) ($d['model'] ?? $this->modelId);
                $usage = is_array($d['usage'] ?? null) ? $d['usage'] : [];
                $eur = $this->spend->usdToEur(self::costUsd($model, $usage));
                $this->spend->record($eur);
                Audit::event('llm_call', ['purpose' => $purpose, 'model' => $model, 'status' => 200, 'ms' => $ms, 'stop' => $d['stop_reason'] ?? null,
                    'inputTokens' => (int) ($usage['input_tokens'] ?? 0), 'outputTokens' => (int) ($usage['output_tokens'] ?? 0), 'eur' => round($eur, 5)]);
                if (($d['stop_reason'] ?? '') === 'refusal') throw new AppError(502, 'LLM_REFUSED', 'The AI declined this request. Try again or edit the job description.');
                if (($d['stop_reason'] ?? '') === 'max_tokens') throw new AppError(502, 'LLM_TRUNCATED', 'The AI answer was cut off. Try fewer candidates or a shorter job description.');
                $text = '';
                foreach ($d['content'] ?? [] as $block) if (($block['type'] ?? '') === 'text') $text .= (string) ($block['text'] ?? '');
                $data = json_decode($text, true);
                if (!is_array($data)) throw new UpstreamError('The AI returned an unreadable answer');
                return ['data' => $data, 'model' => $model, 'inputTokens' => (int) ($usage['input_tokens'] ?? 0), 'outputTokens' => (int) ($usage['output_tokens'] ?? 0), 'eur' => $eur];
            }
            Audit::event('llm_call', ['purpose' => $purpose, 'model' => $this->modelId, 'status' => $s, 'ms' => $ms, 'errorType' => $d['error']['type'] ?? null]);
            $msg = (string) ($d['error']['message'] ?? '');
            if ($s === 400 && $this->useFallbacks && stripos($msg, 'fallback') !== false) {
                $this->useFallbacks = false; // account/model without the fallback beta: retry once without it
                continue;
            }
            if (in_array($s, [429, 500, 502, 503, 504, 529], true) && $attempt < 3) {
                ($this->sleep)(1500 * $attempt);
                continue;
            }
            if ($s === 401 || $s === 403) throw new UpstreamError('Anthropic refused the API key (check ANTHROPIC_API_KEY in config.php)');
            if ($s === 429 || $s === 529) throw new AppError(503, 'LLM_BUSY', 'The AI service is busy. Try again in a minute.');
            if ($s === 0) throw new UpstreamError('Could not reach the AI service');
            throw new UpstreamError("AI service error $s");
        }
        throw new UpstreamError('The AI service did not answer');
    }

    public function ping(): array
    {
        $res = $this->send('GET', 'https://api.anthropic.com/v1/models/' . rawurlencode($this->modelId), $this->headers(), null);
        return match (true) {
            $res['status'] === 200 => ['OK', "model {$this->modelId} available"],
            $res['status'] === 401 || $res['status'] === 403 => ['FAIL', 'API key refused'],
            $res['status'] === 404 => ['FAIL', "model {$this->modelId} not found (check ANTHROPIC_MODEL)"],
            $res['status'] === 0 => ['FAIL', 'api.anthropic.com not reachable'],
            default => ['WARN', 'HTTP ' . $res['status']],
        };
    }

    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport) return ($this->transport)($method, $url, $headers, $body);
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 170, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = (string) $body;
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = $out === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $out === false ? '' : (string) $out];
    }
}

/**
 * Deterministic fake model for ANTHROPIC_BACKEND=mock and the tests. Reads the JSON data block of the prompt
 * (between <data> and </data>) and answers from simple rules. Charges a fixed fake cost so spend limits can be tested.
 */
final class MockLlm implements Llm
{
    public int $calls = 0;
    public array $purposes = [];

    public function __construct(private ?SpendGuard $spend = null, private float $eurPerCall = 0.01) {}

    public function model(): string
    {
        return 'mock-llm';
    }

    public function ping(): array
    {
        return ['OK', 'mock (fake AI, no network)'];
    }

    public static function data(string $prompt): array
    {
        return preg_match('~<data>(.*)</data>~s', $prompt, $m) ? (json_decode($m[1], true) ?: []) : [];
    }

    public function json(string $purpose, string $system, string $user, array $schema, int $maxTokens, string $effort): array
    {
        $this->spend?->assertCanSpend($this->eurPerCall);
        $this->calls++;
        $this->purposes[] = $purpose;
        $d = self::data($user);
        $data = match ($purpose) {
            'jd_parse' => self::parse((string) ($d['jobDescription'] ?? '')),
            'jd_score' => self::score($d['requirements'] ?? [], $d['candidates'] ?? []),
            default => [],
        };
        $this->spend?->record($this->eurPerCall);
        Audit::event('llm_call', ['purpose' => $purpose, 'model' => 'mock-llm', 'status' => 200, 'eur' => $this->eurPerCall]);
        return ['data' => $data, 'model' => 'mock-llm', 'inputTokens' => (int) (strlen($user) / 4), 'outputTokens' => 200, 'eur' => $this->eurPerCall];
    }

    private static function parse(string $jd): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $jd) ?: []), fn ($l) => $l !== ''));
        $role = preg_replace('~^(job title|title|role|functie)\s*:\s*~i', '', $lines[0] ?? '');
        $lower = strtolower($jd);
        $skills = Skills::extract($jd);
        $langs = [];
        foreach (['dutch' => 'Dutch', 'nederlands' => 'Dutch', 'french' => 'French', 'français' => 'French', 'english' => 'English', 'german' => 'German'] as $k => $v) {
            if (str_contains($lower, $k) && !in_array($v, $langs, true)) $langs[] = $v;
        }
        $loc = 'Belgium';
        foreach (['Brussels', 'Antwerp', 'Ghent', 'Leuven', 'Liège', 'Mechelen'] as $c) if (stripos($jd, $c) !== false) { $loc = "$c, Belgium"; break; }
        return [
            'role' => mb_substr((string) $role, 0, 80),
            'seniority' => str_contains($lower, 'senior') ? 'senior' : (str_contains($lower, 'junior') ? 'junior' : (str_contains($lower, 'lead') ? 'lead' : 'medior')),
            'mustHave' => array_slice($skills, 0, 5), 'niceToHave' => array_slice($skills, 5, 4), 'languages' => $langs, 'location' => $loc,
            'clearance' => str_contains($lower, 'clearance') ? 'Security clearance required' : '',
            'contractType' => str_contains($lower, 'freelance') ? 'freelance' : 'permanent',
        ];
    }

    private static function score(array $req, array $candidates): array
    {
        $must = array_values(array_filter(array_map('strval', $req['mustHave'] ?? [])));
        $out = [];
        foreach ($candidates as $c) {
            $text = strtolower(json_encode($c, JSON_UNESCAPED_UNICODE) ?: '');
            $met = [];
            $gaps = [];
            foreach ($must as $m) {
                if ($m !== '' && str_contains($text, strtolower($m))) $met[] = ['requirement' => $m, 'evidence' => "\"$m\" appears in the profile"];
                else $gaps[] = $m;
            }
            $score = $must ? (int) round(100 * count($met) / count($must)) : 50;
            $risks = [];
            if (($req['location'] ?? '') !== '' && !str_contains($text, strtolower(explode(',', (string) $req['location'])[0]))) $risks[] = 'Location differs from the job location';
            $out[] = ['ref' => (string) ($c['ref'] ?? ''), 'score' => $score, 'met' => $met, 'gaps' => $gaps, 'risks' => $risks];
        }
        return ['results' => $out];
    }
}

final class Llms
{
    public const MONTHLY_LIMIT_DEFAULT = 50.0;

    /** Off by default (rule-based matching). Only ANTHROPIC_BACKEND = 'anthropic' (with a key) or 'mock' switches AI on. */
    public static function mode(array $c): string
    {
        $b = strtolower((string) ($c['ANTHROPIC_BACKEND'] ?? ''));
        if ($b === 'mock') return 'mock';
        return $b === 'anthropic' && ($c['ANTHROPIC_API_KEY'] ?? '') !== '' ? 'anthropic' : 'off';
    }

    public static function spendGuard(array $c): SpendGuard
    {
        $limit = ($c['ANTHROPIC_MONTHLY_LIMIT_EUR'] ?? '') !== '' ? (float) $c['ANTHROPIC_MONTHLY_LIMIT_EUR'] : self::MONTHLY_LIMIT_DEFAULT;
        $rate = ($c['ANTHROPIC_USD_TO_EUR'] ?? '') !== '' ? (float) $c['ANTHROPIC_USD_TO_EUR'] : 0.92;
        return new SpendGuard($c['STORAGE_DIR'] . '/anthropic-spend.json', $limit, $rate);
    }

    public static function build(array $c): ?Llm
    {
        return match (self::mode($c)) {
            'mock' => new MockLlm(self::spendGuard($c)),
            'anthropic' => new AnthropicLlm((string) $c['ANTHROPIC_API_KEY'], (string) ($c['ANTHROPIC_MODEL'] ?? ''), self::spendGuard($c)),
            default => null,
        };
    }
}
