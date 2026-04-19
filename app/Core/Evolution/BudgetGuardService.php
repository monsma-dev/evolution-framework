<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use PDO;

/**
 * BudgetGuardService — Transactionele Safe-Spend Gate.
 *
 * Per-taak €0.10 drempel:
 *  < €0.10  → proceed (directe cloud API-call)
 *  ≥ €0.10  → reroute_ollama (lokale Ollama als primaire poging)
 *  Ollama niet geschikt of niet bereikbaar → pending_approval (wachtrij + dashboard alert)
 *
 * Elke omleiding of blokkering wordt gelogd in storage/logs/budget_alerts.log.
 * Goedkeuringen worden opgeslagen in de DB-tabel budget_task_approvals.
 */
final class BudgetGuardService
{
    public const TASK_ALERT_LOG   = 'storage/logs/budget_alerts.log';
    public const THRESHOLD_EUR    = 0.10;

    // Task types that Ollama (local R1:1.5b) can handle reliably
    private const OLLAMA_CAPABLE_TASKS = [
        'formatting', 'classification', 'summarize', 'intent_score',
        'validation', 'tagging', 'sentiment', 'keyword_extract',
        'slug_generate', 'spell_check', 'translate_short',
    ];

    // Task types that always require cloud (complex reasoning, code generation, etc.)
    private const CLOUD_ONLY_TASKS = [
        'strategy', 'code_generation', 'patch_apply', 'architect_review',
        'consensus_judge', 'legal_analysis', 'price_prediction',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ?PDO $db = null
    ) {}

    // ─── Public API ──────────────────────────────────────────────────────────────

    /**
     * Pre-flight cost check before executing any AI API call.
     *
     * @param array<string, mixed> $ctx  e.g. ['user_id' => 1, 'listing_id' => 5, 'payload_hash' => '...']
     *
     * @return array{
     *   action: 'proceed'|'reroute_ollama'|'pending_approval',
     *   estimated_eur: float,
     *   threshold_eur: float,
     *   reason: string,
     *   task_id: string|null,
     *   ollama_capable: bool
     * }
     */
    public function preflightCheck(
        string $taskType,
        string $model,
        int    $inputTokens,
        int    $maxOutputTokens,
        array  $ctx = []
    ): array {
        $estimatedEur = $this->estimateEur($model, $inputTokens, $maxOutputTokens);
        $ollamaCapable = $this->isOllamaCapable($taskType);

        // Under threshold → proceed directly, no guard needed
        if ($estimatedEur < self::THRESHOLD_EUR) {
            return [
                'action'        => 'proceed',
                'estimated_eur' => round($estimatedEur, 4),
                'threshold_eur' => self::THRESHOLD_EUR,
                'reason'        => 'under_threshold',
                'task_id'       => null,
                'ollama_capable' => $ollamaCapable,
            ];
        }

        // Over threshold — try to reroute to Ollama first
        if ($ollamaCapable) {
            $this->logAlert('reroute_ollama', $taskType, $model, $estimatedEur, $inputTokens, $maxOutputTokens, $ctx);
            return [
                'action'        => 'reroute_ollama',
                'estimated_eur' => round($estimatedEur, 4),
                'threshold_eur' => self::THRESHOLD_EUR,
                'reason'        => 'cost_above_threshold_ollama_capable',
                'task_id'       => null,
                'ollama_capable' => true,
            ];
        }

        // Cloud-only task over threshold — queue for manual approval
        $taskId = $this->queuePendingApproval($taskType, $model, $estimatedEur, $inputTokens, $maxOutputTokens, $ctx);
        $this->logAlert('pending_approval', $taskType, $model, $estimatedEur, $inputTokens, $maxOutputTokens, $ctx, $taskId);

        return [
            'action'        => 'pending_approval',
            'estimated_eur' => round($estimatedEur, 4),
            'threshold_eur' => self::THRESHOLD_EUR,
            'reason'        => 'cost_above_threshold_cloud_only',
            'task_id'       => $taskId,
            'ollama_capable' => false,
        ];
    }

