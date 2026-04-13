<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use OpenAI;
use Throwable;

/**
 * Mentor / Junior Architect — gpt-4o-mini prepares; Claude peer-reviews; training rows go to wiki.
 * Junior does not invoke Judge or Police; reports to Architect only.
 */
final class EvolutionMentorService
{
    private const PREFLIGHT_FILE = 'storage/evolution/mentor_preflight_last.json';

    /**
     * @return array<string, mixed>|null
     */
    private function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $m = is_array($evo) ? ($evo['mentor'] ?? []) : null;

        return is_array($m) && filter_var($m['enabled'] ?? true, FILTER_VALIDATE_BOOL) ? $m : null;
    }

    /**
     * Junior slot — OpenAI gpt-4o-mini (cheap).
     *
     * @return array{ok: bool, text?: string, error?: string, model?: string}
     */
    public function juniorDelegate(Config $config, string $taskLabel, string $instruction, ?string $contextCode = null): array
    {
        $m = $this->cfg($config);
        if ($m === null) {
            return ['ok' => false, 'error' => 'evolution.mentor disabled'];
        }
        $model = trim((string) ($m['junior_model'] ?? 'gpt-4o-mini'));
        $maxTok = max(256, min(4096, (int) ($m['junior_max_tokens'] ?? 2500)));
        $key = trim((string) $config->get('ai.openai.api_key', $config->get('assistant.api_key', '')));
        if ($key === '') {
            return ['ok' => false, 'error' => 'Missing ai.openai.api_key for Junior'];
        }

        $system = <<<SYS
You are the Junior Architect for a PHP 8.3 framework. You only prepare work: docblocks, small tests, lint-friendly edits, summaries.
You do NOT call external governance APIs. Output concise, actionable content. Task label: {$taskLabel}
SYS;
        $user = $instruction;
        if ($contextCode !== null && $contextCode !== '') {
            $user .= "\n\n--- CONTEXT ---\n" . mb_substr($contextCode, 0, 120000);
        }

        $monitor = new AiCreditMonitor($config);
        $eval = $monitor->evaluateBeforeCall('mentor_junior', $model, $maxTok, $system, [['role' => 'user', 'content' => $user]], []);

        try {
            $client = OpenAI::factory()->withApiKey($key)->make();
            $r = $client->chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'max_tokens' => $maxTok,
            ]);
            $text = trim((string) ($r->choices[0]?->message->content ?? ''));
            $monitor->recordEstimatedTurn($model, (int) ($eval['estimated_input_tokens'] ?? 0), (int) ceil($maxTok * 0.35), []);
            EvolutionLogger::log('mentor', 'junior_delegate', ['task' => $taskLabel, 'model' => $model]);

            return ['ok' => true, 'text' => $text, 'model' => $model];
        } catch (Throwable $e) {
            EvolutionLogger::log('mentor', 'junior_error', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Architect peer review — Anthropic (same family as production Architect).
     *
     * @return array{ok: bool, approved?: bool, feedback?: string, issues?: list<string>, error?: string}
     */
    public function architectPeerReview(Config $config, string $juniorOutput, string $taskLabel, string $extraContext = ''): array
    {
        $m = $this->cfg($config);
        if ($m === null) {
            return ['ok' => false, 'error' => 'evolution.mentor disabled'];
        }
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $model = trim((string) ($m['architect_review_model'] ?? (is_array($arch) ? ($arch['code_model'] ?? 'claude-3-5-sonnet-20241022') : 'claude-3-5-sonnet-20241022')));
        $maxTok = max(400, min(4096, (int) ($m['review_max_tokens'] ?? 1200)));

        $anth = is_array($evo) ? ($evo['anthropic'] ?? []) : [];
        $key = trim((string) ($anth['api_key'] ?? ''));
        if ($key === '') {
            return ['ok' => false, 'error' => 'evolution.anthropic.api_key missing for Architect peer review'];
        }

        $system = <<<'SYS'
You are the Senior Architect. The Junior (cheap model) produced draft work. Peer-review it before it goes to the Master.
Respond with a single JSON object ONLY:
{"approved": true|false, "feedback": "short instructions for Junior if rejected", "issues": ["bullet strings"]}
Reject if: security risk, wrong layer, breaks PSR-12 badly, or incomplete tests/docs.
SYS;

        $user = "Task: {$taskLabel}\n\n--- JUNIOR OUTPUT ---\n" . mb_substr($juniorOutput, 0, 80000);
        if ($extraContext !== '') {
            $user .= "\n\n--- EXTRA ---\n" . mb_substr($extraContext, 0, 20000);
        }

        $monitor = new AiCreditMonitor($config);
        $monitor->recordEstimatedTurn($model, (int) ceil((strlen($system) + strlen($user)) / 4), (int) ceil($maxTok * 0.4), []);

        try {
            $client = new AnthropicMessagesClient($key);
            $text = trim($client->complete($system, [['role' => 'user', 'content' => $user]], $model, $maxTok));
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```\s*$/', '', $text) ?? $text;
            $j = json_decode($text, true);
            if (!is_array($j)) {
                return ['ok' => false, 'error' => 'Peer review: invalid JSON from Architect'];
            }
            $approved = filter_var($j['approved'] ?? false, FILTER_VALIDATE_BOOL);
            $feedback = trim((string) ($j['feedback'] ?? ''));
            $issues = $j['issues'] ?? [];
            if (!is_array($issues)) {
                $issues = [];
            }
            $issues = array_values(array_filter(array_map(static fn ($x) => is_string($x) ? $x : null, $issues)));

            EvolutionLogger::log('mentor', 'architect_peer_review', ['approved' => $approved, 'task' => $taskLabel]);

            return ['ok' => true, 'approved' => $approved, 'feedback' => $feedback, 'issues' => $issues];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function runDelegateCycle(Config $config, string $taskLabel, string $instruction, ?string $contextCode = null): array
    {
        $m = $this->cfg($config);
        $maxR = max(1, min(6, (int) ($m['max_delegate_retries'] ?? 3)));
        $extra = '';
        $lastJunior = '';
        $transcript = [];

        for ($i = 1; $i <= $maxR; $i++) {
            $ins = $instruction;
            if ($extra !== '') {
                $ins .= "\n\nArchitect feedback (retry {$i}):\n" . $extra;
            }
            $jun = $this->juniorDelegate($config, $taskLabel, $ins, $contextCode);
            $transcript[] = ['round' => $i, 'junior' => $jun];
            if (!($jun['ok'] ?? false)) {
                return ['ok' => false, 'error' => $jun['error'] ?? 'junior failed', 'transcript' => $transcript];
            }
            $lastJunior = (string) ($jun['text'] ?? '');
            $rev = $this->architectPeerReview($config, $lastJunior, $taskLabel, '');
            $transcript[] = ['round' => $i, 'peer_review' => $rev];
            if (($rev['ok'] ?? false) && ($rev['approved'] ?? false)) {
                EvolutionWikiService::appendMentorTrainingData(
                    $config,
                    $taskLabel,
                    [
                        'rounds' => $i,
                        'junior_model' => $jun['model'] ?? 'gpt-4o-mini',
                        'excerpt' => mb_substr($lastJunior, 0, 4000),
                    ]
                );

                return ['ok' => true, 'approved' => true, 'final_junior_output' => $lastJunior, 'transcript' => $transcript];
            }
            $extra = trim(($rev['feedback'] ?? '') . "\n" . implode("\n", $rev['issues'] ?? []));
            if ($extra === '') {
                $extra = 'Improve correctness, completeness, and framework conventions.';
            }
        }

        EvolutionWikiService::appendMentorTrainingData(
            $config,
            $taskLabel . ' (failed after ' . $maxR . ' rounds)',
            ['failed' => true, 'last_excerpt' => mb_substr($lastJunior, 0, 2000)]
        );

        return ['ok' => false, 'approved' => false, 'error' => 'Max mentor retries exceeded', 'last_junior_output' => $lastJunior, 'transcript' => $transcript];
    }

    /**
     * Sanitize pipeline before consensus: lint + phpunit + composer outdated (no Judge/Police).
     *
     * @return array<string, mixed>
     */
    public static function runPreflight(Config $config): array
    {
        $paths = EvolutionStyleEnforcer::pathsFromMentorConfig($config);
        if ($paths === []) {
            $paths = ['src/app/Core/Evolution/EvolutionMentorService.php'];
        }
        $lint = EvolutionStyleEnforcer::lintFiles($paths);
        $trial = EvolutionTrialRunner::runFullSuite(120);
        $pk = EvolutionPackageChecker::composerOutdatedDirect();

        $payload = [
            'ts' => gmdate('c'),
            'style_enforcer' => $lint,
            'trial_runner' => [
                'exit_code' => $trial['exit_code'],
                'stdout_tail' => mb_substr((string) $trial['stdout'], -2000),
                'stderr_tail' => mb_substr((string) $trial['stderr'], -800),
            ],
            'package_checker' => [
                'ok' => $pk['ok'],
                'package_count' => isset($pk['packages']) && is_array($pk['packages']) ? count($pk['packages']) : 0,
                'raw_tail' => mb_substr((string) ($pk['stdout'] ?? ''), -1500),
            ],
        ];

        $file = BASE_PATH . '/' . self::PREFLIGHT_FILE;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        EvolutionLogger::log('mentor', 'preflight', ['lint_ok' => $lint['ok'], 'phpunit' => $trial['exit_code']]);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readLastPreflightSummary(Config $config): array
    {
        $path = BASE_PATH . '/' . self::PREFLIGHT_FILE;
        if (!is_file($path)) {
            return ['hint' => 'Run: php ai_bridge.php evolution:mentor preflight'];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($j) ? $j : ['hint' => 'invalid preflight cache'];
    }

    /**
     * Compact mentor context block for system prompts.
     * Surfaces the last preflight summary (style issues, elegance scores) to the Architect.
     */
    public function promptCompact(Config $config): string
    {
        $m = $this->cfg($config);
        if ($m === null || !filter_var($m['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $summary = self::readLastPreflightSummary($config);
        if (empty($summary) || isset($summary['hint'])) {
            return '';
        }

        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "\n\nMENTOR_PREFLIGHT_LAST: " . ($encoded !== false ? $encoded : '{}') . "\n";
    }
}
