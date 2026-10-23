<?php
// JD matching: parsing (fake AI), stage-1 shortlist, stage-2 scoring with the 24 h cache, daily and monthly limits,
// no personal data on disk or in the AI prompt, the Anthropic request, job-page fetching (blocked -> paste), CV text.
declare(strict_types=1);

use SignalDesk\AnthropicLlm;
use SignalDesk\AppError;
use SignalDesk\Config;
use SignalDesk\ConfigError;
use SignalDesk\CvFetcher;
use SignalDesk\CvText;
use SignalDesk\DailyLimit;
use SignalDesk\Health;
use SignalDesk\JdMatcher;
use SignalDesk\JobPageFetcher;
use SignalDesk\Llm;
use SignalDesk\MockAtlas;
use SignalDesk\MockLlm;
use SignalDesk\SpendGuard;
use SignalDesk\ValidationError;

const T_JD = "Senior Security Architect (m/f/x)\n\nFor a large public-sector client in Brussels we are looking for a senior security architect. "
    . "You design the security architecture of a hybrid Azure landscape, own the Sentinel SIEM roadmap and guide ISO 27001 and NIS2 compliance.\n"
    . "Must have: Azure, Sentinel, ISO 27001. Nice to have: Splunk, CISSP. Languages: Dutch and English, French is a plus. Permanent contract.";

/** Records every prompt sent to the AI (to prove what leaves the server), answers like MockLlm. */
final class SpyLlm implements Llm
{
    public array $prompts = [];
    public function __construct(private MockLlm $inner = new MockLlm()) {}
    public function json(string $purpose, string $system, string $user, array $schema, int $maxTokens, string $effort): array
    {
        $this->prompts[] = [$purpose, $user, $schema, $effort];
        return $this->inner->json($purpose, $system, $user, $schema, $maxTokens, $effort);
    }
    public function ping(): array { return ['OK', 'spy']; }
    public function model(): string { return 'spy'; }
}

/** Matcher on a built mock index; CVs come from MockAtlas::cvText (in memory). $llm false = rule-based (the default). */
function t_matcher(Llm|false|null $llm = null): array
{
    [$src, $mock, $dir] = t_pool_source();
    t_build_index($src);
    $cv = new CvFetcher();
    $cv->transport = fn (string $url) => ['status' => 200, 'type' => 'text/plain', 'body' => MockAtlas::cvText($url) . "\nContact: jane.doe@mail.example, +32 470 12 34 56"];
    $llm ??= new SpyLlm();
    $_SESSION['jd'] = [];
    return [new JdMatcher($src, $llm ?: null, $dir, $cv), $llm, $src, $dir, $mock];
}

function t_zip(array $files): string
{
    $local = '';
    $cd = '';
    foreach ($files as $name => $data) {
        $comp = gzdeflate($data);
        $crc = crc32($data);
        $off = strlen($local);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, strlen($comp), strlen($data), strlen($name), 0) . $name . $comp;
        $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 0, $off) . $name;
    }
    return $local . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($cd), strlen($local), 0);
}

