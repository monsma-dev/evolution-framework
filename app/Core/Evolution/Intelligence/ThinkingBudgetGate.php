<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

use App\Core\Evolution\Trading\ModelSelectorService;

/**
 * ThinkingBudgetGate — Groq Llama 8B als snelle, gratis poortwachter voor Extended Thinking.
 *
 * Werking:
 *   1. Ontvang een taakbeschrijving + optionele context
 *   2. Stuur naar Groq Llama-3.1-8b-instant (~400ms, gratis tier)
 *   3. Llama beslist: verdient deze taak thinking-budget? Zo ja, hoeveel?
 *   4. Alleen bij goedkeuring roept de caller invokeWithThinking() aan
 *
 * Dit voorkomt dat eenvoudige taken (UI-fix, config-wijziging, routing) onnodig
 * Opus 4.6 + 20k thinking tokens verbranden.
 *
 * Cache: resultaten worden 5 minuten gecached op taak-hash (APCu of static array).
 *
 * @example
 *   $gate = new ThinkingBudgetGate($groqApiKey);
 *   $verdict = $gate->evaluate('Refactor the authentication flow to support OAuth2');
 *   if ($verdict['needs_thinking']) {
 *       $response = $bedrock->invokeWithThinking($sys, $prompt, 16000, $verdict['budget']);
 *   } else {
 *       $response = $bedrock->invoke($sys, $prompt);
 *   }
 */
final class ThinkingBudgetGate
{
    private const GROQ_URL    = 'https://api.groq.com/openai/v1/chat/completions';
    private const GROQ_MODEL  = 'llama-3.1-8b-instant';
    private const CACHE_TTL   = 300; // 5 minuten per taak-hash
    private const CURL_TIMEOUT = 6;  // Llama 8B is snel; 6s is royaal

    private static array $staticCache = [];

    public function __construct(private readonly string $groqApiKey) {}

    /**
     * Evalueer of een taak Extended Thinking verdient.
     *
     * @param string $taskDescription Beschrijving van de taak (max ~500 tekens)
     * @param array  $context         Optionele context: ['volatility' => 0.07, 'files_affected' => 8]
     *
     * @return array{
     *   needs_thinking: bool,
     *   complexity: string,
     *   budget: int,
     *   reason: string,
     *   cached: bool,
     *   gate_latency_ms: float
     * }
     */
    public function evaluate(string $taskDescription, array $context = []): array
    {
        if ($this->groqApiKey === '') {
            return $this->skip('Geen Groq API-key — gate overgeslagen, thinking toegestaan', true);
        }

        $taskHash = md5($taskDescription . json_encode($context));
        $cacheKey = 'tbgate_' . $taskHash;

        $cached = $this->fromCache($cacheKey);
        if ($cached !== null) {
            $cached['cached'] = true;
            return $cached;
        }

        $start   = microtime(true);
        $verdict = $this->callGroq($taskDescription, $context);
        $latency = round((microtime(true) - $start) * 1000, 1);

        $verdict['gate_latency_ms'] = $latency;
        $verdict['cached']          = false;

        $this->toCache($cacheKey, $verdict);
        return $verdict;
    }

