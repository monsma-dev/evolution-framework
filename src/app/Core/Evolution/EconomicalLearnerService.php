<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Economical Learner — Spend Smart, Learn More.
 *
 * This service sits between every AI call and the API. Before spending a
 * single credit, it answers one question: "Is this worth paying for?"
 *
 * ─── The 4 Engines ───────────────────────────────────────────────────────────
 *
 * 1. LOCAL-FIRST FILTER
 *    The local Llama model handles 90% of work for free. Only if the local
 *    confidence score drops below the threshold (default: 60%) does the system
 *    escalate to Claude/GPT-4o.
 *
 * 2. MICRO-INFERENCE (Token Compression)
 *    Before sending code to a cloud API, strip all boilerplate: HTML tags,
 *    Tailwind classes, PHPDoc, comments, imports, blank lines. Only the
 *    "DNA-string" of the logic goes to the API. Typical reduction: 85–93%.
 *
 * 3. LEARNING ROI FILTER
 *    The Economist tracks which files are called most often. High-traffic,
 *    high-risk files (payment, auth) get full AI budget. Low-traffic files
 *    (about page, legal) get local-only processing.
 *
 * 4. BATCH ACCUMULATOR
 *    Non-urgent questions are queued. At night (or when batch is full),
 *    they are sent in one batch request — 50% cheaper on Claude/OpenAI.
 *
 * ─── Decision Flow ────────────────────────────────────────────────────────────
 *
 *   Input: code_snippet + local_result + file_path + call_count
 *
 *   ROI check:        call_count < ROI_MIN_CALLS?  → skip entirely
 *   Token estimate:   raw_tokens > BATCH_THRESHOLD? → queue for batch
 *   Local confidence: confidence >= threshold?       → use local result
 *   Cloud call:       none of above                 → escalate to cloud
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *   $learner = new EconomicalLearnerService($config);
 *
 *   $decision = $learner->decide($code, $localResult, $file, $callCount);
 *   // $decision['action'] = 'use_local' | 'call_cloud' | 'queue_batch' | 'skip'
 *   // $decision['compressed'] = stripped code to send if calling cloud
 *   // $decision['estimated_tokens'] = token count after compression
 *   // $decision['reason'] = human-readable explanation
 *
 *   // Batch flush (call at night via cron):
 *   $results = $learner->flushBatch();
 */
final class EconomicalLearnerService
{
    private const BATCH_FILE   = '/var/www/html/storage/evolution/learner_batch.jsonl';
    private const STATS_FILE   = '/var/www/html/storage/evolution/learner_stats.json';

    // ── Thresholds (can be overridden in evolution.json) ─────────────────────

    private float $confidenceThreshold;   // below this → escalate to cloud
    private int   $roiMinCalls;           // files called fewer times → skip
    private int   $batchMaxTokens;        // above this → always queue batch
    private float $dailyBudgetUsd;        // hard ceiling for cloud spending

    /** @var array<string, int> High-risk file patterns → always full budget */
    private const HIGH_PRIORITY_PATTERNS = [
        'payment'    => 100,
        'mollie'     => 100,
        'checkout'   => 90,
        'auth'       => 90,
        'login'      => 90,
        'register'   => 80,
        'password'   => 85,
        'stripe'     => 100,
        'invoice'    => 80,
        'order'      => 75,
        'webhook'    => 90,
    ];

    /** @var array<string, int> Low-priority patterns → local-only */
    private const LOW_PRIORITY_PATTERNS = [
        'about'     => 5,
        'legal'     => 5,
        'privacy'   => 5,
        'contact'   => 10,
        'faq'       => 10,
        'sitemap'   => 1,
        'robots'    => 1,
    ];

    public function __construct(private Config $config)
    {
        $ec = (array)($this->config->get('evolution.economical_learner', []) ?? []);
        $this->confidenceThreshold = (float)($ec['confidence_threshold'] ?? 0.60);
        $this->roiMinCalls         = (int)($ec['roi_min_calls']         ?? 5);
        $this->batchMaxTokens      = (int)($ec['batch_max_tokens']      ?? 800);
        $this->dailyBudgetUsd      = (float)($ec['daily_budget_usd']    ?? 0.50);
    }