test('JD parse: rule-based by default (no AI call); with AI switched on the AI parses; edited requirements are cleaned', function () {
    [$rules] = t_matcher(false);
    $r = $rules->parse(T_JD, 'jp@swooscale.test');
    eq(['rules', 'en'], [$r['engine'], $r['jdLanguage']]);
    eq(['role', 'seniority', 'minYears', 'mustHave', 'niceToHave', 'languages', 'location', 'clearance', 'contractType'], array_keys($r['requirements']));
    eq('Security Architect', $r['requirements']['role']);
    eq(['azure', 'sentinel', 'iso 27001'], $r['requirements']['mustHave']);
    [$jd, $llm] = t_matcher();
    $r = $jd->parse(T_JD, 'jp@swooscale.test');
    eq('ai', $r['engine']);
    eq('Senior Security Architect (m/f/x)', $r['requirements']['role']);
    eq(['jd_parse', 'low'], [$llm->prompts[0][0], $llm->prompts[0][3]]);
    eq(JdMatcher::PARSE_SCHEMA, $llm->prompts[0][2]);
    throws(fn () => $jd->parse('Security architect wanted', 'jp'), ValidationError::class, 'full job description');
    throws(fn () => $jd->parse(str_repeat('x', 30001), 'jp'), ValidationError::class, 'too long');
    $edited = JdMatcher::requirements(['role' => "  Security   Architect ", 'mustHave' => "Azure\nSentinel, azure\n\n", 'languages' => ['Dutch', 5, ''], 'minYears' => '7', 'evil' => 'x']);
    eq(['role' => 'Security Architect', 'seniority' => '', 'minYears' => 7, 'mustHave' => ['Azure', 'Sentinel'], 'niceToHave' => [], 'languages' => ['Dutch'],
        'location' => '', 'clearance' => '', 'contractType' => ''], $edited);
});

test('AI mode (switched on): the rule-based ranking feeds a 40-person shortlist; one of 20 daily matches', function () {
    [$jd, $llm, $src, $dir] = t_matcher();
    $r = $jd->start(['role' => 'Security Architect', 'mustHave' => ['Azure', 'Sentinel']], 'Senior Security Architect', 'A2 - Security Architecture', '', 'jp@swooscale.test');
    eq(['ai', ['Cyber Security']], [$r['engine'], $r['pools']]);
    ok($r['shortlisted'] > 0 && $r['shortlisted'] <= JdMatcher::SHORTLIST);
    eq((int) ceil($r['shortlisted'] / JdMatcher::BATCH), $r['batches']);
    eq(19, $r['remainingToday']);
    $m = $_SESSION['jd'][$r['matchId']];
    eq(['jdHash', 'req', 'ids', 'stage1', 'details', 'at'], array_keys($m), 'the session keeps ids, scores and requirements only');
    $scores = array_values($m['stage1']);
    $sorted = $scores;
    rsort($sorted);
    eq($sorted, $scores, 'highest rule score first');
    $first = array_values(array_filter($src->index()->entries, fn ($e) => $e['personId'] === $m['ids'][0]))[0];
    eq('security architect', $first['normalisedTitle']);
    eq([], $llm->prompts, 'ranking uses no AI');
});

test('Daily limit: 20 JD matches per user per day, then refused; resets the next day; per user', function () {
    $lim = new DailyLimit(tmpdir() . '/jd-usage.json', 20);
    $now = 1_900_000_000;
    $lim->now = function () use (&$now) { return $now; };
    for ($i = 0; $i < 20; $i++) $lim->take('JP@swooscale.test');
    $e = throws(fn () => $lim->take('jp@swooscale.test'), AppError::class, "today's 20");
    eq('DAILY_LIMIT', $e->errCode);
    eq(19, $lim->take('other@swooscale.test'));
    $now += 86400;
    eq(19, $lim->take('jp@swooscale.test'));

    [$jd] = t_matcher();
    $usage = tmpdir();
    for ($i = 0; $i < 20; $i++) $jd->dailyLimit()->take('jp');
    throws(fn () => $jd->start(['role' => 'SOC Analyst'], 'SOC Analyst', '', '', 'jp'), AppError::class, "today's 20");
});

