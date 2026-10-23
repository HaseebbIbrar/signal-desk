<?php
// Rule-based JD matching: the parser on 5 sample JDs (EN/NL/FR), the 35/35/10/10/10 scorer with matched/missing
// items, rule-mode matching (no AI, names read live), and the "Deep match in Claude" prompt (IDs only, no PII).
declare(strict_types=1);

use SignalDesk\DeepPrompt;
use SignalDesk\JdDictionary;
use SignalDesk\JdMatcher;
use SignalDesk\JdParser;
use SignalDesk\RuleScorer;

function t_parse(string $jd): array
{
    return (new JdParser(JdDictionary::default()))->parse($jd);
}

const T_JDS = [
    'en-structured' => "Senior Cyber Security Architect (m/f/x) – freelance\n\nLocation: Brussels (hybrid)\n\nFor a NATO-related programme we look for an architect who designs zero trust and cloud security.\n\n"
        . "Requirements:\n- 7+ years in security architecture\n- Azure and Microsoft Sentinel\n- ISO 27001, NIS2\n- NATO SECRET security clearance\n\nNice to have:\n- CISSP or TOGAF\n- Splunk\n\n"
        . "Languages: English; Dutch or French is a plus.\nDay rate negotiable.",
    'en-plain' => "Data Engineer\n\nOur client, a logistics company in Antwerp, is growing its data platform. You will build pipelines in Databricks and Spark with Python and SQL, "
        . "orchestrate them with Airflow and model data for Power BI. You have 3 years of experience and speak Dutch and English. This is a permanent position with a company car.",
    'nl-profiel' => "Netwerkbeheerder (m/v/x)\n\nVoor een klant in Gent zoeken we een netwerkbeheerder voor het campusnetwerk en het datacenter.\n\nJouw profiel\n- Je hebt minimaal 5 jaar ervaring met Cisco en Fortinet firewalls\n"
        . "- Kennis van BGP, VPN en SD-WAN\n- CCNP is een pluspunt\n\nWat bieden wij?\n- Een vast contract met hospitalisatieverzekering\n- Opleidingen in Azure\n\nJe spreekt vlot Nederlands en Engels.",
    'nl-freelance' => ".NET Ontwikkelaar (freelance)\n\nOpdracht van 6 maanden in Leuven voor een overheidsdienst.\n\nVereisten:\n- C# en .NET Core\n- Azure DevOps en CI/CD\n- SQL Server\n\nBij voorkeur:\n- Blazor\n- Kennis van Scrum\n\n"
        . "Zelfstandige met dagtarief. Nederlands is vereist, Frans is een plus.",
    'fr-cdi' => "Ingénieur Cybersécurité (H/F)\n\nLieu de travail : Liège\n\nVous rejoignez le SOC d'un acteur public et vous gérez les incidents de sécurité.\n\nProfil recherché :\n- Expérience avec Splunk et QRadar\n"
        . "- Connaissance de la réponse aux incidents et de la gestion des risques\n- Habilitation de sécurité requise\n\nUn atout : certification CISSP ou GCIH.\n\nNous offrons un CDI. Français et anglais requis.",
];

