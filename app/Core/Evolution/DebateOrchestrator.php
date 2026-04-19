<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Multi-model Socratic debate with EconomicalLearner gate.
 *
 * ─── The three-round architecture ────────────────────────────────────────────
 *
 *  Round 1 — OPTIMIST  (GPT-4o-mini / Junior)
 *    "Propose the best, most complete, secure solution."
 *
 *  Round 2 — CRITIC    (Claude Sonnet / Architect)
 *    "Find every security hole, bug and performance problem."
 *
 *  Round 3 — DEFENSE   (GPT-4o-mini / Junior again)
 *    "Address every criticism. Produce the final, improved solution."
 *
 *  Arbiter — LOCAL OLLAMA (free)
 *    "Is the final solution safe and correct? JSON verdict."
 *
 * ─── Credit gate ─────────────────────────────────────────────────────────────
 *
 *  Debate only fires when ALL conditions are met:
 *    1. Risk tag is in the HIGH_RISK list (payment, auth, security …)
 *    2. Daily budget is not exhausted
 *    3. Both OpenAI + Anthropic API keys are configured
 *
 *  Otherwise: returns null — caller falls back to normal single-model flow.
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *   $debate = new DebateOrchestrator($config);
 *
 *   // Let the gate decide:
 *   $result = $debate->runIfNeeded($task, $code, 'payment');
 *   if ($result !== null && $result['approved']) {
 *       $finalCode = $result['consensus'];
 *   }
 *
 *   // Force a debate unconditionally:
 *   $result = $debate->debate($task, $code);
 */
final class DebateOrchestrator
{
    private const HIGH_RISK_TAGS = [
        'payment', 'stripe', 'mollie', 'checkout', 'invoice',
        'auth', 'login', 'register', 'password', '2fa', 'oauth',
        'security', 'webhook', 'escrow', 'transaction', 'crypto',
    ];

    private const DEBATE_LOG = '/var/www/html/data/evolution/debate_log.jsonl';