test('Stage 2: batches scored by the AI with met/gaps/risks, ranked; cached 24 h (score, met, gaps only); no personal data in the prompt or on disk', function () {
    [$jd, $llm, $src, $dir] = t_matcher();
    $start = $jd->start(['role' => 'Security Architect', 'mustHave' => ['Azure', 'Sentinel', 'ISO 27001'], 'location' => 'Brussels'], 'Security Architect', '', '', 'jp');
    $b0 = $jd->batch($start['matchId'], 0, 'jp')['results'];
    eq(JdMatcher::BATCH, count($b0));
    $scores = array_column($b0, 'score');
    $sorted = $scores;
    rsort($sorted);
    eq($sorted, $scores);
    $top = $b0[0];
    ok(str_starts_with($top['name'], 'Example Person'), 'names are shown on screen (fetched live)');
    ok($top['met'] && isset($top['met'][0]['requirement'], $top['met'][0]['evidence']));
    eq('https://my.recruitwithatlas.com/people-v2?profile=' . $top['id'], $top['profileUrl']);
    eq(1, count($llm->prompts));
    [$purpose, $prompt, $schema, $effort] = $llm->prompts[0];
    eq(['jd_score', 'medium'], [$purpose, $effort]);
    eq(JdMatcher::SCORE_SCHEMA, $schema);
    foreach (['Example Person', '@mail.example', '+32 470', 'Secret note', 'expectedSalary', 'firstName', 'lastName', 'identities'] as $pii) {
        ok(!str_contains($prompt, $pii), "AI prompt contains \"$pii\"");
    }
    ok(str_contains($prompt, '[email]') && str_contains($prompt, '[phone]'), 'contact details in CV text are masked');
    ok(str_contains($prompt, 'Curriculum vitae'), 'CV text is included (in memory)');

    // the same JD again: scores come from the cache, no new AI call
    $again = $jd->start(['role' => 'Security Architect', 'mustHave' => ['Azure', 'Sentinel', 'ISO 27001'], 'location' => 'Brussels'], 'Security Architect', '', '', 'jp');
    $b0b = $jd->batch($again['matchId'], 0, 'jp')['results'];
    eq(1, count($llm->prompts));
    eq(array_column($b0, 'score', 'id'), array_column($b0b, 'score', 'id'));
    ok($b0b[0]['cached']);

    $cache = json_decode((string) file_get_contents("$dir/jd-score-cache.json"), true);
    foreach ($cache as $e) eq(['jdHash', 'personId', 'score', 'met', 'gaps', 't'], array_keys($e));
    foreach (glob("$dir/{,*/}*", GLOB_BRACE) as $f) {
        if (!is_file($f)) continue;
        $raw = (string) file_get_contents($f);
        foreach (['Example Person', '@mail.example', '+32 470', 'Secret note', 'Curriculum vitae', 'Hands-on with'] as $pii) ok(!str_contains($raw, $pii), basename($f) . " contains \"$pii\"");
    }
    $log = json_encode($GLOBALS['__events']);
    ok(!str_contains($log, 'Example Person') && !str_contains($log, 'Curriculum'), 'audit log has no personal data');
    throws(fn () => $jd->batch('nope', 0, 'jp'), \SignalDesk\NotFoundError::class, 'expired');
});

test('Monthly spend guard: refused before the call once the budget would be passed; resets next month', function () {
    $g = new SpendGuard(tmpdir() . '/spend.json', 0.025);
    $now = gmmktime(12, 0, 0, 10, 30, 2026);
    $g->now = function () use (&$now) { return $now; };
    $llm = new MockLlm($g, 0.01);
    $llm->json('jd_parse', 's', '<data>{"jobDescription":"x"}</data>', [], 100, 'low');
    $llm->json('jd_parse', 's', '<data>{"jobDescription":"x"}</data>', [], 100, 'low');
    $e = throws(fn () => $llm->json('jd_parse', 's', '<data>{}</data>', [], 100, 'low'), AppError::class, 'monthly AI budget');
    eq('SPEND_LIMIT', $e->errCode);
    eq(2, $llm->calls);
    eq(0.02, round($g->spent(), 4));
    $now = gmmktime(12, 0, 0, 11, 1, 2026);
    eq(0.0, $g->spent());
    $llm->json('jd_parse', 's', '<data>{}</data>', [], 100, 'low');
    eq(3, $llm->calls);
});