    /**
     * Batch-evalueer meerdere taken in één round-trip.
     * Handig voor de EvolutionArchitect die meerdere skills tegelijk beoordeelt.
     *
     * @param array<string, string> $tasks ['task_id' => 'task description']
     * @return array<string, array> verdicts per task_id
     */
    public function evaluateBatch(array $tasks, array $sharedContext = []): array
    {
        $results = [];
        foreach ($tasks as $id => $desc) {
            $results[$id] = $this->evaluate($desc, $sharedContext);
        }
        return $results;
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function callGroq(string $task, array $context): array
    {
        $contextStr = '';
        if (!empty($context)) {
            $parts = [];
            if (isset($context['volatility'])) {
                $parts[] = 'Market volatility: ' . round((float)$context['volatility'] * 100, 1) . '%';
            }
            if (isset($context['files_affected'])) {
                $parts[] = 'Files affected: ' . (int)$context['files_affected'];
            }
            if (isset($context['severity'])) {
                $parts[] = 'Severity: ' . $context['severity'];
            }
            $contextStr = "\nContext: " . implode(', ', $parts);
        }

        $systemPrompt = <<<'SYS'
You are a strict AI task classifier. Your ONLY job is to decide if a task requires extended reasoning (thinking budget) or can be answered quickly.

APPROVE thinking ONLY for these high-value tasks:
- Architecture decisions (system redesign, database schema, API contracts, multi-service refactors)
- Security audits (vulnerability assessment, auth flow, injection risks, secrets handling)
- Complex refactors touching 5+ interdependent files
- Market analysis during EXTREME volatility (>6% daily move)
- Weekly system audits or incident post-mortems
- Algorithm design (trading strategies, ML pipelines, cryptographic protocols)

DENY thinking for everything else, including:
- Single-file edits, CSS/template changes, translation updates
- Simple CRUD, config changes, route fixes, log reading
- Routine buy/sell signal validation (volatility < 3.5%)
- Anything resolvable in 1-2 sentences
- UI improvements, text changes, minor bug fixes

Complexity levels:
- simple: no thinking (budget: 0)
- standard: no thinking (budget: 0)
- complex: thinking if justified (budget: 8000)
- architecture: always thinking (budget: 12000)
- security: always thinking (budget: 12000)
- audit: always thinking (budget: 20000)

Also rate your OWN confidence in this classification (0-100). High confidence = you understand the task well. Low confidence = task is ambiguous or extremely complex.

Return ONLY valid JSON on one line. No markdown, no explanation outside JSON:
{"needs_thinking": false, "complexity": "standard", "budget": 0, "confidence": 85, "reason": "one line max 80 chars"}
SYS;

        $userPrompt = "Task: {$task}{$contextStr}";

        $body = json_encode([
            'model'       => self::GROQ_MODEL,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'max_tokens'  => 120,
            'temperature' => 0.0,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::GROQ_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->groqApiKey,
            ],
        ]);
        $raw     = (string)curl_exec($ch);
        $curlErr = curl_errno($ch);
        curl_close($ch);

        if ($curlErr !== 0) {
            return $this->skip("Groq timeout/error ({$curlErr}) — thinking toegestaan als fallback", true);
        }

        $data = json_decode($raw, true) ?: [];
        $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));

        if ($text === '') {
            return $this->skip('Groq lege respons — thinking toegestaan als fallback', true);
        }

        // Strip eventuele markdown code fences die Llama toch meestuurt
        $text = preg_replace('/^```[a-z]*\n?|```$/m', '', $text) ?? $text;
        $text = trim($text);

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            return $this->skip("Groq ongeldige JSON ({$text}) — thinking geweigerd als voorzorgsmaatregel", false);
        }

        $rawBudget    = $this->sanitizeBudget((int)($parsed['budget'] ?? 0));
        $llamaConfidence = min(100, max(0, (int)($parsed['confidence'] ?? 75)));
        $scaledBudget = $this->scaleBudgetByConfidence($rawBudget, $llamaConfidence);

        return [
            'needs_thinking'   => (bool)($parsed['needs_thinking'] ?? false),
            'complexity'       => (string)($parsed['complexity'] ?? 'standard'),
            'budget'           => $scaledBudget,
            'budget_raw'       => $rawBudget,
            'llama_confidence' => $llamaConfidence,
            'reason'           => substr((string)($parsed['reason'] ?? ''), 0, 120),
            'gate_latency_ms'  => 0.0,
            'cached'           => false,
        ];
    }

    /**
     * Schaal het thinking budget omgekeerd evenredig met Llama's confidence.
     * Llama hoog zeker (≥85%) → Opus hoeft minder diep te denken → lagere kosten.
     * Llama onzeker (<70%) → Opus krijgt volledig budget.
     *
     * Schaal:  confidence ≥85 → 25% van budget
     *          confidence 70-84 → 50% van budget
     *          confidence <70  → 100% van budget
     */
    private function scaleBudgetByConfidence(int $budget, int $confidence): int
    {
        if ($budget === 0) {
            return 0;
        }
        $factor = match(true) {
            $confidence >= 85 => 0.25,
            $confidence >= 70 => 0.50,
            default           => 1.00,
        };
        // Clamp naar minimaal 2000 tokens zodat extended thinking altijd geldig is
        return max(2000, (int)round($budget * $factor));
    }

    private function sanitizeBudget(int $raw): int
    {
        // Llama mag alleen de bekende budgets teruggeven; clamp naar dichtbijzijnde
        $valid = [0, 4000, 8000, 12000, 20000];
        $closest = 0;
        $minDiff = PHP_INT_MAX;
        foreach ($valid as $v) {
            $diff = abs($raw - $v);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $v;
            }
        }
        return $closest;
    }

    /** @return array{needs_thinking: bool, complexity: string, budget: int, reason: string, gate_latency_ms: float, cached: bool} */
    private function skip(string $reason, bool $allowThinking): array
    {
        return [
            'needs_thinking'  => $allowThinking,
            'complexity'      => $allowThinking ? 'complex' : 'standard',
            'budget'          => $allowThinking ? ModelSelectorService::getThinkingBudget('complex') : 0,
            'reason'          => $reason,
            'gate_latency_ms' => 0.0,
            'cached'          => false,
        ];
    }

    private function fromCache(string $key): ?array
    {
        if (function_exists('apcu_fetch')) {
            $success = false;
            $val = \apcu_fetch($key, $success);
            if ($success && is_array($val)) {
                return $val;
            }
        }
        $entry = self::$staticCache[$key] ?? null;
        if ($entry !== null && $entry['expires'] >= time()) {
            return $entry['data'];
        }
        return null;
    }

    private function toCache(string $key, array $data): void
    {
        if (function_exists('apcu_store')) {
            \apcu_store($key, $data, self::CACHE_TTL);
        }
        self::$staticCache[$key] = ['data' => $data, 'expires' => time() + self::CACHE_TTL];
    }
}
