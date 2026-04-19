<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Predictive Auto-Scale — Traffic Economist
 *
 * Monitors request/web-vitals data for traffic spikes and
 * triggers provision proposals when thresholds are breached.
 * Also handles dynamic pricing signals.
 *
 * Works alongside EvolutionApprovalGateway — proposals still
 * require human approval (Authority Level 1 default).
 */
final class TrafficEconomistService
{
    private const VITALS_LOG   = '/var/www/html/data/evolution/web_vitals.jsonl';
    private const TRAFFIC_LOG  = '/var/www/html/data/evolution/traffic_spikes.jsonl';
    private const SCALE_LOCK   = '/var/www/html/data/evolution/traffic_scale.lock';

    private const SPIKE_WINDOW_MINUTES = 5;
    private const SPIKE_MULTIPLIER     = 2.5;   // 2.5× baseline = spike
    private const COOLDOWN_MINUTES     = 30;    // no duplicate proposals within 30 min
    private const HIGH_LOAD_PCT        = 75.0;  // CPU % that triggers scale

    public function __construct(private readonly Config $config) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Main entry point: analyse traffic and trigger a provision proposal if needed.
     *
     * @return array{action: string, reason: string, proposal_id?: string}
     */
    public function analyse(): array
    {
        if ($this->inCooldown()) {
            return ['action' => 'cooldown', 'reason' => 'Scale proposal already pending — waiting for human approval'];
        }

        $spike = $this->detectSpike();
        if (!$spike['detected']) {
            return ['action' => 'normal', 'reason' => $spike['reason']];
        }

        // Create a provision proposal via the Approval Gateway
        $gateway = new EvolutionApprovalGateway($this->config);
        $budget  = $this->estimateCost($spike);

        $id = $gateway->create(
            'traffic_scale_up',
            $spike['description'] ?? 'Traffic spike auto-scale',
            $budget,
            ['trigger' => $spike, 'type' => 'traffic_scale'],
        );

        if ($id === '') {
            return ['action' => 'blocked', 'reason' => 'Rust guard blocked proposal (budget ceiling)'];
        }

        // Write spike event to log
        $this->logSpike($spike, $id);

        // Set cooldown lock
        file_put_contents(self::SCALE_LOCK, (string)time());

        // Notify via EvolutionNotifier if configured
        $this->notify($spike, $id, $budget);

        return [
            'action'      => 'proposed',
            'reason'      => $spike['description'],
            'proposal_id' => $id,
        ];
    }