test('Anthropic request: key header, API version, JSON-schema output, model; cost recorded; refusal/401 handled; prompt never logged', function () {
    $g = new SpendGuard(tmpdir() . '/spend.json', 50, 0.9);
    $llm = new AnthropicLlm('sk-test', 'claude-opus-5-5', $g);
    $llm->sleep = fn () => null;
    $sent = [];
    $answers = [
        ['status' => 400, 'body' => '{"type":"error","error":{"type":"invalid_request_error","message":"fallbacks: not enabled for this organization"}}'],
        ['status' => 200, 'body' => json_encode(['model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => '{"role":"Security Architect"}']]])],
    ];
    $llm->transport = function (string $method, string $url, array $headers, ?string $body) use (&$sent, &$answers) {
        $sent[] = [$method, $url, $headers, json_decode((string) $body, true)];
        return array_shift($answers);
    };
    $r = $llm->json('jd_parse', 'SYSTEM', 'SECRET JD TEXT', ['type' => 'object'], 8000, 'low');
    eq(['role' => 'Security Architect'], $r['data']);
    [$m, $url, $h, $body] = $sent[0];
    eq(['POST', 'https://api.anthropic.com/v1/messages'], [$m, $url]);
    ok(in_array('x-api-key: sk-test', $h, true) && in_array('anthropic-version: 2026-10-21', $h, true));
    eq('claude-opus-5-5', $body['model']);
    eq(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => ['type' => 'object']]], $body['output_config']);
    eq('default', $body['fallbacks']);
    ok(!isset($sent[1][3]['fallbacks']) && !in_array('anthropic-beta: server-side-fallback-2026-07-01', $sent[1][2], true), 'retried without fallbacks');
    eq(round((1000 * 4 + 500 * 20) / 1e6 * 0.9, 6), round($g->spent(), 6), 'cost: Opus 5.5 $4/$20 per MTok, in EUR');
    ok(!str_contains(json_encode($GLOBALS['__events']), 'SECRET JD TEXT'), 'audit log has no prompt text');

    $llm->transport = fn () => ['status' => 200, 'body' => json_encode(['model' => 'claude-opus-5-5', 'stop_reason' => 'refusal', 'usage' => ['input_tokens' => 10, 'output_tokens' => 0], 'content' => []])];
    eq('LLM_REFUSED', throws(fn () => $llm->json('jd_parse', 's', 'u', [], 100, 'low'), AppError::class)->errCode);
    $llm->transport = fn () => ['status' => 401, 'body' => '{"error":{"type":"authentication_error","message":"bad key"}}'];
    throws(fn () => $llm->json('jd_parse', 's', 'u', [], 100, 'low'), \SignalDesk\UpstreamError::class, 'ANTHROPIC_API_KEY');
    $big = new AnthropicLlm('k', 'claude-opus-5-5', new SpendGuard(tmpdir() . '/s.json', 0.001));
    $big->transport = function () { fail('must not call the API over budget'); };
    throws(fn () => $big->json('jd_score', 's', str_repeat('x', 100000), [], 16000, 'medium'), AppError::class, 'monthly AI budget');
});

test('Job page: https public pages only; LinkedIn/Indeed, private addresses, http and empty pages fall back to "paste the JD"', function () {
    $f = new JobPageFetcher();
    $f->resolve = fn (string $host) => match ($host) { 'intranet.example.com' => ['10.0.0.5'], 'rebind.example.com' => ['93.184.216.34', '127.0.0.1'], default => ['93.184.216.34'] };
    $pages = [
        'https://jobs.example.com/a' => ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => '<html><head><title>x</title><script>var a=1</script></head><body><nav>Menu</nav><h1>Security Architect</h1><p>' . str_repeat('You design secure Azure platforms for our clients. ', 10) . '</p></body></html>'],
        'https://jobs.example.com/ld' => ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<script type="application/ld+json">' . json_encode(['@type' => 'JobPosting', 'title' => 'Data Engineer', 'description' => '<p>' . str_repeat('Build Databricks pipelines in Azure. ', 12) . '</p>']) . '</script><body>cookie banner</body>'],
        'https://jobs.example.com/short' => ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<p>Apply now</p>'],
        'https://jobs.example.com/redirect' => ['status' => 302, 'headers' => ['Location' => 'https://intranet.example.com/x'], 'body' => ''],
        'https://jobs.example.com/pdf' => ['status' => 200, 'headers' => ['content-type' => 'application/pdf'], 'body' => '%PDF'],
    ];
    $seen = [];
    $f->transport = function (string $url, string $host, string $ip) use ($pages, &$seen) { $seen[] = [$url, $ip]; return $pages[$url] ?? ['status' => 404, 'headers' => [], 'body' => '']; };
    $a = $f->fetch('https://jobs.example.com/a');
    ok($a['ok']);
    ok(str_starts_with($a['text'], "Security Architect\nYou design"), 'menus, scripts and head stripped');
    eq(['https://jobs.example.com/a', '93.184.216.34'], $seen[0], 'connects to the checked IP');
    $ld = $f->fetch('https://jobs.example.com/ld');
    ok(str_starts_with($ld['text'], "Data Engineer\n\nBuild Databricks"), 'JSON-LD JobPosting preferred');
    foreach ([
        'https://www.linkedin.com/jobs/view/123' => 'blocks automated reading', 'https://be.indeed.com/viewjob?jk=1' => 'blocks automated reading',
        'http://jobs.example.com/a' => 'Only https', 'https://intranet.example.com/x' => 'public website', 'https://rebind.example.com/' => 'public website',
        'https://127.0.0.1/' => 'cannot be read', 'https://jobs.example.com:8443/a' => 'Only https', 'https://jobs.example.com/short' => 'No readable',
        'https://jobs.example.com/redirect' => 'public website', 'https://jobs.example.com/pdf' => 'not a web page', 'https://jobs.example.com/404' => 'HTTP 404',
    ] as $url => $why) {
        $r = $f->fetch($url);
        ok(!$r['ok'] && str_contains($r['reason'], $why), "$url: " . ($r['reason'] ?? 'ok'));
    }
    eq(['https://jobs.example.com/a', 'https://jobs.example.com/ld', 'https://jobs.example.com/short', 'https://jobs.example.com/redirect', 'https://jobs.example.com/pdf', 'https://jobs.example.com/404'],
        array_column($seen, 0), 'blocked URLs (and the private redirect target) never reach the network');
    ok(JobPageFetcher::publicIp('93.184.216.34') && !JobPageFetcher::publicIp('192.168.1.1') && !JobPageFetcher::publicIp('100.64.1.1') && !JobPageFetcher::publicIp('169.254.169.254'));
});

