<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Computes 0–100 health scores for staging mirror sampling (Revenue / Chaos / Visual).
 * Intended to run on the staging deployment on a schedule.
 */
final class StagingHealthAggregator
{
    /**
     * @return array{revenue: float, chaos: float, visual: float, composite: float, detail: array<string, mixed>}
     */
    public static function collect(Container $container): array
    {
        $cfg = $container->get('config');
        $sm = $cfg->get('evolution.staging_mirror', []);
        $relaxCro = is_array($sm) && filter_var($sm['relax_cro_when_empty'] ?? true, FILTER_VALIDATE_BOOL);

        $health = new HealthSnapshotService();
        if ($health->croHealthy()) {
            $revenue = 100.0;
        } elseif ($relaxCro) {
            $revenue = 100.0;
        } else {
            $revenue = 0.0;
        }

        $chaos = self::chaosScore();
        $visual = self::visualScore($container);

        $composite = min($revenue, $chaos, $visual);

        return [
            'revenue' => $revenue,
            'chaos' => $chaos,
            'visual' => $visual,
            'composite' => $composite,
            'detail' => [
                'cro_healthy' => $health->croHealthy(),
                'relax_cro' => $relaxCro,
            ],
        ];
    }

    private static function chaosScore(): float
    {
        $dir = BASE_PATH . '/storage/evolution/chaos_results';
        if (!is_dir($dir)) {
            return 100.0;
        }
        $files = glob($dir . '/chaos-*.json') ?: [];
        if ($files === []) {
            return 100.0;
        }
        rsort($files);
        $raw = @file_get_contents($files[0]);
        $decoded = is_string($raw) ? @json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return 0.0;
        }
        $sims = $decoded['simulations'] ?? [];
        if (!is_array($sims) || $sims === []) {
            return 100.0;
        }
        $passed = count(array_filter($sims, static fn(array $r) => (bool)($r['passed'] ?? false)));
        $total = count($sims);

        return $total > 0 ? round(100.0 * $passed / $total, 2) : 100.0;
    }

    private static function visualScore(Container $container): float
    {
        $script = BASE_PATH . '/tooling/scripts/architect-screenshot.mjs';
        if (!is_file($script)) {
            return 100.0;
        }
        $dir = BASE_PATH . '/storage/evolution/visual_regression';
        if (!is_dir($dir)) {
            return 100.0;
        }
        $files = glob($dir . '/*.json') ?: [];
        foreach ($files as $f) {
            $raw = @file_get_contents($f);
            $j = is_string($raw) ? @json_decode($raw, true) : null;
            if (is_array($j) && ($j['regression'] ?? false) === true) {
                return 0.0;
            }
        }

        return 100.0;
    }
}
