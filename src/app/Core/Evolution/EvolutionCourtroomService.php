<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;
use OpenAI;
use Throwable;

/**
 * Deadlock → Judge: krijgt alleen samengevat dossier + standpunten (minimale tokens).
 */
final class EvolutionCourtroomService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, mixed> $transcript
     *
     * @return array{ok: bool, verdict?: string, supreme_directive?: string, error?: string, timed_out?: bool}
     */
    public function judge(Config $config, string $topic, array $transcript, float $deadlineUtc): array
    {
        $evo = $config->get('evolution', []);
        $cr = is_array($evo) ? ($evo['consensus']['courtroom'] ?? []) : [];
        if (!is_array($cr) || !filter_var($cr['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'courtroom disabled'];
        }

        $monitor = new AiCreditMonitor($config);
        $headroom = $monitor->assertMonthBudgetHeadroomForPremiumCall((float) ($cr['judge_estimated_eur_max'] ?? 0.5));
        if (!($headroom['ok'] ?? true)) {
            EvolutionLogger::log('courtroom', 'judge_blocked_budget', $headroom);

            return ['ok' => false, 'error' => 'monthly budget — judge blocked', 'verdict' => 'HOLD'];
        }

        $judgeModel = (string) ($cr['judge_model'] ?? 'o1');
        $maxTok = max(256, min(4096, (int) ($cr['judge_max_tokens'] ?? 1200)));
        $judgeBudgetSec = max(5, min(60, (int) ($cr['judge_time_budget_seconds'] ?? 15)));
        $judgeDeadline = microtime(true) + $judgeBudgetSec;
        if ($judgeDeadline > $deadlineUtc) {
            $judgeDeadline = $deadlineUtc;
        }

        $dossier = mb_substr(json_encode($transcript, JSON_UNESCAPED_UNICODE), 0, (int) ($cr['max_dossier_chars'] ?? 12000));

        $system = 'You are the Judge. You write NO code. Read the dossier and issue a single Supreme Directive. '
            . 'Respond with JSON only: {"verdict":"APPROVE_DEPLOY|HOLD|ABORT","supreme_directive":"one short paragraph","rationale":"why"}. '
            . 'APPROVE_DEPLOY only if native bridge / deploy risks are acceptable.';

        $user = "TOPIC:\n{$topic}\n\nDOSSIER (truncated):\n{$dossier}";

        $key = trim((string) $config->get('ai.openai.api_key', $config->get('assistant.api_key', '')));
        if ($key === '') {
            return ['ok' => false, 'error' => 'Missing OpenAI API key for Judge'];
        }

        if (microtime(true) > $judgeDeadline) {
            return ['ok' => false, 'timed_out' => true, 'error' => 'judge time budget exceeded'];
        }

        try {
            $client = OpenAI::factory()->withApiKey($key)->make();
            $isReasoning = (bool) preg_match('/^o1|gpt-5/i', $judgeModel);
            $payload = [
                'model' => $judgeModel,
                'messages' => $isReasoning
                    ? [['role' => 'user', 'content' => $system . "\n\n" . $user]]
                    : [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
            ];
            if ($isReasoning) {
                $payload['max_completion_tokens'] = $maxTok;
            } else {
                $payload['max_tokens'] = $maxTok;
            }
            $r = $client->chat()->create($payload);
            $text = trim((string) ($r->choices[0]->message->content ?? ''));
        } catch (Throwable $e) {
            EvolutionLogger::log('courtroom', 'judge_error', ['e' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $monitor->recordEstimatedTurn($judgeModel, (int) ceil((strlen($system) + strlen($user)) / 4), (int) ceil($maxTok * 0.4), []);

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return ['ok' => true, 'verdict' => 'HOLD', 'supreme_directive' => $text];
        }

        $verdict = (string) ($decoded['verdict'] ?? 'HOLD');
        $directive = (string) ($decoded['supreme_directive'] ?? '');

        EvolutionWikiService::appendArchitecturalDecision($config, 'Courtroom Judge verdict: ' . mb_substr($topic, 0, 60), [
            'verdict' => $verdict,
            'supreme_directive' => $directive,
            'raw' => $decoded,
        ]);

        if (strtoupper($verdict) === 'APPROVE_DEPLOY' || strtoupper($verdict) === 'HOLD') {
            (new EvolutionHallOfFameService($this->container))->recordMilestone(
                'Courtroom: Judge verdict ' . $verdict . ' — ' . mb_substr($directive, 0, 120),
                'courtroom',
                ['topic' => $topic]
            );
        }

        return [
            'ok' => true,
            'verdict' => $verdict,
            'supreme_directive' => $directive,
        ];
    }
}