test('CV text in memory: DOCX, PDF (Flate), plain text; unreadable files give nothing', function () {
    $body = str_repeat('<w:p><w:r><w:t>Senior security engineer with Azure and Sentinel experience.</w:t></w:r></w:p>', 6);
    $docx = t_zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => "<w:document><w:body>$body</w:body></w:document>"]);
    $t = CvText::extract($docx);
    ok(str_starts_with($t, 'Senior security engineer with Azure'), $t);
    eq(6, substr_count($t, "\n") + 1);
    $stream = gzcompress('BT /F1 11 Tf 72 720 Td (Curriculum vitae) Tj T* [(Senior) -300 (security) -300 (architect)] TJ T* ' . str_repeat('(ISO 27001 and NIS2 programmes for public sector clients\\051) Tj T* ', 5) . 'ET');
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Length " . strlen($stream) . " /Filter /FlateDecode >>\nstream\n$stream\nendstream\nendobj\n%%EOF";
    $p = CvText::extract($pdf);
    ok(str_starts_with($p, "Curriculum vitae\nSenior security architect\nISO 27001"), $p);
    ok(str_contains($p, 'clients)'), 'escaped characters decoded');
    eq('', CvText::extract(random_bytes(3000)), 'binary noise');
    eq('', CvText::extract("%PDF-1.4\nnothing here"), 'no text');
    ok(str_starts_with(CvText::extract(str_repeat('Plain text CV line. ', 20), 'cv.txt'), 'Plain text CV'));
    ok(mb_strlen(CvText::extract(str_repeat('Long CV text. ', 2000), 'cv.txt')) <= CvText::MAX_CHARS);
    eq(null, CvText::zipEntry('not a zip', 'word/document.xml'));
});

