<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Blocks production shadow swap until staging has reported all-green samples for a full window.
 */
final class StagingMirrorGateService
{
    private const SAMPLES = 'storage/evolution/staging_mirror_samples.jsonl';

    /**
     * Human-readable block reason, or null if swap is allowed.
     */
    public static function reasonIfBlocked(Config $config): ?string
    {
        $sm = $config->get('evolution.staging_mirror', []);
        if (!is_array($sm) || !filter_var($sm['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }
        if (!filter_var($sm['require_green_before_prod_swap'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $hours = max(1, (int)($sm['green_window_hours'] ?? 24));
        $minSamples = max(1, (int)($sm['min_samples_in_window'] ?? $hours));

        $path = BASE_PATH . '/' . self::SAMPLES;
        if (!is_file($path)) {
            return 'Staging mirror: geen health samples — run `php ai_bridge.php evolution:staging-sample` op staging gedurende ' . $hours . ' uur.';
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return 'Staging mirror: sample file empty.';
        }

        $cutoff = time() - $hours * 3600;
        $relevant = [];
        foreach ($lines as $line) {
            $j = @json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $ts = strtotime((string)($j['ts'] ?? '')) ?: 0;
            if ($ts >= $cutoff) {
                $relevant[] = $j;
            }
        }

        if (count($relevant) < $minSamples) {
            return 'Staging mirror: nog maar ' . count($relevant) . ' sample(s) in ' . $hours . 'u venster (min ' . $minSamples . ').';
        }

        foreach ($relevant as $i => $j) {
            $c = (float)($j['composite'] ?? 0);
            if ($c < 100.0 - 0.001) {
                return 'Staging mirror: sample #' . ($i + 1) . ' composite=' . $c . ' (vereist 100).';
            }
        }

        return null;
    }

    /**
     * Append one staging health row (call from staging cron).
     */
    public static function appendSample(array $scores): void
    {
        $row = array_merge(['ts' => gmdate('c')], $scores);
        $path = BASE_PATH . '/' . self::SAMPLES;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        self::trimSamples($path, 5000);
        EvolutionLogger::log('staging_mirror', 'sample', [
            'composite' => $row['composite'] ?? null,
            'revenue' => $row['revenue'] ?? null,
            'chaos' => $row['chaos'] ?? null,
            'visual' => $row['visual'] ?? null,
        ]);
    }

    private static function trimSamples(string $path, int $maxLines): void
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= $maxLines) {
            return;
        }
        $keep = array_slice($lines, -$maxLines);
        @file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
    }
}