    /**
     * Record a web vitals data point (called from the API ingest endpoint).
     *
     * @param array<string, mixed> $vitals
     */
    public function recordVitals(array $vitals): void
    {
        $dir = dirname(self::VITALS_LOG);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $entry = [
            'ts'      => time(),
            'lcp'     => (float)($vitals['lcp'] ?? 0),
            'fid'     => (float)($vitals['fid'] ?? 0),
            'cls'     => (float)($vitals['cls'] ?? 0),
            'load_ms' => (float)($vitals['load_ms'] ?? 0),
            'path'    => substr((string)($vitals['path'] ?? ''), 0, 120),
        ];
        file_put_contents(self::VITALS_LOG, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Get traffic trend for the portal dashboard.
     *
     * @return array<string, mixed>
     */
    public function trend(): array
    {
        $recent = $this->recentRequestCounts(30);
        $baseline = $this->baseline(60);

        return [
            'recent_rpm'    => $recent,
            'baseline_rpm'  => $baseline,
            'spike_ratio'   => $baseline > 0 ? round($recent / $baseline, 2) : 1.0,
            'load_avg'      => $this->loadAvg(),
            'in_spike'      => ($baseline > 0 && $recent >= $baseline * self::SPIKE_MULTIPLIER),
            'last_spike'    => $this->lastSpikeTime(),
            'cooldown_left' => max(0, self::COOLDOWN_MINUTES * 60 - (time() - (int)@file_get_contents(self::SCALE_LOCK))),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return array{detected: bool, reason: string, description?: string, rpm?: int, baseline?: int, load?: float} */
    private function detectSpike(): array
    {
        $recentRpm = $this->recentRequestCounts(self::SPIKE_WINDOW_MINUTES);
        $baseline  = $this->baseline(60);
        $load      = $this->loadAvg();

        // Not enough data
        if ($baseline < 5) {
            return ['detected' => false, 'reason' => 'Insufficient baseline data'];
        }

        // Traffic spike detection
        if ($recentRpm >= $baseline * self::SPIKE_MULTIPLIER) {
            return [
                'detected'    => true,
                'reason'      => 'Traffic spike',
                'description' => sprintf(
                    'Traffic spike detected: %d rpm vs baseline %d rpm (%.1f×). Auto-scale proposal created.',
                    $recentRpm, $baseline, $recentRpm / max(1, $baseline)
                ),
                'rpm'      => $recentRpm,
                'baseline' => $baseline,
                'load'     => $load,
                'type'     => 'traffic',
            ];
        }

        // CPU load spike detection
        if ($load > self::HIGH_LOAD_PCT) {
            return [
                'detected'    => true,
                'reason'      => 'High CPU load',
                'description' => sprintf(
                    'CPU load %.1f%% exceeds threshold. Scale-up proposal created.',
                    $load
                ),
                'rpm'      => $recentRpm,
                'baseline' => $baseline,
                'load'     => $load,
                'type'     => 'cpu',
            ];
        }

        return ['detected' => false, 'reason' => sprintf('Normal: %d rpm, load %.1f%%', $recentRpm, $load)];
    }

    /** Count vitals entries in the last N minutes as proxy for RPM. */
    private function recentRequestCounts(int $minutes): int
    {
        if (!is_file(self::VITALS_LOG)) {
            return 0;
        }

        $cutoff = time() - ($minutes * 60);
        $count  = 0;

        foreach (array_reverse(array_filter(explode("\n", (string)file_get_contents(self::VITALS_LOG)))) as $line) {
            $d = json_decode($line, true);
            if (!is_array($d)) {
                continue;
            }
            if ((int)($d['ts'] ?? 0) < $cutoff) {
                break;
            }
            $count++;
        }

        return (int)round($count / max(1, $minutes));
    }

    private function baseline(int $minutes): int
    {
        return $this->recentRequestCounts($minutes);
    }

    private function loadAvg(): float
    {
        if (!is_readable('/proc/loadavg')) {
            return 0.0;
        }
        $parts = explode(' ', (string)file_get_contents('/proc/loadavg'));
        $nCpu  = (int)shell_exec('nproc') ?: 1;
        return round(((float)($parts[0] ?? 0)) / $nCpu * 100, 1);
    }

    private function inCooldown(): bool
    {
        if (!is_file(self::SCALE_LOCK)) {
            return false;
        }
        $last = (int)file_get_contents(self::SCALE_LOCK);
        return (time() - $last) < (self::COOLDOWN_MINUTES * 60);
    }

    /** @param array<string, mixed> $spike */
    private function estimateCost(array $spike): float
    {
        // Simple heuristic: 1 additional t3.small = ~$0.023/hour
        return 0.023 * 24; // 1 day estimate
    }

    /** @param array<string, mixed> $spike */
    private function logSpike(array $spike, string $proposalId): void
    {
        $dir = dirname(self::TRAFFIC_LOG);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            self::TRAFFIC_LOG,
            json_encode(array_merge($spike, ['proposal_id' => $proposalId, 'logged_at' => date('c')])) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /** @param array<string, mixed> $spike */
    private function notify(array $spike, string $proposalId, float $budget): void
    {
        try {
            $notifier = new EvolutionNotifier($this->config);
            $notifier->businessCase(
                what: '📈 Traffic Spike — Auto-Scale Proposal',
                why: $spike['description'] ?? 'Traffic anomaly detected',
                cost: sprintf('$%.3f/day', $budget),
                roi: 'Scale-up avoids potential downtime and revenue loss.',
                approvalId: $proposalId,
                command: 'php ai_bridge.php evolve:provision approve ' . $proposalId,
            );
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    private function lastSpikeTime(): ?string
    {
        if (!is_file(self::TRAFFIC_LOG)) {
            return null;
        }
        $lines = array_filter(explode("\n", (string)file_get_contents(self::TRAFFIC_LOG)));
        $last  = end($lines);
        if (!$last) {
            return null;
        }
        $d = json_decode($last, true);
        return is_array($d) ? (string)($d['logged_at'] ?? '') : null;
    }
}
