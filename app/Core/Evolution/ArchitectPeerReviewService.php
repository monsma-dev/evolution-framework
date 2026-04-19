<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use OpenAI;
use Throwable;

/**
 * Second model reviews strategy_plan JSON before phase-2 code generation (think-step).
 */
final class ArchitectPeerReviewService
{
    /**
     * @param array<string, mixed>|null $strategyPlan
     * @return array{ok: bool, approved: bool, summary?: string, issues?: list<string>, error?: string}
     */
    public static function review(
        Config $config,
        string $openaiApiKey,
        ?array $strategyPlan
    ): array {
        $pr = $config->get('evolution.peer_review', []);
        if (!is_array($pr) || !filter_var($pr['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'approved' => true];
        }
        if ($strategyPlan === null || $strategyPlan === []) {
            return ['ok' => true, 'approved' => true];
        }

        $model = trim((string)($pr['reviewer_model'] ?? 'gpt-4o'));
        if ($model === '') {
            $model = 'gpt-4o';
        }
        $maxTok = max(200, min(1200, (int)($pr['max_tokens'] ?? 600)));

        $planJson = json_encode($strategyPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($planJson === false) {
            return ['ok' => false, 'approved' => false, 'error' => 'Peer review: cannot encode strategy_plan'];
        }

        $system = <<<'SYS'
You are a strict peer reviewer for a PHP 8.3 marketplace codebase. You receive only a JSON "strategy_plan" (no code).
Check for: logical gaps, security concerns (SQL in wrong layer, missing validation), scope creep, immune-path violations, unrealistic steps.
Respond with a single JSON object: {"approved": true|false, "issues": ["short bullet strings"], "summary": "one paragraph"}.
If any issue is severe (security or breaking change without approval), set approved to false.
SYS;

        try {
            $client = OpenAI::factory()->withApiKey($openaiApiKey)->make();
            $r = $client->chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "strategy_plan JSON:\n{$planJson}"],
                ],
                'max_tokens' => $maxTok,
                'response_format' => ['type' => 'json_object'],
            ]);
            $text = trim((string)($r->choices[0]?->message->content ?? ''));
            if ($text === '') {
                return ['ok' => false, 'approved' => false, 'error' => 'Peer review: empty response'];
            }
            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'approved' => false, 'error' => 'Peer review: invalid JSON'];
            }
            $approved = filter_var($decoded['approved'] ?? false, FILTER_VALIDATE_BOOL);
            $issues = $decoded['issues'] ?? [];
            if (!is_array($issues)) {
                $issues = [];
            }
            $issues = array_values(array_filter(array_map(static fn ($x) => is_string($x) ? $x : null, $issues)));
            $summary = trim((string)($decoded['summary'] ?? ''));

            EvolutionLogger::log('architect', 'peer_review', [
                'approved' => $approved,
                'model' => $model,
                'issues_count' => count($issues),
            ]);

            return [
                'ok' => true,
                'approved' => $approved,
                'summary' => $summary,
                'issues' => $issues,
            ];
        } catch (Throwable $e) {
            EvolutionLogger::log('architect', 'peer_review_error', ['message' => $e->getMessage()]);

            return ['ok' => false, 'approved' => false, 'error' => $e->getMessage()];
        }
    }
}