test('AI health and config: off by default ("AI scoring: off (rule-based)"); mock/on; key never shown; mock refused in production', function () {
    $base = ['APP_ENV' => 'development', 'WORKBOOK_BACKEND' => 'mock', 'APP_ORIGIN' => '', 'TIMEZONE' => '', 'AZURE_TENANT_ID' => '', 'AZURE_CLIENT_ID' => '',
        'AZURE_CLIENT_SECRET' => '', 'GRAPH_DRIVE_ID' => '', 'GRAPH_MASTER_ITEM_ID' => '', 'GRAPH_DEV_ITEM_ID' => '',
        'AUTH_USERS' => 'a@x.test|A|' . base64_encode(password_hash('long enough pass', PASSWORD_BCRYPT, ['cost' => 4])), 'STORAGE_DIR' => tmpdir()];
    eq([['AI scoring', 'OK', 'off (rule-based)']], Health::aiChecks(Config::validate($base, tmpdir())));
    eq('off', SignalDesk\Llms::mode(Config::validate($base + ['ANTHROPIC_API_KEY' => 'sk-x'], tmpdir())), 'a key alone does not switch AI on');
    $c = Config::validate($base + ['ANTHROPIC_BACKEND' => 'mock'], tmpdir());
    $h = Health::aiChecks($c);
    eq(['AI scoring', 'Anthropic API reachable', 'AI spend this month'], array_column($h, 0));
    eq(['OK', 'OK', 'OK'], array_column($h, 1));
    ok(str_contains($h[2][2], '€0.00 of €50.00'));
    $live = Config::validate($base + ['ANTHROPIC_BACKEND' => 'anthropic', 'ANTHROPIC_API_KEY' => 'sk-secret-123', 'ANTHROPIC_MONTHLY_LIMIT_EUR' => '20'], tmpdir());
    $fake = new AnthropicLlm('sk-secret-123', '', SignalDesk\Llms::spendGuard($live));
    $fake->transport = fn ($m, $u) => ['status' => str_ends_with($u, '/claude-opus-5-5') ? 200 : 404, 'body' => '{}'];
    $rows = Health::aiChecks($live, $fake);
    eq([['AI scoring', 'OK'], ['ANTHROPIC_API_KEY', 'OK'], ['Anthropic API reachable', 'OK'], ['AI spend this month', 'OK']], array_map(fn ($r) => [$r[0], $r[1]], $rows));
    ok(!str_contains(json_encode($rows), 'sk-secret'), 'the key is never shown');
    ok(str_contains($rows[3][2], '€20.00'));
    throws(fn () => Config::validate(['APP_ORIGIN' => 'https://x.test', 'APP_ENV' => 'production', 'WORKBOOK_BACKEND' => 'graph', 'ANTHROPIC_BACKEND' => 'mock'] + $base, tmpdir()), ConfigError::class, 'ANTHROPIC_BACKEND=mock');
    throws(fn () => Config::validate($base + ['ANTHROPIC_MODEL' => 'gpt-5'], tmpdir()), ConfigError::class, 'ANTHROPIC_MODEL');
    throws(fn () => Config::validate($base + ['ANTHROPIC_MONTHLY_LIMIT_EUR' => 'lots'], tmpdir()), ConfigError::class, 'number');
});
