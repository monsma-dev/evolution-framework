<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use PDO;

/**
 * DeepSeek R1-powered niche analysis and prompt evolution.
 *
 * Workflow:
 *   1. Pull top market signals from MarketSignalModel.
 *   2. Send them to deepseek-reasoner for niche scoring.
 *   3. Optionally mutate outreach system prompts based on recent conversions.
 *   4. Log <think> traces to storage/logs/evolution_strategy.log (via DeepSeekClient).
 *
 * CLI: php ai_bridge.php evolution:strategy analyze
 *      php ai_bridge.php evolution:strategy mutate-prompt
 */
final class EvolutionStrategyService
{
    private const MAX_SIGNALS     = 20;
    private const ANALYSIS_TOKENS = 3000;
    private const MUTATE_TOKENS   = 4096;
    private const REASONER        = DeepSeekClient::MODEL_REASONER;

    public function __construct(
        private readonly Config $config,
        private readonly PDO    $db
    ) {
    }

    // ─── Public API ─────────────────────────────────────────────────────────────

    /**
     * Analyze top market signals and rank niches by intent score.
     * Uses deepseek-reasoner for chain-of-thought niche evaluation.
     *
     * @return array{ok: bool, niche?: string, score?: float, analysis?: array<string, mixed>, error?: string}
     */
    public function getBestNicheFromSignals(): array
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'DEEPSEEK_API_KEY not configured (ai.deepseek.api_key).'];
        }

        $model = new MarketSignalModel($this->db, $this->config);
        $niches = $model->getTopNiches(24, 10);

        if (empty($niches)) {
            return ['ok' => false, 'error' => 'No market signals in the last 24 hours.'];
        }

        $signals = $model->getTopByIntentScore(0.3, self::MAX_SIGNALS);
        $signalSummary = $this->summarizeSignals($signals);

        $system = <<<'SYSTEM'
You are an autonomous market intelligence analyst for a European secondhand marketplace.
Analyze the provided market signal data and identify the BEST niche opportunity.
Respond with a single JSON object:
{
  "best_niche": "niche_slug",
  "confidence": 0.0-1.0,
  "rationale": "2-3 sentence reasoning",
  "top_niches": [
    {"niche": "...", "score": 0.0-1.0, "signals": 0, "opportunity": "brief note"}
  ],
  "recommended_action": "brief next step for the outreach agent"
}
SYSTEM;

        $user = "NICHE AGGREGATES (last 24h):\n" . json_encode($niches, JSON_UNESCAPED_UNICODE)
            . "\n\nRAW SIGNALS SAMPLE:\n" . $signalSummary;

        $client = new DeepSeekClient($apiKey);
        $raw = $client->complete($system, [['role' => 'user', 'content' => $user]], self::REASONER, self::ANALYSIS_TOKENS, false);

        if ($raw === '') {
            EvolutionLogger::log('strategy', 'analysis_failed', [
                'http_status' => $client->getLastHttpStatus(),
                'niches'      => count($niches),
            ]);

            return ['ok' => false, 'error' => 'DeepSeek reasoner returned empty (status: ' . $client->getLastHttpStatus() . ').'];
        }

        $decoded = $this->decodeJson($raw);
        if ($decoded === null) {
            return ['ok' => false, 'error' => 'Invalid JSON from reasoner.', 'raw' => mb_substr($raw, 0, 500)];
        }

        EvolutionLogger::log('strategy', 'analysis_complete', [
            'best_niche' => $decoded['best_niche'] ?? 'unknown',
            'confidence' => $decoded['confidence'] ?? 0,
        ]);

        return [
            'ok'       => true,
            'niche'    => (string)($decoded['best_niche'] ?? ''),
            'score'    => (float)($decoded['confidence'] ?? 0),
            'analysis' => $decoded,
        ];
    }

    /**
     * Prompt Evolution: Given the last N successful conversions, ask R1 to rewrite
     * the outreach agent's system prompt to improve conversion rate.
     *
     * @param list<array{source: string, niche: string, raw_content: string}> $conversions
     * @return array{ok: bool, new_prompt?: string, diff_summary?: string, error?: string}
     */
    public function mutateSystemPrompt(string $currentPrompt, array $conversions): array
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'DEEPSEEK_API_KEY not configured.'];
        }

        if (empty($conversions)) {
            return ['ok' => false, 'error' => 'No conversions provided for mutation.'];
        }

        $convSummary = '';
        foreach (array_slice($conversions, 0, 10) as $i => $c) {
            $convSummary .= ($i + 1) . ". [{$c['source']}] {$c['niche']}: " . mb_substr($c['raw_content'], 0, 200) . "\n";
        }

        $system = <<<'SYSTEM'
You are an autonomous prompt engineer for a marketplace outreach agent.
Study the successful conversion patterns and rewrite the provided system prompt to improve conversion rate.
Respond with JSON:
{
  "new_prompt": "full rewritten system prompt",
  "diff_summary": "what changed and why (2-3 sentences)",
  "expected_improvement": "brief hypothesis"
}
SYSTEM;

        $user = "CURRENT SYSTEM PROMPT:\n{$currentPrompt}\n\nSUCCESSFUL CONVERSIONS:\n{$convSummary}";

        $client = new DeepSeekClient($apiKey);
        $raw = $client->complete($system, [['role' => 'user', 'content' => $user]], self::REASONER, self::MUTATE_TOKENS, false);

        if ($raw === '') {
            return ['ok' => false, 'error' => 'Reasoner returned empty (status: ' . $client->getLastHttpStatus() . ').'];
        }

        $decoded = $this->decodeJson($raw);
        if ($decoded === null || empty($decoded['new_prompt'])) {
            return ['ok' => false, 'error' => 'Invalid mutation response.'];
        }

        EvolutionLogger::log('strategy', 'prompt_mutated', [
            'diff_summary' => mb_substr((string)($decoded['diff_summary'] ?? ''), 0, 200),
        ]);

        return [
            'ok'          => true,
            'new_prompt'  => (string)$decoded['new_prompt'],
            'diff_summary' => (string)($decoded['diff_summary'] ?? ''),
        ];
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function resolveApiKey(): string
    {
        return trim((string)$this->config->get('ai.deepseek.api_key', ''));
    }

    /**
     * @param list<array<string, mixed>> $signals
     */
    private function summarizeSignals(array $signals): string
    {
        $lines = [];
        foreach (array_slice($signals, 0, self::MAX_SIGNALS) as $i => $s) {
            $lines[] = sprintf(
                '%d. [%s|%s|%.2f] %s',
                $i + 1,
                $s['source'] ?? '?',
                $s['niche'] ?? '?',
                (float)($s['intent_score'] ?? 0),
                mb_substr((string)($s['raw_content'] ?? ''), 0, 150)
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $t = trim($raw);
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $t, $m)) {
            $t = trim($m[1]);
        }
        $d = json_decode($t, true);

        return is_array($d) ? $d : null;
    }
}
