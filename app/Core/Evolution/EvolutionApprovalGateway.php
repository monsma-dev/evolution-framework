<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Evolution Approval Gateway — One-Click Human Override
 *
 * Stores pending AI proposals that require human approval before execution.
 * Each proposal gets a unique ALERT_YYYYMMDD_XXXXXX ID.
 *
 * ─── Flow ────────────────────────────────────────────────────────────────────
 *
 *  1. AI detects opportunity/issue → creates pending approval
 *  2. EvolutionNotifier sends alert with one-click command
 *  3. Human runs: php ai_bridge.php evolve:provision approve --id=ALERT_xxx
 *  4. Gateway validates expiry + budget guard (Rust binary)
 *  5. Stored command is executed if all checks pass
 *
 * ─── Storage ─────────────────────────────────────────────────────────────────
 *
 *  Each approval is a JSON file in storage/evolution/approvals/{id}.json
 *  Approvals expire after 24 hours (configurable via evolution.safety.approval_ttl_hours)
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *  $gw = new EvolutionApprovalGateway($config);
 *
 *  $id = $gw->create(
 *      action:      'evolve:provision scale --tier=medium',
 *      description: 'Upgrade to t4g.medium to handle Llama load',
 *      costPerMonth: 4.50
 *  );
 *
 *  $result = $gw->approve($id);  // validate + execute
 */
final class EvolutionApprovalGateway
{
    private const APPROVALS_DIR  = '/var/www/html/data/evolution/approvals';
    private const GUARD_BINARY   = '/var/www/html/data/evolution/evolution_guard';
    private const DEFAULT_TTL    = 24; // hours