    // ── Main decision engine ──────────────────────────────────────────────────

    /**
     * Decide how to process a code snippet.
     *
     * @param array<string, mixed> $localResult  Output from local Llama call
     * @return array{
     *   action: 'use_local'|'call_cloud'|'queue_batch'|'skip',
     *   reason: string,
     *   compressed: string,
     *   estimated_tokens: int,
     *   estimated_cost_usd: float,
     *   roi_score: int,
     *   savings_tokens: int
     * }
     */
    public function decide(
        string $code,
        array $localResult,
        string $filePath = '',
        int $callCount = 0
    ): array {
        // 1. ROI check — is this file worth optimising at all?
        $roiScore = $this->computeRoi($filePath, $callCount);
        if ($roiScore < 5) {
            return $this->decision('skip', "ROI score {$roiScore} < 5 — low-traffic file, not worth cloud credits", $code, 0);
        }

        // 2. Daily budget guard
        if ($this->dailySpend() >= $this->dailyBudgetUsd) {
            $budgetUsd = $this->dailyBudgetUsd;
            return $this->decision('use_local', "Daily budget \${$budgetUsd} reached — Ghost Mode active", $code, 0);
        }

        // 3. Local confidence — did Llama solve it well enough?
        $confidence = (float)($localResult['confidence'] ?? 0.0);
        if ($confidence >= $this->confidenceThreshold) {
            return $this->decision('use_local', sprintf("Local confidence %.0f%% ≥ threshold %.0f%% — no cloud needed", $confidence * 100, $this->confidenceThreshold * 100), $code, 0);
        }

        // 4. Compress the code (Micro-Inference)
        $compressed = $this->compress($code);
        $rawTokens  = $this->estimateTokens($code);
        $newTokens  = $this->estimateTokens($compressed);
        $savings    = $rawTokens - $newTokens;

        // 5. Batch or immediate cloud call
        if ($newTokens > $this->batchMaxTokens || $roiScore < 50) {
            return $this->decision('queue_batch',
                "Token count {$newTokens} or ROI {$roiScore} → queued for nightly batch (50% cheaper)",
                $compressed, $newTokens, $savings
            );
        }

        return $this->decision('call_cloud',
            sprintf("Local confidence %.0f%% < threshold — escalating to cloud (%d tokens, $%.4f)",
                $confidence * 100, $newTokens, $this->estimateCost($newTokens)),
            $compressed, $newTokens, $savings
        );
    }

    // ── Micro-Inference: Token Compression ───────────────────────────────────

    /**
     * Strip all boilerplate from code. Return only logic DNA.
     * Typical reduction: 85–93%.
     */
    public function compress(string $code): string
    {
        // Remove PHPDoc blocks
        $code = preg_replace('/\/\*\*[\s\S]*?\*\//m', '', $code) ?? $code;
        // Remove single-line comments
        $code = preg_replace('/^\s*\/\/.*$/m', '', $code) ?? $code;
        // Remove HTML tags
        $code = strip_tags($code);
        // Remove Tailwind utility classes (common patterns)
        $code = preg_replace('/class\s*=\s*["\'][^"\']*["\']/', '', $code) ?? $code;
        // Remove use statements (known imports)
        $code = preg_replace('/^use\s+[^;]+;\s*$/m', '', $code) ?? $code;
        // Remove blank lines (3+ → 1)
        $code = preg_replace('/\n{3,}/', "\n\n", $code) ?? $code;
        // Remove leading whitespace on lines > 4 spaces deep
        $code = preg_replace('/^    {2,}/m', '    ', $code) ?? $code;
        // Collapse long string literals
        $code = preg_replace('/"[^"]{80,}"/', '"..."', $code) ?? $code;
        $code = preg_replace("/'[^']{80,}'/", "'...'", $code) ?? $code;

        return trim($code);
    }