test('JD parser on 5 sample JDs (EN / NL / FR): role, seniority, must/nice sections, languages, location, clearance, contract', function () {
    $a = t_parse(T_JDS['en-structured']);
    $r = $a['requirements'];
    eq(['Security Architect', 'senior', 7], [$r['role'], $r['seniority'], $r['minYears']]);
    eq(['security architecture', 'azure', 'sentinel', 'iso 27001', 'nis2', 'nato secret', 'security clearance'], $r['mustHave']);
    eq(['cissp', 'togaf', 'splunk', 'zero trust', 'cloud security'], $r['niceToHave'], 'nice section first, then skills mentioned elsewhere ("NATO-related" is not the skill NATO)');
    eq(['English', 'Dutch', 'French'], $r['languages']);
    eq(['Brussels, Belgium', 'Security clearance (NATO SECRET)', 'freelance', 'en', true], [$r['location'], $r['clearance'], $r['contractType'], $a['jdLanguage'], $a['sections']]);

    $b = t_parse(T_JDS['en-plain']);
    $r = $b['requirements'];
    eq(['Data Engineer', 'medior', 3], [$r['role'], $r['seniority'], $r['minYears']], 'no seniority word: 3 years -> medior');
    eq(['databricks', 'spark', 'python', 'sql', 'airflow', 'power bi'], $r['mustHave'], 'no sections: every skill is a must-have');
    eq([], $r['niceToHave']);
    eq([['Dutch', 'English'], 'Antwerp, Belgium', '', 'permanent', false], [$r['languages'], $r['location'], $r['clearance'], $r['contractType'], $b['sections']]);

    $c = t_parse(T_JDS['nl-profiel']);
    $r = $c['requirements'];
    eq(['Network Engineer', 'senior', 5, 'nl'], [$r['role'], $r['seniority'], $r['minYears'], $c['jdLanguage']]);
    eq(['cisco', 'fortinet', 'firewall', 'bgp', 'vpn', 'sd-wan'], $r['mustHave'], '"Jouw profiel" opens the must-haves');
    eq(['ccnp', 'datacenter'], $r['niceToHave'], '"is een pluspunt" marks a nice-to-have; "Wat bieden wij" (Azure training) is ignored');
    eq([['Dutch', 'English'], 'Ghent, Belgium', 'permanent'], [$r['languages'], $r['location'], $r['contractType']]);

    $d = t_parse(T_JDS['nl-freelance']);
    $r = $d['requirements'];
    eq(['.NET Developer', 'nl', 'freelance'], [$r['role'], $d['jdLanguage'], $r['contractType']]);
    eq(['c#', '.net', 'azure devops', 'ci/cd', 'sql server'], $r['mustHave']);
    eq(['blazor', 'scrum'], $r['niceToHave']);
    eq([['Dutch', 'French'], 'Leuven, Belgium'], [$r['languages'], $r['location']]);

    $plural = t_parse("Security Architect

Jouw profiel
- Azure en Sentinel

Pluspunten:
- CISSP
- Splunk

Atouts :
- TOGAF")['requirements'];
    eq([['azure', 'sentinel'], ['cissp', 'splunk', 'togaf']], [$plural['mustHave'], $plural['niceToHave']], '"Pluspunten" / "Atouts" headings (plurals)');

    $e = t_parse(T_JDS['fr-cdi']);
    $r = $e['requirements'];
    eq(['Security Engineer', 'fr', 'permanent'], [$r['role'], $e['jdLanguage'], $r['contractType']]);
    eq(['splunk', 'qradar', 'incident response', 'risk management', 'security clearance'], $r['mustHave'], '"Profil recherché" opens the must-haves');
    eq(['cissp', 'gcih', 'soc'], $r['niceToHave'], '"Un atout" marks nice-to-haves');
    eq([['French', 'English'], 'Liège, Belgium', 'Security clearance'], [$r['languages'], $r['location'], $r['clearance']]);
});

test('The JD dictionary: your additions in storage/jd-dictionary.json are merged into the default (which can keep improving); "remove" switches entries off', function () {
    $dir = tmpdir();
    $l = JdDictionary::load($dir);
    ok(is_file("$dir/jd-dictionary.json"), 'an additions file is created on first use');
    ok(filesize("$dir/jd-dictionary.json") < 2000, 'it holds your additions only, not a copy of the default');
    eq(null, $l['error']);
    ok(count($l['dict']['skills']) >= 300, 'at least 300 skills and certifications');
    ok(!isset($l['dict']['skills']['example skill (delete me)']), 'the template examples are ignored');
    file_put_contents("$dir/jd-dictionary.json", json_encode([
        'titles' => ['Bakery Architect' => ['synonyms' => ['bakkerij-architect']], 'Data Engineer' => ['synonyms' => ['datapijplijnbouwer']]],
        'skills' => ['sourdough' => ['aliases' => ['zuurdesem'], 'category' => 'dev']],
        'remove' => ['skills' => ['go']],
    ]));
    $d = JdDictionary::load($dir)['dict'];
    ok(in_array('data engineer', $d['titles']['Data Engineer']['synonyms'], true) && in_array('datapijplijnbouwer', $d['titles']['Data Engineer']['synonyms'], true), 'synonym lists are combined');
    ok(!isset($d['skills']['go']) && isset($d['skills']['python']), 'removed: go; the rest of the default stays');
    $r = (new JdParser($d))->parse("Bakkerij-architect\n\nWe zoeken iemand met ervaring in zuurdesem, Go en Python voor onze vestiging in Mechelen. Een vast contract.")['requirements'];
    eq(['Bakery Architect', ['sourdough', 'python'], 'Antwerp, Belgium'], [$r['role'], $r['mustHave'], $r['location']], 'edits apply; Mechelen is in the Antwerp area');
    file_put_contents("$dir/jd-dictionary.json", '{ broken');
    $bad = JdDictionary::load($dir);
    ok(str_contains((string) $bad['error'], 'not valid JSON'));
    ok(count($bad['dict']['skills']) >= 300, 'falls back to the default');
});

test('Scoring 0-100: title 35, must-haves 35, nice-to-haves 10, seniority 10, location 10; matched and missing items', function () {
    $req = JdMatcher::requirements(['role' => 'Security Architect', 'seniority' => 'senior', 'mustHave' => ['Azure', 'Sentinel', 'ISO 27001', 'NIS2'],
        'niceToHave' => ['CISSP', 'Splunk'], 'location' => 'Brussels, Belgium']);
    $s = new RuleScorer($req, JdDictionary::default(), ['senior', 'junior']);
    $perfect = ['title' => 'Senior Cybersecurity Architect', 'normalisedTitle' => 'cybersecurity architect', 'pastTitles' => [],
        'skills' => ['azure', 'sentinel', 'iso 27001', 'nis2', 'cissp', 'splunk'], 'city' => 'Evere', 'country' => 'Belgium'];
    $r = $s->score($perfect);
    eq(['score' => 100, 'parts' => ['title' => 35, 'must' => 35, 'nice' => 10, 'seniority' => 10, 'location' => 10]], array_intersect_key($r, ['score' => 1, 'parts' => 1]), 'a French/NL synonym title and a Brussels commune count fully');
    eq([], $r['missing']);
    $partial = ['title' => 'Junior Cloud Architect', 'normalisedTitle' => 'cloud architect', 'pastTitles' => ['IT Consultant'],
        'skills' => ['azure', 'sentinel'], 'city' => 'Mechelen', 'country' => 'Belgium'];
    $r = $s->score($partial);
    eq(['title' => 13, 'must' => 18, 'nice' => 0, 'seniority' => 3, 'location' => 5], $r['parts']);
    eq(39, $r['score']);
    eq(['similar title: Junior Cloud Architect', 'Azure', 'Sentinel'], $r['matched']);
    eq(['ISO 27001', 'NIS2', 'nice to have: CISSP', 'nice to have: Splunk', 'seniority: junior (job: senior)', 'location: Mechelen, Belgium (job: Brussels)'], $r['missing']);
    $past = $s->score(['title' => 'IT Consultant', 'normalisedTitle' => 'it consultant', 'pastTitles' => ['Security Architect'], 'skills' => [], 'city' => 'Lille', 'country' => 'France']);
    eq(['title' => 20, 'location' => 0], array_intersect_key($past['parts'], ['title' => 1, 'location' => 1]), 'a past title counts 20 of 35');
    ok(in_array('past title: Security Architect', $past['matched'], true));
    $open = new RuleScorer(JdMatcher::requirements(['role' => 'Security Architect']), JdDictionary::default());
    eq(['title' => 35, 'must' => 18, 'nice' => 5, 'seniority' => 5, 'location' => 5], $open->score($perfect)['parts'], 'nothing asked: half points, nothing missing');
});

test('Rule-based matching (default, no AI): pool ranked, top 50, 25 per page with names read live; "Deep match in Claude" prompt has IDs only', function () {
    [$jd, , $src] = t_matcher(false);
    $jdText = T_JDS['en-structured'] . "\nContact our recruiter Jane Recruiter at jane.recruiter@agency.example or +32 470 11 22 33, https://agency.example/apply";
    $req = $jd->parse($jdText, 'jp')['requirements'];
    $r = $jd->start($req, 'Senior Security Architect', 'A2 - Security Architecture', '', 'jp', $jdText);
    eq(['rules', ['Cyber Security']], [$r['engine'], $r['pools']]);
    ok($r['ranked'] > 25 && $r['ranked'] <= JdMatcher::RESULTS);
    eq(25, count($r['results']));
    eq((int) ceil($r['ranked'] / 25), $r['totalPages']);
    $top = $r['results'][0];
    eq(1, $top['rank']);
    ok(str_starts_with($top['name'], 'Example Person'), 'names on screen, read live');
    ok(str_contains($top['role'], 'Security Architect'));
    eq(['title', 'must', 'nice', 'seniority', 'location'], array_keys($top['parts']));
    eq($top['score'], array_sum($top['parts']) <= 100 ? $top['score'] : -1);
    ok($top['matched'] && $top['missing'], 'matched and missing items per candidate');
    $scores = array_column($r['results'], 'score');
    $sorted = $scores;
    rsort($sorted);
    eq($sorted, $scores);
    $p2 = $jd->page($r['matchId'], 2);
    eq(26, $p2['results'][0]['rank']);
    ok(!array_intersect(array_column($r['results'], 'id'), array_column($p2['results'], 'id')));

    $prompt = $r['deepPrompt'];
    $ids = array_slice($_SESSION['jd'][$r['matchId']]['ids'], 0, 20);
    eq(20, count($ids));
    foreach ($ids as $i => $id) ok(str_contains($prompt, ($i + 1) . ". $id (rule score "), "ID $i listed in rank order");
    foreach (['getCandidateProfile', 'searchPeople', 'Pool: Cyber Security', 'Must have: security architecture, azure, sentinel', 'NATO-related programme', 'read-only'] as $want) ok(str_contains($prompt, $want), "prompt mentions $want");
    foreach (['Example Person', 'Example Company', '@mail.example', 'jane.recruiter@agency.example', '+32 470', 'https://agency.example'] as $pii) ok(!str_contains($prompt, $pii), "prompt contains \"$pii\"");
    ok(str_contains($prompt, '[email]') && str_contains($prompt, '[phone]') && str_contains($prompt, '[link]'), 'contact details in the JD are masked');
    eq(0, count(array_filter($GLOBALS['__events'], fn ($e) => $e['type'] === 'llm_call')), 'no AI call');
    eq(20, $jd->dailyLimit()->remaining('jp'), 'rule-based matching does not use the daily AI limit');
});

test('Deep-match prompt: built from requirements, pool and IDs only; long JDs are cut', function () {
    $p = DeepPrompt::build(str_repeat('Azure architecture. ', 2000), JdMatcher::requirements(['role' => 'Cloud Architect', 'minYears' => 8]), [], [['11111111-1111-4111-a111-111111111111', 80]]);
    ok(str_contains($p, "1. 11111111-1111-4111-a111-111111111111 (rule score 80)"));
    ok(str_contains($p, 'Pool: all pools') && str_contains($p, '(8+ years)'));
    ok(mb_strlen($p) < DeepPrompt::MAX_JD + 3000);
});
