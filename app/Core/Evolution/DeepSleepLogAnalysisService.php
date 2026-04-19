<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Monthly-style aggregation over error logs to surface slow-burn regressions below Guard Dog spike thresholds.
 */
final class DeepSleepLogAnalysisService
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(Config $config): array
    {
        $ds = $config->get('evolution.deep_sleep', []);
        if (!is_array($ds) || !filter_var($ds['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'deep_sleep disabled'];
        }
        $days = max(7, min(90, (int)($ds['days'] ?? 30)));

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $daily[$d] = $this->countErrorLinesForDate($d);
        }

        $values = array_values($daily);
        $n = count($values);
        $firstWeek = $n >= 7 ? (int)round(array_sum(array_slice($values, 0, 7)) / 7) : 0;
        $lastWeek = $n >= 7 ? (int)round(array_sum(array_slice($values, -7)) / 7) : 0;

        $silentCandidates = [];
        if ($firstWeek > 0 && $lastWeek > $firstWeek * 1.1) {
            $silentCandidates[] = 'Average daily errors rose ~' . round((($lastWeek / max(1, $firstWeek)) - 1) * 100) . '% comparing first vs last week — possible silent regression (under 20% short-window spike).';
        }

        $evolutionTail = $this->tailEvolutionLogEntries(400);

        $report = [
            'ok' => true,
            'generated_at' => gmdate('c'),
            'window_days' => $days,
            'daily_error_counts' => $daily,
            'avg_first_week' => $firstWeek,
            'avg_last_week' => $lastWeek,
            'silent_regression_hints' => $silentCandidates,
            'evolution_log_tail' => $evolutionTail,
        ];

        $outPath = BASE_PATH . '/data/evolution/deep_sleep_last.json';
        $dir = dirname($outPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        EvolutionLogger::log('deep_sleep', 'analysis', [
            'days' => $days,
            'avg_first_week' => $firstWeek,
            'avg_last_week' => $lastWeek,
            'hints' => count($silentCandidates),
        ]);

        return $report;
    }

    private function countErrorLinesForDate(string $ymd): int
    {
        $file = BASE_PATH . '/data/logs/errors/' . $ymd . '.log';
        if (!is_file($file)) {
            return 0;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? count($lines) : 0;
    }

    /**
     * @return list<string>
     */
    private function tailEvolutionLogEntries(int $maxLines): array
    {
        $path = BASE_PATH . '/data/evolution/evolution.log';
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        return array_slice($lines, -$maxLines);
    }
}