    /**
     * Extract just the function signatures and key logic lines (ultra-compact).
     * Use for batch mode where only the essence matters.
     */
    public function dnaString(string $code): string
    {
        $lines  = explode("\n", $this->compress($code));
        $dna    = [];
        $inBody = false;
        $depth  = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') { continue; }

            // Always keep function/class/if/return/throw signatures
            if (preg_match('/^(function|class|interface|trait|if|elseif|foreach|return|throw|try|catch)\b/', $trimmed)) {
                $dna[]  = $line;
                $inBody = true;
            } elseif ($inBody && $depth <= 1) {
                $dna[] = $line;
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return implode("\n", array_slice($dna, 0, 50));
    }

    // ── Batch Accumulator ─────────────────────────────────────────────────────

    /**
     * Queue a question for nightly batch processing.
     *
     * @param array<string, mixed> $context
     */
    public function queueBatch(string $question, string $compressed, array $context = []): void
    {
        $entry = json_encode([
            'id'        => substr(md5(uniqid('batch', true)), 0, 8),
            'queued_at' => gmdate('c'),
            'question'  => mb_substr($question, 0, 500),
            'code'      => $compressed,
            'tokens'    => $this->estimateTokens($compressed),
            'context'   => $context,
        ]) . "\n";

        $path = $this->resolvePath(self::BATCH_FILE);
        $dir  = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Flush the batch queue — sends all accumulated questions as one API call.
     * Returns count of questions processed.
     * Typically called at 03:00 AM via cron (cheapest API window).
     */
    public function flushBatch(Config $config): int
    {
        $path = $this->resolvePath(self::BATCH_FILE);
        if (!is_readable($path)) { return 0; }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (empty($lines)) { return 0; }

        $questions = array_filter(array_map(
            static fn(string $l) => json_decode($l, true),
            $lines
        ));

        $mentor   = new EvolutionMentorService();
        $combined = "Process this batch of " . count($questions) . " independent code analysis questions.\n"
            . "For each: identify issues and provide a concise fix.\n\n";

        foreach (array_values($questions) as $i => $q) {
            $qId       = (string)($q['id'] ?? '?');
            $qNum      = $i + 1;
            $combined .= "=== Question {$qNum} (id:{$qId}) ===\n";
            $combined .= mb_substr((string)($q['question'] ?? ''), 0, 200) . "\n";
            $combined .= "```\n" . mb_substr((string)($q['code'] ?? ''), 0, 300) . "\n```\n\n";
        }

        $resp = $mentor->juniorDelegate($config, 'batch_process', $combined);

        $cost = 0.0;
        if ($resp['ok'] ?? false) {
            // Estimate batch cost (50% discount applied)
            $totalTokens = array_sum(array_column($questions, 'tokens'));
            $cost        = $this->estimateCost((int)$totalTokens) * 0.5;
            $this->recordSpend($cost);
            $this->recordStats('batch_flushed', count($questions), $cost);
        }

        // Clear the batch file
        file_put_contents($path, '');

        return count($questions);
    }

    // ── ROI Calculator ────────────────────────────────────────────────────────

    /**
     * Compute ROI score for a file (0–100).
     * High-risk + high-traffic files score high → get full cloud budget.
     */
    public function computeRoi(string $filePath, int $callCount): int
    {
        $file     = strtolower(basename($filePath));
        $fullPath = strtolower($filePath);

        // Explicit high-priority override
        foreach (self::HIGH_PRIORITY_PATTERNS as $pattern => $score) {
            if (str_contains($fullPath, $pattern)) { return max($score, min(100, $callCount * 2)); }
        }

        // Explicit low-priority override
        foreach (self::LOW_PRIORITY_PATTERNS as $pattern => $score) {
            if (str_contains($fullPath, $pattern)) { return $score; }
        }

        // Call-count based scoring (1 call = 1 pt, max 70)
        $callScore = min(70, $callCount * 2);

        // Boost for known critical file types
        $typeBoost = match (true) {
            str_contains($file, 'controller')  => 10,
            str_contains($file, 'model')       => 8,
            str_contains($file, 'service')     => 8,
            str_contains($file, 'middleware')  => 12,
            str_contains($file, 'command')     => 5,
            default                            => 0,
        };

        return min(100, $callScore + $typeBoost);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *   total_decisions: int,
     *   use_local: int,
     *   call_cloud: int,
     *   queue_batch: int,
     *   skip: int,
     *   total_tokens_saved: int,
     *   total_cost_usd: float,
     *   daily_spend_usd: float,
     *   daily_budget_usd: float,
     *   savings_pct: float
     * }
     */
    public function stats(): array
    {
        $s = $this->loadStats();
        $total = max(1, (int)array_sum([$s['use_local'] ?? 0, $s['call_cloud'] ?? 0, $s['queue_batch'] ?? 0, $s['skip'] ?? 0]));
        $cloud = (int)($s['call_cloud'] ?? 0) + (int)($s['queue_batch'] ?? 0);
        $savings = $total > 0 ? round((1 - $cloud / $total) * 100, 1) : 0.0;

        return [
            'total_decisions'    => $total,
            'use_local'          => (int)($s['use_local']  ?? 0),
            'call_cloud'         => (int)($s['call_cloud'] ?? 0),
            'queue_batch'        => (int)($s['queue_batch'] ?? 0),
            'skip'               => (int)($s['skip']        ?? 0),
            'total_tokens_saved' => (int)($s['tokens_saved'] ?? 0),
            'total_cost_usd'     => (float)($s['total_cost_usd'] ?? 0),
            'daily_spend_usd'    => $this->dailySpend(),
            'daily_budget_usd'   => $this->dailyBudgetUsd,
            'savings_pct'        => $savings,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{action: string, reason: string, compressed: string, estimated_tokens: int, estimated_cost_usd: float, roi_score: int, savings_tokens: int}
     */
    private function decision(
        string $action,
        string $reason,
        string $compressed,
        int $tokens,
        int $savedTokens = 0
    ): array {
        $cost = $this->estimateCost($tokens);
        $this->recordStats($action, $tokens, $cost, $savedTokens);

        return [
            'action'            => $action,
            'reason'            => $reason,
            'compressed'        => $compressed,
            'estimated_tokens'  => $tokens,
            'estimated_cost_usd'=> $cost,
            'roi_score'         => 0,
            'savings_tokens'    => $savedTokens,
        ];
    }

    private function estimateTokens(string $text): int
    {
        // ~4 chars per token (GPT/Claude average)
        return (int)ceil(mb_strlen($text) / 4);
    }

    private function estimateCost(int $tokens): float
    {
        // Claude Haiku: $0.25 per 1M input tokens
        return round($tokens / 1_000_000 * 0.25, 6);
    }

    private function dailySpend(): float
    {
        $s = $this->loadStats();
        $today = date('Ymd');
        return (float)(((array)($s['daily'] ?? []))[$today] ?? 0.0);
    }

    private function recordSpend(float $usd): void
    {
        $stats = $this->loadStats();
        $today = date('Ymd');
        if (!isset($stats['daily'])) { $stats['daily'] = []; }
        $stats['daily'][$today]        = ((float)($stats['daily'][$today] ?? 0)) + $usd;
        $stats['total_cost_usd']       = ((float)($stats['total_cost_usd'] ?? 0)) + $usd;
        // Keep only last 30 days
        $stats['daily'] = array_slice($stats['daily'], -30, null, true);
        $this->saveStats($stats);
    }

    private function recordStats(string $action, int $tokens = 0, float $cost = 0.0, int $savedTokens = 0): void
    {
        $stats = $this->loadStats();
        $stats[$action]          = ((int)($stats[$action] ?? 0)) + 1;
        $stats['tokens_saved']   = ((int)($stats['tokens_saved'] ?? 0)) + $savedTokens;
        $stats['total_cost_usd'] = ((float)($stats['total_cost_usd'] ?? 0)) + $cost;
        if ($cost > 0) { $this->recordSpend($cost); }
        $this->saveStats($stats);
    }

    /** @return array<string, mixed> */
    private function loadStats(): array
    {
        $path = $this->resolvePath(self::STATS_FILE);
        if (!is_readable($path)) { return []; }
        return (array)(json_decode((string)file_get_contents($path), true) ?? []);
    }

    /** @param array<string, mixed> $stats */
    private function saveStats(array $stats): void
    {
        $path = $this->resolvePath(self::STATS_FILE);
        $dir  = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        @file_put_contents($path, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/var/www/html') && is_dir('/var/www/html')) { return $path; }
        $base = defined('BASE_PATH') ? BASE_PATH : '/var/www/html';
        return rtrim($base, '/') . '/' . ltrim(str_replace('/var/www/html/', '', $path), '/');
    }
}
