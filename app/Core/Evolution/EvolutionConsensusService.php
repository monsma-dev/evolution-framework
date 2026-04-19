<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;
use OpenAI;
use Throwable;

/**
 * Vergaderkamer: Runner (goedkoop) → Architect → Master; bij deadlock/time-out → Courtroom (Judge).
 */
final class EvolutionConsensusService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function runMeeting(string $topic, string $contextHint = ''): array
    {
        $config = $this->container->get('config');
        $meeting = $this->meetingConfig($config);
        if ($meeting === null || !filter_var($meeting['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'consensus.meeting disabled'];
        }

        if (EvolutionSleepService::shouldBlockConsensusDuringHibernation($config)) {
            return ['ok' => false, 'error' => 'Consensus deferred: court in hibernation (Evolution Downtime protocol).'];
        }

        $bailiff = new EvolutionBailiffService($this->container);
        $budgetGate = $bailiff->assertBudgetAllowsSession($config);
        if (!($budgetGate['ok'] ?? true)) {
            return array_merge(['ok' => false], $budgetGate);
        }

        $timeout = (float) ($meeting['discussion_timeout_seconds'] ?? 120);
        $deadline = microtime(true) + max(30.0, min(600.0, $timeout));
        $maxWords = max(50, min(500, (int) ($meeting['max_words_per_turn'] ?? 200)));
        $maxRounds = max(1, min(8, (int) ($meeting['max_rounds'] ?? 3)));

        $transcript = [
            'topic' => $topic,
            'context_hint' => $contextHint,
            'started_at' => gmdate('c'),
        ];
        $transcript['police_security_scan'] = EvolutionPoliceService::securityScanForMeeting($config);
        $transcript['mentor_preflight'] = EvolutionMentorService::readLastPreflightSummary($config);
        $transcript['observatory_weekly'] = EvolutionObservatoryService::readLastWeeklyCache();
        $transcript['time_capsule'] = [
            'seal_due' => EvolutionTimeCapsuleService::isSealDue($config),
        ];

        $runner = $this->buildRunnerPack($config, $topic, $contextHint, $meeting, $deadline, $maxWords);
        $transcript['runner'] = $runner;

        if ($bailiff->isTimedOut($deadline)) {
            return $this->finishWithCourtroom($config, $topic, $transcript, $deadline, 'discussion_timeout_before_architect');
        }

        $courtroom = new EvolutionCourtroomService($this->container);
        $prosecutor = new EvolutionProsecutorService($this->container);

        $approved = false;
        $architectText = '';
        $masterJson = null;

        for ($round = 1; $round <= $maxRounds; $round++) {
            if ($bailiff->isTimedOut($deadline)) {
                $transcript['stop_reason'] = 'discussion_timeout';

                break;
            }

            $feedback = '';
            if ($round > 1 && is_array($masterJson)) {
                $feedback = "\nPrevious Master response (JSON): " . json_encode($masterJson, JSON_UNESCAPED_UNICODE);
                $charges = $transcript['charges'] ?? [];
                if (is_array($charges) && $charges !== []) {
                    $lastCharge = (string) $charges[array_key_last($charges)];
                    if ($lastCharge !== '') {
                        $feedback .= "\nProsecutor charge: " . $lastCharge;
                    }
                }
            }

            $arch = $this->callArchitect($config, $meeting, (string) ($runner['summary'] ?? ''), $topic, $contextHint . $feedback, $maxWords);
            $transcript['rounds'][$round]['architect'] = $arch;
            if (!($arch['ok'] ?? false)) {
                break;
            }
            $architectText = (string) ($arch['text'] ?? '');

            if ($bailiff->isTimedOut($deadline)) {
                $transcript['stop_reason'] = 'discussion_timeout';

                break;
            }

            $mast = $this->callMaster($config, $meeting, $architectText, (string) ($runner['summary'] ?? ''), $topic, $maxWords);
            $transcript['rounds'][$round]['master'] = $mast;
            if (!($mast['ok'] ?? false)) {
                break;
            }
            $masterJson = $mast['decoded'] ?? null;
            $decision = is_array($masterJson) ? strtolower((string) ($masterJson['decision'] ?? '')) : '';
            $minEl = max(1, min(10, (int) ($meeting['min_elegance_score'] ?? 7)));
            $elegance = is_array($masterJson) ? (int) ($masterJson['elegance_score'] ?? 0) : 0;

            if ($decision === 'approve' && $elegance >= $minEl) {
                $approved = true;

                break;
            }

            if ($decision === 'approve' && $elegance < $minEl) {
                $transcript['rounds'][$round]['master_elegance_block'] = ['min_required' => $minEl, 'reported' => $elegance];
                $masterJson = array_merge(is_array($masterJson) ? $masterJson : [], [
                    'decision' => 'reject',
                    'reason' => 'Elegance score ' . $elegance . ' below required ' . $minEl,
                ]);
            }

            $chg = $prosecutor->formulateCharge(
                $config,
                (string) ($masterJson['reason'] ?? json_encode($masterJson)),
                mb_substr($architectText, 0, 3000)
            );
            if (($chg['ok'] ?? false) && isset($chg['charge'])) {
                $transcript['charges'][] = $chg['charge'];
            }

            if ($round >= $maxRounds) {
                $transcript['stop_reason'] = 'max_rounds_no_consensus';

                break;
            }
        }

        if (!$approved) {
            $cr = $config->get('evolution.consensus.courtroom', []);
            $escalate = is_array($cr) && filter_var($cr['escalate_on_deadlock'] ?? true, FILTER_VALIDATE_BOOL);
            if ($escalate || ($transcript['stop_reason'] ?? '') === 'discussion_timeout') {
                $judge = $courtroom->judge($config, $topic, $transcript, $deadline);
                $transcript['judge'] = $judge;
            }
        }

        EvolutionWikiService::appendArchitecturalDecision(
            $config,
            'Architectural Decisions — ' . mb_substr($topic, 0, 72),
            $transcript
        );

        EvolutionLogger::log('consensus_meeting', 'complete', ['approved' => $approved, 'topic_len' => strlen($topic)]);

        return [
            'ok' => true,
            'approved' => $approved,
            'transcript' => $transcript,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function meetingConfig(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $c = is_array($evo) ? ($evo['consensus'] ?? []) : [];

        return is_array($c['meeting'] ?? null) ? $c['meeting'] : null;
    }

    /**
     * @param array<string, mixed> $meeting
     *
     * @return array<string, mixed>
     */
    private function buildRunnerPack(Config $config, string $topic, string $hint, array $meeting, float $deadline, int $maxWords): array
    {
        $dep = EvolutionDependencySquire::auditProject();
        $scout1 = EvolutionContextScout::findSymbol($config, 'EvolutionDualExecutionGuard');
        $scout2 = EvolutionContextScout::findSymbol($config, 'EvolutionNativeCompilerService');
        $native = EvolutionNativeCompilerService::configSummary($config);
        $awsMeta = $this->awsDeployEnvPresence($config);

        $summary = "## Inventory\n- PHP: {$dep['php_version']}\n- dep_ok: " . (($dep['ok'] ?? false) ? 'yes' : 'no')
            . "\n- DualExecutionGuard refs: " . count($scout1['matches'] ?? [])
            . "\n- NativeCompilerService refs: " . count($scout2['matches'] ?? [])
            . "\n- native_compiler.enabled: " . (($native['enabled'] ?? false) ? 'true' : 'false')
            . "\n\n## AWS / deploy (.env metadata — values never shown)\n" . $awsMeta;

        $costModel = (string) ($meeting['runner_model'] ?? 'gpt-4o-mini');
        $cb = $this->costBenefitMini($config, $costModel, $topic, $hint, $summary, $deadline, $maxWords);
        if (($cb['ok'] ?? false) && isset($cb['text'])) {
            $summary .= "\n\n## Cost-benefit (Runner)\n" . $cb['text'];
        }

        return [
            'summary' => $this->truncateWords($summary, $maxWords * 3),
            'dependency_audit' => $dep,
            'scout' => ['dual_guard' => $scout1, 'native_compiler' => $scout2],
            'native_config' => $native,
            'aws_env_presence' => $awsMeta,
            'ms' => ($scout1['ms'] ?? 0) + ($scout2['ms'] ?? 0),
        ];
    }

    /**
     * Presence-only hints for deploy (no secret values).
     */
    private function awsDeployEnvPresence(Config $config): string
    {
        $evo = $config->get('evolution', []);
        $deploy = is_array($evo) ? ($evo['deploy'] ?? []) : [];
        $required = ['EVOLUTION_DEPLOY_SSH_HOST', 'APP_ENV', 'DB_HOST'];
        $extra = $deploy['required_env_vars'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $k) {
                if (is_string($k) && $k !== '') {
                    $required[] = $k;
                }
            }
        }
        $sk = is_array($evo) ? ($evo['survival_kit'] ?? []) : [];
        $names = $sk['env_key_names'] ?? [];
        if (is_array($names)) {
            foreach ($names as $k) {
                if (is_string($k) && $k !== '') {
                    $required[] = $k;
                }
            }
        }
        $required = array_values(array_unique($required));
        $lines = [];
        foreach ($required as $name) {
            $v = getenv($name);
            $lines[] = '- ' . $name . ': ' . ($v !== false && $v !== '' ? 'present' : 'missing');
        }
        $lines[] = '- evolution.deploy.enabled (config): ' . (filter_var($deploy['enabled'] ?? false, FILTER_VALIDATE_BOOL) ? 'true' : 'false');

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $meeting
     *
     * @return array{ok: bool, text?: string, error?: string}
     */
    private function costBenefitMini(Config $config, string $model, string $topic, string $hint, string $facts, float $deadline, int $maxWords): array
    {
        if (microtime(true) > $deadline - 5) {
            return ['ok' => false, 'error' => 'skip cost-benefit — time'];
        }
        $key = EvolutionProviderKeys::openAi($config, true);
        if ($key === '') {
            return ['ok' => false, 'error' => 'no api key'];
        }
        $prompt = "In max {$maxWords} words, give cost/benefit of doing this change under a €20/month AI budget. Facts:\n{$facts}\nTopic: {$topic}\nHint: {$hint}";

        try {
            $client = OpenAI::factory()->withApiKey($key)->make();
            $r = $client->chat()->create([
                'model' => $model,
                'max_tokens' => 350,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
            $text = trim((string) ($r->choices[0]->message->content ?? ''));
            $monitor = new AiCreditMonitor($config);
            $monitor->recordEstimatedTurn($model, (int) ceil(strlen($prompt) / 4), 300, []);

            return ['ok' => true, 'text' => $this->truncateWords($text, $maxWords)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $meeting
     *
     * @return array{ok: bool, text?: string, error?: string}
     */
    private function callArchitect(Config $config, array $meeting, string $runnerSummary, string $topic, string $extra, int $maxWords): array
    {
        $provider = strtolower((string) ($meeting['architect_provider'] ?? 'openai'));
        $model = (string) ($meeting['architect_model'] ?? 'gpt-4o');
        $system = 'You are the Architect. Propose a concise technical plan (no full code). '
            . "Respond as JSON: {\"proposal\":\"...\",\"risks\":[\"...\"],\"validation_steps\":[\"...\"]}\n"
            . "Max {$maxWords} words total in proposal field.";

        $user = "TOPIC:\n{$topic}\n\nRUNNER DATA:\n{$runnerSummary}\n\nOTHER:\n{$extra}";

        if ($provider === 'anthropic') {
            $ar = $this->architectAnthropic($config, $model, $system, $user, $meeting);
            $arText = trim((string) ($ar['text'] ?? ''));
            if (($ar['ok'] ?? false) && $arText !== '') {
                return $ar;
            }
            $fb = (string) ($meeting['architect_fallback_model'] ?? 'gpt-4o');

            return $this->openAiJson($config, $fb, $system, $user, 900, $meeting);
        }

        return $this->openAiJson($config, $model, $system, $user, 900, $meeting);
    }

    /**
     * @param array<string, mixed> $meeting
     *
     * @return array{ok: bool, text?: string, error?: string}
     */
    private function architectAnthropic(Config $config, string $model, string $system, string $user, array $meeting): array
    {
        $key = EvolutionProviderKeys::anthropic($config);
        if ($key === '') {
            return ['ok' => false, 'error' => 'anthropic api_key missing (evolution / ai / env)'];
        }
        $client = new AnthropicMessagesClient($key);
        try {
            $text = $client->complete($system, [['role' => 'user', 'content' => $user]], $model, 1200);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $monitor = new AiCreditMonitor($config);
        $monitor->recordEstimatedTurn($model, (int) ceil((strlen($system) + strlen($user)) / 4), 400, []);

        return ['ok' => true, 'text' => trim($text)];
    }

    /**
     * @param array<string, mixed> $meeting
     *
     * @return array{ok: bool, decoded?: array<string, mixed>|null, raw?: string, error?: string}
     */
    private function callMaster(Config $config, array $meeting, string $architectProposal, string $runnerSummary, string $topic, int $maxWords): array
    {
        $model = (string) ($meeting['master_model'] ?? 'gpt-4o');
        $minEl = max(1, min(10, (int) ($meeting['min_elegance_score'] ?? 7)));
        $system = 'You are the Master (quality gate). Decide if the Architect plan is safe for this PHP codebase and budget. '
            . 'Score the elegance/clarity of the consensus/courtroom approach as elegance_score (integer 1–10). '
            . "You MUST reject (decision \"reject\") if elegance_score would be below {$minEl}. "
            . 'Reply JSON only: {"decision":"approve"|"reject","elegance_score":7,"reason":"...","checks":["..."]}. '
            . "Keep reason under {$maxWords} words.";

        $user = "TOPIC:\n{$topic}\n\nRUNNER:\n{$runnerSummary}\n\nARCHITECT:\n{$architectProposal}";

        $r = $this->openAiJson($config, $model, $system, $user, 700, $meeting);
        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'error' => $r['error'] ?? '?'];
        }
        $raw = (string) ($r['text'] ?? '');
        $decoded = json_decode($raw, true);

        return ['ok' => true, 'decoded' => is_array($decoded) ? $decoded : null, 'raw' => $raw];
    }

    /**
     * @param array<string, mixed> $meeting
     *
     * @return array{ok: bool, text?: string, error?: string}
     */
    private function openAiJson(Config $config, string $model, string $system, string $user, int $maxTokens, array $meeting): array
    {
        $key = EvolutionProviderKeys::openAi($config, true);
        if ($key === '') {
            return ['ok' => false, 'error' => 'Missing OpenAI API key'];
        }
        $monitor = new AiCreditMonitor($config);
        $eval = $monitor->evaluateBeforeCall('core', $model, $maxTokens, $system, [['role' => 'user', 'content' => $user]], []);
        $useModel = $model;
        if (($eval['force_cheap'] ?? false) && empty($meeting['ignore_budget_cheap_override'])) {
            $bg = $config->get('evolution.budget_guard', []);
            $useModel = is_array($bg) ? (string) ($bg['cheap_core_model'] ?? 'gpt-4o-mini') : 'gpt-4o-mini';
        }

        try {
            $client = OpenAI::factory()->withApiKey($key)->make();
            $resp = $client->chat()->create([
                'model' => $useModel,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);
            $text = trim((string) ($resp->choices[0]->message->content ?? ''));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $monitor->recordEstimatedTurn($useModel, (int) ($eval['estimated_input_tokens'] ?? 500), (int) ceil($maxTokens * 0.45), []);

        return ['ok' => true, 'text' => $text];
    }

    /**
     * @param array<string, mixed> $transcript
     *
     * @return array<string, mixed>
     */
    private function finishWithCourtroom(Config $config, string $topic, array $transcript, float $deadline, string $reason): array
    {
        $transcript['stop_reason'] = $reason;
        $court = new EvolutionCourtroomService($this->container);
        $transcript['judge'] = $court->judge($config, $topic, $transcript, $deadline);
        EvolutionWikiService::appendArchitecturalDecision($config, 'Consensus (timeout): ' . mb_substr($topic, 0, 60), $transcript);

        return ['ok' => true, 'approved' => false, 'transcript' => $transcript];
    }

    private function truncateWords(string $text, int $maxWords): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || count($words) <= $maxWords) {
            return trim($text);
        }

        return implode(' ', array_slice($words, 0, $maxWords));
    }
}