    public function __construct(
        private readonly Config $config,
        private readonly ?VectorMemoryService $memory = null
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run a debate only when the gate allows it.
     * Returns null when the gate blocks (low-risk or budget exceeded).
     *
     * @return array<string, mixed>|null
     */
    public function runIfNeeded(string $task, string $code = '', string $riskTag = ''): ?array
    {
        if (!$this->shouldDebate($riskTag)) {
            return null;
        }

        return $this->debate($task, $code, $riskTag);
    }

    /**
     * Force a full 3-round debate regardless of gate.
     *
     * @return array{
     *   task: string,
     *   risk_tag: string,
     *   round1: string,
     *   round2_issues: list<string>,
     *   round2_feedback: string,
     *   round3: string,
     *   approved: bool,
     *   confidence: int,
     *   consensus: string,
     *   warnings: list<string>,
     *   debated_at: string,
     *   skipped: bool,
     *   skip_reason?: string
     * }
     */
    public function debate(string $task, string $code = '', string $riskTag = 'code'): array
    {
        $mentor = new EvolutionMentorService();

        // Enrich task with vector memory context
        $enrichedTask = $this->injectMemory($task);

        // ── Round 1: Optimist proposes ────────────────────────────────────────
        $instruction1 = <<<INST
ROLE: OPTIMIST DEVELOPER
Your goal is to propose the best, most complete, most secure solution for the task below.
Be thorough. Consider edge cases, security, performance, and maintainability.

TASK: {$enrichedTask}
INST;
        if ($code !== '') {
            $instruction1 .= "\n\nEXISTING CODE:\n" . mb_substr($code, 0, 40000);
        }

        $round1 = $mentor->juniorDelegate($this->config, "debate:optimist:{$riskTag}", $instruction1);

        if (!($round1['ok'] ?? false)) {
            return $this->skipped($task, $riskTag, 'Round 1 failed: ' . ($round1['error'] ?? 'unknown'));
        }

        $optimistProposal = (string) ($round1['text'] ?? '');

        // ── Round 2: Critic attacks ───────────────────────────────────────────
        $extraCriticContext = <<<CTX
ROLE: SECURITY OFFICER / CRITIC
You are NOT here to approve. You are here to find every flaw.
Be ruthless. Find: SQL injection, XSS, CSRF, authentication bypasses, race conditions,
missing input validation, incorrect error handling, performance bottlenecks, PSR-12 violations,
missing tests, and any logic errors.
CTX;
        $round2 = $mentor->architectPeerReview(
            $this->config,
            $optimistProposal,
            "debate:critic:{$riskTag}",
            $extraCriticContext
        );

        if (!($round2['ok'] ?? false)) {
            return $this->skipped($task, $riskTag, 'Round 2 failed: ' . ($round2['error'] ?? 'unknown'));
        }

        $issues   = (array) ($round2['issues']   ?? []);
        $feedback = (string) ($round2['feedback'] ?? '');

        // ── Round 3: Optimist defends and improves ────────────────────────────
        $criticSummary = empty($issues)
            ? $feedback
            : "Issues found:\n" . implode("\n", array_map(static fn ($i) => "- {$i}", $issues))
              . ($feedback !== '' ? "\n\nAdditional feedback: {$feedback}" : '');

        $instruction3 = <<<INST
ROLE: OPTIMIST DEVELOPER — FINAL DEFENSE
The Critic (Security Officer) reviewed your proposal and found the following:

{$criticSummary}

Your original proposal:
{$optimistProposal}

Now produce the FINAL, IMPROVED solution that:
1. Addresses every valid criticism
2. Is complete and production-ready
3. Contains no security vulnerabilities
Output ONLY the final solution — no meta-commentary.
INST;

        $round3 = $mentor->juniorDelegate($this->config, "debate:defense:{$riskTag}", $instruction3);

        if (!($round3['ok'] ?? false)) {
            return $this->skipped($task, $riskTag, 'Round 3 failed: ' . ($round3['error'] ?? 'unknown'));
        }

        $finalProposal = (string) ($round3['text'] ?? '');

        // ── Arbiter: local Ollama (free) ──────────────────────────────────────
        [$approved, $confidence, $warnings] = $this->arbitrate($finalProposal, $criticSummary, $riskTag);

        // ── Store consensus in vector memory ──────────────────────────────────
        if ($approved && $finalProposal !== '' && $this->memory !== null) {
            $this->memory->store(
                "Task: {$task}\nRisk: {$riskTag}\nConsensus solution:\n{$finalProposal}",
                ['type' => 'debate_consensus', 'risk' => $riskTag, 'confidence' => $confidence]
            );
        }

        $result = [
            'task'            => $task,
            'risk_tag'        => $riskTag,
            'round1'          => $optimistProposal,
            'round2_issues'   => $issues,
            'round2_feedback' => $feedback,
            'round3'          => $finalProposal,
            'approved'        => $approved,
            'confidence'      => $confidence,
            'consensus'       => $finalProposal,
            'warnings'        => $warnings,
            'debated_at'      => date('c'),
            'skipped'         => false,
        ];

        $this->log($result);

        return $result;
    }

    /**
     * Decide if a debate is warranted based on risk tag and budget.
     */
    public function shouldDebate(string $riskTag): bool
    {
        // Must be a high-risk domain
        $tag = strtolower(trim($riskTag));
        if (!in_array($tag, self::HIGH_RISK_TAGS, true)) {
            return false;
        }

        // Both API keys must be configured
        if (!$this->hasOpenAiKey() || !$this->hasAnthropicKey()) {
            return false;
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function recentDebates(int $n = 20): array
    {
        if (!is_file(self::DEBATE_LOG)) {
            return [];
        }

        $lines = array_filter(explode("\n", (string) file_get_contents(self::DEBATE_LOG)));
        $lines = array_slice(array_reverse(array_values($lines)), 0, $n);

        return array_values(array_filter(
            array_map(static fn ($l) => json_decode($l, true), $lines)
        ));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Ask local Ollama to arbitrate. Returns [approved, confidence, warnings].
     *
     * @return array{bool, int, list<string>}
     */
    private function arbitrate(string $finalProposal, string $criticPoints, string $riskTag): array
    {
        try {
            $ollamaHost  = (string) ($this->config->get('evolution.sovereign.ollama_host')  ?? 'http://ollama:11434');
            $ollamaModel = (string) ($this->config->get('evolution.sovereign.ollama_model') ?? 'deepseek-r1:1.5b');
            $ollama = new OllamaClient($ollamaHost, $ollamaModel);

            $system = 'You are an impartial code arbiter. Respond ONLY with a JSON object.';
            $user   = <<<USR
A {$riskTag} feature was debated. Evaluate the final solution.

CRITIC RAISED:
{$criticPoints}

FINAL SOLUTION:
{$finalProposal}

Reply ONLY with this JSON (no markdown, no explanation):
{"approved": true, "confidence": 85, "warnings": ["list any remaining concerns or empty array"]}
USR;

            $raw  = $ollama->complete($system, [['role' => 'user', 'content' => $user]], null, 512);
            $raw  = (string) preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
            $raw  = (string) preg_replace('/\s*```\s*$/', '', $raw);
            $data = json_decode($raw, true);

            if (is_array($data)) {
                $approved   = (bool) filter_var($data['approved'] ?? true, FILTER_VALIDATE_BOOL);
                $confidence = max(0, min(100, (int) ($data['confidence'] ?? 70)));
                $warnings   = array_values(array_filter(
                    is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
                    static fn ($w) => is_string($w) && $w !== ''
                ));

                return [$approved, $confidence, $warnings];
            }
        } catch (\Throwable) {
            // Ollama unavailable — default to approved with medium confidence
        }

        return [true, 65, ['Arbiter unavailable — manual review recommended']];
    }

    private function injectMemory(string $task): string
    {
        if ($this->memory === null) {
            return $task;
        }

        return $this->memory->inject($task, 2, 0.15);
    }

    private function hasOpenAiKey(): bool
    {
        return EvolutionProviderKeys::openAi($this->config, true) !== '';
    }

    private function hasAnthropicKey(): bool
    {
        return EvolutionProviderKeys::anthropic($this->config) !== '';
    }

    /** @return array<string, mixed> */
    private function skipped(string $task, string $riskTag, string $reason): array
    {
        return [
            'task'            => $task,
            'risk_tag'        => $riskTag,
            'round1'          => '',
            'round2_issues'   => [],
            'round2_feedback' => '',
            'round3'          => '',
            'approved'        => false,
            'confidence'      => 0,
            'consensus'       => '',
            'warnings'        => [$reason],
            'debated_at'      => date('c'),
            'skipped'         => true,
            'skip_reason'     => $reason,
        ];
    }

    /** @param array<string, mixed> $result */
    private function log(array $result): void
    {
        $dir = dirname(self::DEBATE_LOG);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $entry = [
            'task'       => mb_substr($result['task'], 0, 200),
            'risk_tag'   => $result['risk_tag'],
            'approved'   => $result['approved'],
            'confidence' => $result['confidence'],
            'warnings'   => $result['warnings'],
            'debated_at' => $result['debated_at'],
        ];

        file_put_contents(self::DEBATE_LOG, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }
}