    /**
     * Check if a specific task has been approved by admin in the cockpit.
     * Returns true and marks the task as 'approved' consumed if found.
     */
    public function isApproved(string $taskId): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT id FROM budget_task_approvals WHERE task_id = :id AND status = :status LIMIT 1'
            );
            $stmt->execute([':id' => $taskId, ':status' => 'approved']);
            if ($stmt->fetch() === false) {
                return false;
            }
            // Mark as consumed so it can't be double-used
            $upd = $this->db->prepare(
                'UPDATE budget_task_approvals SET status = :s, approved_at = NOW() WHERE task_id = :id'
            );
            $upd->execute([':s' => 'executed', ':id' => $taskId]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * List pending approval tasks for the Master Control Cockpit.
     * @return list<array<string, mixed>>
     */
    public function listPendingApprovals(): array
    {
        if ($this->db === null) {
            return [];
        }
        try {
            $stmt = $this->db->query(
                'SELECT task_id, task_type, model, estimated_eur, input_tokens, output_tokens, context_json, created_at
                 FROM budget_task_approvals WHERE status = \'pending\' ORDER BY created_at DESC LIMIT 50'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Admin approves a pending task (called from cockpit).
     */
    public function approve(string $taskId, int $adminId): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE budget_task_approvals SET status = :s, approved_by = :admin, approved_at = NOW()
                 WHERE task_id = :id AND status = :pending'
            );
            $ok = $stmt->execute([
                ':s'       => 'approved',
                ':admin'   => $adminId,
                ':id'      => $taskId,
                ':pending' => 'pending',
            ]);
            if ($ok && $stmt->rowCount() > 0) {
                EvolutionLogger::log('budget_guard', 'TASK_APPROVED', [
                    'task_id'  => $taskId,
                    'admin_id' => $adminId,
                ]);
                return true;
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Admin rejects a pending task.
     */
    public function reject(string $taskId, int $adminId): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE budget_task_approvals SET status = :s, approved_by = :admin, approved_at = NOW()
                 WHERE task_id = :id AND status = :pending'
            );
            $ok = $stmt->execute([
                ':s'       => 'rejected',
                ':admin'   => $adminId,
                ':id'      => $taskId,
                ':pending' => 'pending',
            ]);
            if ($ok) {
                EvolutionLogger::log('budget_guard', 'TASK_REJECTED', [
                    'task_id'  => $taskId,
                    'admin_id' => $adminId,
                ]);
            }
            return $ok && $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pending approval count — for dashboard badge.
     */
    public function pendingCount(): int
    {
        if ($this->db === null) {
            return 0;
        }
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM budget_task_approvals WHERE status = 'pending'");
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ─── Pricing ─────────────────────────────────────────────────────────────────

    /**
     * EUR estimate using pricing from evolution.budget_guard config or hardcoded fallbacks.
     * Pricing is in EUR per million tokens.
     */
    public function estimateEur(string $model, int $inputTokens, int $maxOutputTokens): float
    {
        $bg = $this->budgetConfig();

        $inM  = $inputTokens / 1_000_000;
        $outM = $maxOutputTokens / 1_000_000;

        $inMap  = is_array($bg) ? ($bg['input_price_per_million_tokens_eur'] ?? []) : [];
        $outMap = is_array($bg) ? ($bg['output_price_per_million_tokens_eur'] ?? []) : [];

        if (!is_array($inMap))  { $inMap  = []; }
        if (!is_array($outMap)) { $outMap = []; }

        // Fallback pricing (EUR/M tokens) — aligned with DeepSeek public pricing
        $defaultIn  = (float)($inMap['default']  ?? 0.27);  // ~$0.28/M input
        $defaultOut = (float)($outMap['default'] ?? 1.10);  // ~$1.10/M output

        $pin  = (float)($inMap[$model]  ?? $defaultIn);
        $pout = (float)($outMap[$model] ?? $defaultOut);

        return $inM * $pin + $outM * $pout;
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function isOllamaCapable(string $taskType): bool
    {
        $taskLower = strtolower($taskType);
        if (in_array($taskLower, self::CLOUD_ONLY_TASKS, true)) {
            return false;
        }
        return in_array($taskLower, self::OLLAMA_CAPABLE_TASKS, true);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function queuePendingApproval(
        string $taskType,
        string $model,
        float  $estimatedEur,
        int    $inputTokens,
        int    $maxOutputTokens,
        array  $ctx
    ): string {
        $taskId = 'btask_' . bin2hex(random_bytes(8));

        if ($this->db !== null) {
            try {
                $stmt = $this->db->prepare(
                    'INSERT INTO budget_task_approvals
                     (task_id, task_type, model, estimated_eur, input_tokens, output_tokens, context_json, status, created_at)
                     VALUES (:id, :type, :model, :eur, :in, :out, :ctx, :status, NOW())'
                );
                $stmt->execute([
                    ':id'     => $taskId,
                    ':type'   => $taskType,
                    ':model'  => $model,
                    ':eur'    => round($estimatedEur, 4),
                    ':in'     => $inputTokens,
                    ':out'    => $maxOutputTokens,
                    ':ctx'    => json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':status' => 'pending',
                ]);
            } catch (\Throwable) {
                // DB unavailable — task_id still usable for log correlation
            }
        }

        return $taskId;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function logAlert(
        string  $action,
        string  $taskType,
        string  $model,
        float   $estimatedEur,
        int     $inputTokens,
        int     $maxOutputTokens,
        array   $ctx,
        ?string $taskId = null
    ): void {
        $line = json_encode([
            'ts'            => gmdate('c'),
            'action'        => $action,
            'task_type'     => $taskType,
            'model'         => $model,
            'estimated_eur' => round($estimatedEur, 4),
            'threshold_eur' => self::THRESHOLD_EUR,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $maxOutputTokens,
            'task_id'       => $taskId,
            'ctx'           => $ctx,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $path = $base . '/' . self::TASK_ALERT_LOG;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);

        // Also write to the central Evolution log for Police-Agent visibility
        EvolutionLogger::log('budget_guard', 'SAFE_SPEND_' . strtoupper($action), [
            'task_type'     => $taskType,
            'model'         => $model,
            'estimated_eur' => round($estimatedEur, 4),
            'task_id'       => $taskId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function budgetConfig(): ?array
    {
        $evo = $this->config->get('evolution', []);
        if (!is_array($evo)) {
            return null;
        }
        $bg = $evo['budget_guard'] ?? [];
        return is_array($bg) ? $bg : null;
    }
}
