<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;
use OpenAI;
use Throwable;

/**
 * Independent GPT-4o "Master" pass: elegance + test sharpness vs master_wisdom.jsonl.
 */
final class EvolutionMasterOpinionService
{
    public const LAST_OPINION_FILE = 'storage/evolution/master_last_opinion.json';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, mixed>|null $phpunitBlock suggested_changes[].phpunit_test
     * @return array{
     *   ok: bool,
     *   master_score: float,
     *   master_verdict: string,
     *   wisdom_quote_id: string,
     *   test_elegance_score: float,
     *   test_audit_notes: string,
     *   approved: bool,
     *   toolbox?: array<string, mixed>,
     *   node_audit?: array<string, mixed>,
     *   error?: string
     * }
     */
    public function evaluatePhpAndTests(
        string $php,
        ?array $phpunitBlock,
        Config $config,
        ?string $fqcn = null,
        ?string $pageAuditUrl = null
    ): array {
        $mm = $config->get('evolution.master_mentor', []);
        if (!is_array($mm) || !filter_var($mm['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return [
                'ok' => true,
                'master_score' => 10.0,
                'master_verdict' => '(master_mentor disabled)',
                'wisdom_quote_id' => '',
                'test_elegance_score' => 10.0,
                'test_audit_notes' => '',
                'approved' => true,
            ];
        }

        $testSnippet = '';
        if (is_array($phpunitBlock)) {
            $testSnippet = trim((string) ($phpunitBlock['file_contents'] ?? ''));
            if ($testSnippet === '' && isset($phpunitBlock['class_name'])) {
                $testSnippet = '(phpunit_test block present but file_contents empty)';
            }
        } else {
            $testSnippet = '(geen phpunit_test — beoordeel alleen productiecode; geef lagere test_elegance_score)';
        }

        $toolbox = MasterToolboxService::analyzePhpPatch($config, $php, $testSnippet, $fqcn);
        if (($toolbox['enabled'] ?? false) === true) {
            MasterToolboxService::recordToolboxMilestoneIfNeeded($this->container);
            MasterToolboxService::recordLeanToolboxMilestoneIfNeeded($this->container);
        }
        if (($toolbox['toolbox_blocks_apply'] ?? false) === true) {
            $verdict = 'Master Toolbox (pre-API): ' . implode('; ', $toolbox['violations'] ?? ['violation']);
            MasterReflectionService::append($config, $verdict, ['blocked' => true, 'fqcn' => $fqcn]);
            $blocked = [
                'ok' => true,
                'master_score' => 1.0,
                'master_verdict' => $verdict,
                'wisdom_quote_id' => 'kiss-1',
                'test_elegance_score' => 1.0,
                'test_audit_notes' => 'Blocked before LLM — zie toolbox.',
                'approved' => false,
                'toolbox' => $toolbox,
            ];
            foreach ($toolbox['violations'] ?? [] as $v) {
                if (str_contains((string) $v, 'type_integrity')) {
                    NeuroTemporalBridgeService::recordLevel8MilestoneIfNeeded($this->container);
                    break;
                }
            }
            self::persistLastOpinion($blocked);
            self::appendHallOfWisdom($blocked);

            return $blocked;
        }

        $nodeAudit = [];
        if (is_string($pageAuditUrl) && trim($pageAuditUrl) !== '') {
            $nodeAudit = MasterToolboxService::nodeMasterAudit($config, trim($pageAuditUrl));
            if (($nodeAudit['ok'] ?? false)) {
                MasterToolboxService::recordNodeBridgeMilestoneIfNeeded($this->container);
            }
            $nodeAudit = self::applyLighthouseThresholds($config, $nodeAudit);
        }

        $openaiKey = EvolutionProviderKeys::openAi($config, true);
        if ($openaiKey === '') {
            return [
                'ok' => false,
                'master_score' => 0.0,
                'master_verdict' => 'Geen OpenAI key — Master-check overgeslagen.',
                'wisdom_quote_id' => '',
                'test_elegance_score' => 0.0,
                'test_audit_notes' => '',
                'approved' => false,
                'toolbox' => $toolbox,
                'node_audit' => $nodeAudit,
                'error' => 'Missing OpenAI API key (evolution / ai / assistant / env)',
            ];
        }

        $model = (string) ($mm['model'] ?? 'gpt-4o');
        $minScore = max(1.0, min(10.0, (float) ($mm['min_score'] ?? 7.0)));

        $wisdom = EvolutionMentorService::wisdomExcerptForApi(6500);
        $system = <<<SYS
You are the Grandmaster code mentor. You apply principles from the WISDOM corpus (ids matter).
Evaluate PHP patch AND PHPUnit snippet for:
- Elegance: DRY, KISS, SOLID, readable names, shallow nesting.
- Test sharpness: edge cases, failure paths, meaningful assertions — not only happy path.
You receive MASTER_TOOLBOX_JSON: deterministic metrics (complexity, nesting, jit hints, test saboteur). Respect major toolbox failures already filtered; use metrics in your verdict.

Output a single JSON object (no markdown):
{
  "master_score": 1-10,
  "test_elegance_score": 1-10,
  "approved": true|false,
  "master_verdict": "one or two sentences, may reference a principle",
  "wisdom_quote_id": "id from corpus that best matches your critique, or empty string",
  "test_audit_notes": "short: what is missing in tests if any"
}
approved must be true only if master_score >= {$minScore} AND test_elegance_score >= {$minScore} (when tests are required by severity; if no tests, still penalize test_elegance_score).
Be strict but fair — 7 means "ship with minor nits", below 7 means reject.
SYS;

        $tbJson = json_encode($toolbox, JSON_UNESCAPED_UNICODE);
        $naJson = json_encode($nodeAudit, JSON_UNESCAPED_UNICODE);
        $user = "WISDOM_CORPUS:\n{$wisdom}\n\nMASTER_TOOLBOX_JSON:\n{$tbJson}\n\nNODE_VISUAL_AUDIT_JSON:\n{$naJson}\n\n--- PHP_PATCH ---\n" . mb_substr($php, 0, 42000)
            . "\n\n--- PHPUNIT_SNIPPET ---\n" . mb_substr($testSnippet, 0, 22000);

        $client = OpenAI::factory()->withApiKey($openaiKey)->make();
        try {
            $resp = $client->chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'max_tokens' => 900,
                'response_format' => ['type' => 'json_object'],
            ]);
            $text = trim((string) ($resp->choices[0]->message->content ?? ''));
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'master_score' => 0.0,
                'master_verdict' => 'Master API error: ' . $e->getMessage(),
                'wisdom_quote_id' => '',
                'test_elegance_score' => 0.0,
                'test_audit_notes' => '',
                'approved' => false,
                'toolbox' => $toolbox,
                'node_audit' => $nodeAudit,
                'error' => $e->getMessage(),
            ];
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'master_score' => 0.0,
                'master_verdict' => 'Ongeldig Master-antwoord (geen JSON).',
                'wisdom_quote_id' => '',
                'test_elegance_score' => 0.0,
                'test_audit_notes' => '',
                'approved' => false,
                'toolbox' => $toolbox,
                'node_audit' => $nodeAudit,
                'error' => 'invalid_json',
            ];
        }

        $score = (float) ($decoded['master_score'] ?? 0);
        $testScore = (float) ($decoded['test_elegance_score'] ?? $score);
        $approved = filter_var($decoded['approved'] ?? false, FILTER_VALIDATE_BOOL)
            && $score >= $minScore
            && $testScore >= $minScore;
        if (($nodeAudit['lighthouse_regress'] ?? false) === true) {
            $approved = false;
        }

        $verdict = trim((string) ($decoded['master_verdict'] ?? ''));
        if (($nodeAudit['lighthouse_regress'] ?? false) === true) {
            $verdict .= ($verdict !== '' ? ' ' : '') . '[Lighthouse threshold breached]';
        }

        $out = [
            'ok' => true,
            'master_score' => $score,
            'master_verdict' => $verdict,
            'wisdom_quote_id' => trim((string) ($decoded['wisdom_quote_id'] ?? '')),
            'test_elegance_score' => $testScore,
            'test_audit_notes' => trim((string) ($decoded['test_audit_notes'] ?? '')),
            'approved' => $approved,
            'toolbox' => $toolbox,
            'node_audit' => $nodeAudit,
        ];

        self::persistLastOpinion($out);
        self::appendHallOfWisdom($out);
        EvolutionMentorService::recordActivationMilestoneIfNeeded($this->container);
        self::recordIntegrityMilestoneIfNeeded($this->container);
        if (!$approved) {
            MasterReflectionService::append(
                $config,
                $verdict !== '' ? $verdict : 'Master rejected patch (LLM phase).',
                ['master_score' => $score, 'fqcn' => $fqcn]
            );
        }

        return $out;
    }

    /**
     * Design-phase critique (Supreme Synthesis): JSON design from Claude.
     *
     * @return array{ok: bool, raw: string, approved?: bool, master_score?: float, error?: string}
     */
    public function critiqueDesignJson(string $designJson, Config $config): array
    {
        $mm = $config->get('evolution.master_mentor', []);
        if (!is_array($mm) || !filter_var($mm['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'raw' => '{"approved":true,"master_score":10,"master_verdict":"(master disabled)"}', 'approved' => true];
        }

        $tb = $config->get('evolution.master_toolbox', []);
        $maxD = max(8000, min(200000, (int) (is_array($tb) ? ($tb['design_json_max_chars'] ?? 52000) : 52000)));
        if (strlen($designJson) > $maxD) {
            return [
                'ok' => false,
                'raw' => '',
                'error' => 'Design JSON exceeds token guard (' . $maxD . ' chars)',
            ];
        }

        $openaiKey = EvolutionProviderKeys::openAi($config, true);
        if ($openaiKey === '') {
            return ['ok' => false, 'raw' => '', 'error' => 'Missing OpenAI API key (evolution / ai / assistant / env)'];
        }

        $model = (string) ($mm['model'] ?? 'gpt-4o');
        $minScore = max(1.0, min(10.0, (float) ($mm['min_score'] ?? 7.0)));
        $wisdom = EvolutionMentorService::wisdomExcerptForApi(6000);

        $system = <<<SYS
You are the Grandmaster architect — you hate spaghetti and over-engineering.
Given DESIGN_JSON from a principal architect, ask: Is this the simplest solution? Would a junior understand it in two years?
Use WISDOM_CORPUS for tone. Output ONE JSON object:
{
  "approved": true|false,
  "master_score": 1-10,
  "master_verdict": "short",
  "wisdom_quote_id": "",
  "blockers": [],
  "security_notes": [],
  "required_changes": ""
}
Set approved false if master_score < {$minScore} or if there are unresolved HIGH severity risks.
Keep security_notes for real security/data issues.
SYS;

        $user = "WISDOM_CORPUS:\n{$wisdom}\n\nDESIGN_JSON:\n" . mb_substr($designJson, 0, 32000);

        $client = OpenAI::factory()->withApiKey($openaiKey)->make();
        try {
            $resp = $client->chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'max_tokens' => 1500,
                'response_format' => ['type' => 'json_object'],
            ]);
            $raw = trim((string) ($resp->choices[0]->message->content ?? ''));
        } catch (Throwable $e) {
            return ['ok' => false, 'raw' => '', 'error' => $e->getMessage()];
        }

        $decoded = json_decode($raw, true);
        $approved = is_array($decoded) && filter_var($decoded['approved'] ?? false, FILTER_VALIDATE_BOOL);
        $score = is_array($decoded) ? (float) ($decoded['master_score'] ?? 0) : 0.0;

        return [
            'ok' => true,
            'raw' => $raw,
            'approved' => $approved && $score >= $minScore,
            'master_score' => $score,
        ];
    }

    /**
     * @param array<string, mixed> $out
     */
    private static function persistLastOpinion(array $out): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        $path = BASE_PATH . '/' . self::LAST_OPINION_FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $payload = array_merge($out, ['ts' => gmdate('c')]);
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * @param array<string, mixed> $out
     */
    private static function appendHallOfWisdom(array $out): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        $path = BASE_PATH . '/data/evolution/hall_of_wisdom.jsonl';
        $tb = $out['toolbox'] ?? null;
        $line = json_encode([
            'ts' => gmdate('c'),
            'master_score' => $out['master_score'] ?? null,
            'master_verdict' => $out['master_verdict'] ?? '',
            'approved' => $out['approved'] ?? null,
            'test_elegance_score' => $out['test_elegance_score'] ?? null,
            'toolbox_cc' => is_array($tb) ? ($tb['cyclomatic_complexity'] ?? null) : null,
            'toolbox_violations' => is_array($tb) ? ($tb['violations'] ?? null) : null,
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string, mixed> $nodeAudit
     *
     * @return array<string, mixed>
     */
    private static function applyLighthouseThresholds(Config $config, array $nodeAudit): array
    {
        $tb = $config->get('evolution.master_toolbox', []);
        $node = is_array($tb) ? ($tb['node_audit'] ?? []) : [];
        if (!is_array($node)) {
            return $nodeAudit;
        }
        $minPerf = (float) ($node['lighthouse_min_performance'] ?? 0);
        $minA11y = (float) ($node['lighthouse_min_accessibility'] ?? 0);
        if ($minPerf <= 0 && $minA11y <= 0) {
            return $nodeAudit;
        }
        $lh = $nodeAudit['lighthouse'] ?? null;
        if (!is_array($lh)) {
            return $nodeAudit;
        }
        $perf = self::lighthouseCategoryScore($lh, 'performance');
        $a11y = self::lighthouseCategoryScore($lh, 'accessibility');
        $nodeAudit['lighthouse_scores_normalized'] = ['performance' => $perf, 'accessibility' => $a11y];
        $nodeAudit['lighthouse_regress'] = false;
        if ($minPerf > 0 && $perf > 0 && $perf < $minPerf) {
            $nodeAudit['lighthouse_regress'] = true;
        }
        if ($minA11y > 0 && $a11y > 0 && $a11y < $minA11y) {
            $nodeAudit['lighthouse_regress'] = true;
        }

        return $nodeAudit;
    }

    /**
     * @param array<string, mixed> $lh
     */
    private static function lighthouseCategoryScore(array $lh, string $catName): float
    {
        $cat = $lh['categories'][$catName] ?? null;
        if (is_array($cat) && isset($cat['score']) && is_numeric($cat['score'])) {
            $v = (float) $cat['score'];

            return $v <= 1.0 ? $v * 100.0 : $v;
        }
        $top = $lh[$catName] ?? null;
        if (is_numeric($top)) {
            $v = (float) $top;

            return $v <= 1.0 ? $v * 100.0 : $v;
        }

        return 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readLastOpinion(): array
    {
        if (!defined('BASE_PATH')) {
            return [];
        }
        $path = BASE_PATH . '/' . self::LAST_OPINION_FILE;
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($j) ? $j : [];
    }

    public static function recordIntegrityMilestoneIfNeeded(Container $container): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        $flag = BASE_PATH . '/data/evolution/.master_second_opinion_milestone_done';
        if (is_file($flag)) {
            return;
        }
        (new EvolutionHallOfFameService($container))->recordMilestone(
            'Master Second Opinion Active: Dual-Model Integrity Established.',
            'milestone',
            ['master_second_opinion' => true]
        );
        @file_put_contents($flag, gmdate('c') . "\n");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function readHallOfWisdomRecent(int $limit = 12): array
    {
        if (!defined('BASE_PATH')) {
            return [];
        }
        $path = BASE_PATH . '/data/evolution/hall_of_wisdom.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $rows = [];
        foreach (array_slice($lines, -$limit) as $line) {
            $j = json_decode($line, true);
            if (is_array($j)) {
                $rows[] = $j;
            }
        }

        return $rows;
    }
}
