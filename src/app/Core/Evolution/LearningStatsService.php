<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Aggregates learning_history.jsonl into dashboard statistics.
 * Powers the AI Evolution widget in the admin architect workspace.
 */
final class LearningStatsService
{
    /**
     * @return array{
     *   total: int,
     *   success: int,
     *   failed: int,
     *   success_rate_pct: float,
     *   rollbacks: int,
     *   self_corrections: int,
     *   visual_regressions: int,
     *   policy_violations: int,
     *   immune_blocked: int,
     *   by_severity: array<string, array{total: int, ok: int, failed: int}>,
     *   by_type: array<string, array{total: int, ok: int, failed: int}>,
     *   recent_failures: list<array{ts: string, target: string, error: string}>,
     *   streak: array{current: int, best: int, type: string},
     *   last_24h: array{total: int, success: int, failed: int},
     *   last_7d: array{total: int, success: int, failed: int},
     *   daily_trend: list<array{date: string, success: int, failed: int}>
     * }
     */
    public function aggregate(): array
    {
        $entries = $this->readAll();

        $total = count($entries);
        $success = 0;
        $failed = 0;
        $rollbacks = 0;
        $selfCorrections = 0;
        $visualRegressions = 0;
        $policyViolations = 0;
        $immuneBlocked = 0;

        $bySeverity = [];
        $byType = [];
        $recentFailures = [];
        $dailyBuckets = [];

        $now = time();
        $last24h = ['total' => 0, 'success' => 0, 'failed' => 0];
        $last7d = ['total' => 0, 'success' => 0, 'failed' => 0];

        $streakCurrent = 0;
        $streakBest = 0;
        $streakType = 'success';
        $lastOk = null;

        foreach ($entries as $e) {
            $ok = (bool)($e['ok'] ?? false);
            $severity = (string)($e['severity'] ?? 'unknown');
            $type = (string)($e['type'] ?? 'unknown');
            $ts = (string)($e['ts'] ?? '');
            $epoch = $ts !== '' ? (strtotime($ts) ?: 0) : 0;

            if ($ok) {
                $success++;
            } else {
                $failed++;
            }

            if ($e['rolled_back'] ?? false) {
                $rollbacks++;
            }
            if ($e['visual_regression'] ?? false) {
                $visualRegressions++;
            }
            if (str_contains((string)($e['error'] ?? ''), 'Policy violation')) {
                $policyViolations++;
            }
            if (str_contains((string)($e['error'] ?? ''), 'Hot-path immune')) {
                $immuneBlocked++;
            }
            if (isset($e['self_corrected']) || str_contains((string)($e['self_correction_of'] ?? ''), '')) {
                if (isset($e['self_correction_of'])) {
                    $selfCorrections++;
                }
            }

            if (!isset($bySeverity[$severity])) {
                $bySeverity[$severity] = ['total' => 0, 'ok' => 0, 'failed' => 0];
            }
            $bySeverity[$severity]['total']++;
            $bySeverity[$severity][$ok ? 'ok' : 'failed']++;

            if (!isset($byType[$type])) {
                $byType[$type] = ['total' => 0, 'ok' => 0, 'failed' => 0];
            }
            $byType[$type]['total']++;
            $byType[$type][$ok ? 'ok' : 'failed']++;

            if ($epoch > 0) {
                if ($now - $epoch <= 86400) {
                    $last24h['total']++;
                    $last24h[$ok ? 'success' : 'failed']++;
                }
                if ($now - $epoch <= 604800) {
                    $last7d['total']++;
                    $last7d[$ok ? 'success' : 'failed']++;
                }

                $day = date('Y-m-d', $epoch);
                if (!isset($dailyBuckets[$day])) {
                    $dailyBuckets[$day] = ['success' => 0, 'failed' => 0];
                }
                $dailyBuckets[$day][$ok ? 'success' : 'failed']++;
            }

            if (!$ok && count($recentFailures) < 5) {
                $recentFailures[] = [
                    'ts' => $ts,
                    'target' => (string)($e['target'] ?? ''),
                    'error' => substr((string)($e['error'] ?? $e['rollback_reason'] ?? ''), 0, 120),
                ];
            }

            if ($lastOk === null) {
                $lastOk = $ok;
                $streakCurrent = 1;
                $streakType = $ok ? 'success' : 'failure';
            } elseif ($ok === $lastOk) {
                $streakCurrent++;
            } else {
                if ($streakCurrent > $streakBest) {
                    $streakBest = $streakCurrent;
                }
                $streakCurrent = 1;
                $streakType = $ok ? 'success' : 'failure';
                $lastOk = $ok;
            }
        }
        if ($streakCurrent > $streakBest) {
            $streakBest = $streakCurrent;
        }

        $dailyTrend = [];
        ksort($dailyBuckets);
        foreach (array_slice($dailyBuckets, -14, null, true) as $date => $counts) {
            $dailyTrend[] = ['date' => $date, 'success' => $counts['success'], 'failed' => $counts['failed']];
        }

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'success_rate_pct' => $total > 0 ? round(($success / $total) * 100, 1) : 0,
            'rollbacks' => $rollbacks,
            'self_corrections' => $selfCorrections,
            'visual_regressions' => $visualRegressions,
            'policy_violations' => $policyViolations,
            'immune_blocked' => $immuneBlocked,
            'by_severity' => $bySeverity,
            'by_type' => $byType,
            'recent_failures' => array_reverse($recentFailures),
            'streak' => ['current' => $streakCurrent, 'best' => $streakBest, 'type' => $streakType],
            'last_24h' => $last24h,
            'last_7d' => $last7d,
            'daily_trend' => $dailyTrend,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readAll(): array
    {
        $path = BASE_PATH . '/storage/evolution/learning_history.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $entries = [];
        foreach ($lines as $line) {
            $j = @json_decode($line, true);
            if (is_array($j)) {
                $entries[] = $j;
            }
        }

        return $entries;
    }
}