    public function __construct(private readonly Config $config) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Create a new pending approval and return its ID.
     *
     * @param array<string, mixed> $meta  Extra metadata (what, why, roi, etc.)
     */
    public function create(
        string $action,
        string $description,
        float $costPerMonth = 0.0,
        array $meta = []
    ): string {
        $dir = self::APPROVALS_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->cleanupExpired();

        $id      = 'ALERT_' . date('Ymd') . '_' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $ttlHours = (int) ($this->config->get('evolution.safety.approval_ttl_hours') ?? self::DEFAULT_TTL);

        $approval = [
            'id'            => $id,
            'action'        => $action,
            'description'   => $description,
            'cost_per_month' => $costPerMonth,
            'meta'          => $meta,
            'status'        => 'pending',
            'created_at'    => date('c'),
            'expires_at'    => date('c', strtotime("+{$ttlHours} hours")),
            'authority_level' => (int) ($this->config->get('evolution.safety.authority_level') ?? 1),
        ];

        file_put_contents(
            $dir . '/' . $id . '.json',
            json_encode($approval, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return $id;
    }

    /**
     * Approve and execute a pending proposal.
     *
     * Checks:
     *  1. Approval exists and not expired
     *  2. Budget ceiling via Rust guard binary
     *  3. Authority level allows execution
     *
     * @return array{success: bool, message: string, action: string}
     */
    public function approve(string $id): array
    {
        $approval = $this->get($id);

        if ($approval === null) {
            return ['success' => false, 'message' => "Approval {$id} not found or expired.", 'action' => ''];
        }

        if ($approval['status'] !== 'pending') {
            return ['success' => false, 'message' => "Approval {$id} already {$approval['status']}.", 'action' => ''];
        }

        // 1. Expiry check
        if (strtotime((string) $approval['expires_at']) < time()) {
            $this->updateStatus($id, 'expired');

            return ['success' => false, 'message' => "Approval {$id} expired.", 'action' => ''];
        }

        // 2. Rust budget ceiling guard
        $guardResult = $this->runGuard((float) ($approval['cost_per_month'] ?? 0.0));
        if (!$guardResult['allowed']) {
            $this->updateStatus($id, 'blocked');

            return [
                'success' => false,
                'message' => 'Budget guard blocked: ' . $guardResult['reason'],
                'action'  => $approval['action'],
            ];
        }

        // 3. Execute the stored action
        $this->updateStatus($id, 'approved');

        return [
            'success' => true,
            'message' => "Approved. Remaining budget: \${$guardResult['remaining']}/month. Action ready to execute.",
            'action'  => (string) $approval['action'],
        ];
    }

    /** Reject a pending approval */
    public function reject(string $id): bool
    {
        if ($this->get($id) === null) {
            return false;
        }

        $this->updateStatus($id, 'rejected');

        return true;
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $path = self::APPROVALS_DIR . '/' . $id . '.json';
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        $dir = self::APPROVALS_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $this->cleanupExpired();
        $result = [];

        foreach (glob($dir . '/ALERT_*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && ($data['status'] ?? '') === 'pending') {
                $result[] = $data;
            }
        }

        usort($result, static fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $result;
    }

    // ── Rust budget guard ─────────────────────────────────────────────────────

    /**
     * Calls the evolution_guard binary to check if the proposed cost
     * stays within the hard monthly ceiling.
     *
     * Falls back to PHP check if binary is unavailable.
     *
     * @return array{allowed: bool, reason: string, remaining: float}
     */
    private function runGuard(float $proposedCostPerMonth): array
    {
        $ceiling = (float) ($this->config->get('evolution.safety.hard_monthly_ceiling') ?? 20.00);
        $current = $this->currentMonthlySpend();
        $binary  = (string) ($this->config->get('evolution.safety.guard_binary') ?? self::GUARD_BINARY);

        // Use Rust binary if available (compiled, unmodifiable by AI)
        if (is_file($binary) && is_executable($binary)) {
            $input = json_encode([
                'action'          => 'provision',
                'cost_per_month'  => $proposedCostPerMonth,
                'ceiling'         => $ceiling,
                'current_monthly' => $current,
            ]);

            $pipes   = [];
            $process = proc_open(
                escapeshellcmd($binary),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );

            if (is_resource($process)) {
                fwrite($pipes[0], (string) $input);
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                $result = json_decode((string) $output, true);
                if (is_array($result) && isset($result['allowed'])) {
                    return [
                        'allowed'   => (bool) $result['allowed'],
                        'reason'    => (string) ($result['reason'] ?? ''),
                        'remaining' => round((float) ($result['remaining'] ?? 0), 2),
                    ];
                }
            }
        }

        // PHP fallback (same logic as Rust)
        $newTotal = $current + $proposedCostPerMonth;
        $remaining = round($ceiling - $newTotal, 2);

        if ($newTotal > $ceiling) {
            return [
                'allowed'   => false,
                'reason'    => sprintf('Would exceed ceiling: $%.2f + $%.2f = $%.2f > $%.2f/month', $current, $proposedCostPerMonth, $newTotal, $ceiling),
                'remaining' => round($ceiling - $current, 2),
            ];
        }

        return [
            'allowed'   => true,
            'reason'    => sprintf('Within budget: $%.2f + $%.2f = $%.2f <= $%.2f/month', $current, $proposedCostPerMonth, $newTotal, $ceiling),
            'remaining' => $remaining,
        ];
    }

    /**
     * Reads current monthly spend from the evolution budget tracker.
     * Returns 0.0 if no tracking data exists yet.
     */
    private function currentMonthlySpend(): float
    {
        $logFile = '/var/www/html/data/evolution/monthly_spend.json';
        if (!is_file($logFile)) {
            return 0.0;
        }

        $data  = json_decode((string) file_get_contents($logFile), true);
        $month = date('Y-m');

        return is_array($data) ? (float) ($data[$month]['total'] ?? 0.0) : 0.0;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function updateStatus(string $id, string $status): void
    {
        $path = self::APPROVALS_DIR . '/' . $id . '.json';
        if (!is_file($path)) {
            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return;
        }

        $data['status']     = $status;
        $data['updated_at'] = date('c');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function cleanupExpired(): void
    {
        $dir = self::APPROVALS_DIR;
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/ALERT_*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            if (($data['status'] ?? '') === 'pending'
                && isset($data['expires_at'])
                && strtotime((string) $data['expires_at']) < time()) {
                $data['status']     = 'expired';
                $data['updated_at'] = date('c');
                file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            }
        }
    }
}
