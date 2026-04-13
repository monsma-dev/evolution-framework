<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Regression intelligence: compares Pulse + A/B perf aggregates before vs after a patch batch to detect cross-layer degradation.
 */
final class EvolutionButterflyService
{
    private const SLOW_LOG = 'storage/evolution/slow_queries.jsonl';

    /**
     * Lightweight snapshot without running a new pulse (reads last pulse_state + AB aggregates).
     *
     * @return array<string, mixed>
     */
    public static function captureBaseline(Container $container): array
    {
        $pulse = EvolutionPulseService::lastState();
        $ab = AbPerformanceService::evaluate($container->get('config'));
        $slowLines = self::slowLogLineCount();

        return [
            'ts' => gmdate('c'),
            'pulse' => $pulse,
            'ab_eval' => $ab,
            'ab_avg_wall_ms' => self::abAvgWallMsLast(200),
            'slow_query_lines_24h_estimate' => $slowLines,
        ];
    }

    /**
     * @param array<string, mixed> $before from captureBaseline
     * @return array{regression: bool, message?: string, details?: array<string, mixed>}
     */
    public static function evaluateAfterBatch(Container $container, array $before, bool $hadSuccessfulApply): array
    {
        $cfg = $container->get('config');
        $bf = $cfg->get('evolution.butterfly', []);
        if (!is_array($bf) || !filter_var($bf['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['regression' => false];
        }
        if (!$hadSuccessfulApply) {
            return ['regression' => false];
        }

        $pulseSvc = new EvolutionPulseService($container);
        $afterPulse = $pulseSvc->runDeepPulse();
        $afterAbWall = self::abAvgWallMsLast(200);
        $afterSlow = self::slowLogLineCount();

        $beforeLat = self::extractPulseLatency($before['pulse'] ?? []);
        $afterLat = (float) ($afterPulse['latency_ms_total'] ?? self::extractPulseLatency(EvolutionPulseService::lastState()));

        $beforeWall = (float) ($before['ab_avg_wall_ms'] ?? 0);
        $minBase = (float) ($bf['min_baseline_pulse_ms'] ?? 40);
        $pulseSpike = $beforeLat >= $minBase
            && $afterLat > $beforeLat * (float) ($bf['pulse_latency_ratio_threshold'] ?? 1.45);
        $wallSpike = $beforeWall > 0.5 && $afterAbWall > $beforeWall * (float) ($bf['ab_wall_ratio_threshold'] ?? 1.35);

        $regression = ($pulseSpike && $afterLat > (float) ($bf['min_pulse_ms_for_alert'] ?? 120))
            || ($pulseSpike && $wallSpike);

        if (!$regression) {
            return ['regression' => false];
        }

        $msg = 'Subtiele systeem-degradatie gedetecteerd buiten de scope van de patch (vlindereffect): '
            . 'pulse latency voor/na ≈ ' . round($beforeLat, 1) . ' / ' . round($afterLat, 1) . ' ms; '
            . 'AB avg wall ms voor/na ≈ ' . round($beforeWall, 2) . ' / ' . round($afterAbWall, 2) . '.';

        $details = [
            'before_pulse_ms' => $beforeLat,
            'after_pulse_ms' => $afterLat,
            'before_ab_wall_ms' => $beforeWall,
            'after_ab_wall_ms' => $afterAbWall,
            'slow_query_delta' => $afterSlow - (int) ($before['slow_query_lines_24h_estimate'] ?? 0),
        ];

        $path = BASE_PATH . '/storage/evolution/butterfly_last_incident.json';
        @file_put_contents($path, json_encode([
            'ts' => gmdate('c'),
            'message' => $msg,
            'details' => $details,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

        EvolutionLogger::log('butterfly', 'regression_suspected', $details);
        LearningLoopService::record([
            'type' => 'butterfly',
            'target' => 'system',
            'severity' => 'low_autofix',
            'ok' => false,
            'error' => $msg,
        ]);

        return ['regression' => true, 'message' => $msg, 'details' => $details];
    }

    private static function extractPulseLatency(array $pulse): float
    {
        return (float) ($pulse['latency_ms_total'] ?? 0);
    }

    private static function abAvgWallMsLast(int $maxLines): float
    {
        $metricsFile = BASE_PATH . '/storage/evolution/ab_perf_metrics.jsonl';
        if (!is_file($metricsFile)) {
            return 0.0;
        }
        $lines = @file($metricsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $sum = 0.0;
        $n = 0;
        foreach (array_slice($lines, -$maxLines) as $line) {
            $j = json_decode((string) $line, true);
            if (!is_array($j) || !isset($j['wall_ms'])) {
                continue;
            }
            $sum += (float) $j['wall_ms'];
            $n++;
        }

        return $n > 0 ? round($sum / $n, 3) : 0.0;
    }

    private static function slowLogLineCount(): int
    {
        $path = BASE_PATH . '/' . self::SLOW_LOG;
        if (!is_file($path)) {
            return 0;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? count($lines) : 0;
    }
}
