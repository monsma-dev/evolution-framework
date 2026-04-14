<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Revenue Guard: monitors the business impact of AI changes.
 *
 * Tracks the conversion ratio (page_views -> clicks/conversions) in CRO data.
 * If conversion drops significantly after a ui_autofix patch (even with zero errors),
 * the Guard Dog rolls back the visual change because user behavior degraded.
 */
final class RevenueGuardService
{
    private const MARKER_DIR = 'storage/evolution/.revenue_guard';
    private const DEFAULT_DROP_THRESHOLD_PCT = 30;
    private const DEFAULT_CHECK_WINDOW_HOURS = 4;
    private const MIN_EVENTS_FOR_COMPARISON = 20;

    /**
     * Snapshot the current conversion baseline before a UI patch.
     */
    public static function snapshotBefore(string $target): void
    {
        $dir = BASE_PATH . '/' . self::MARKER_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $baseline = self::currentConversionMetrics();
        $marker = [
            'target' => $target,
            'ts' => time(),
            'baseline' => $baseline,
        ];
        $file = $dir . '/' . sha1($target) . '.json';
        @file_put_contents($file, json_encode($marker, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Check pending revenue markers and rollback if conversion dropped.
     *
     * @return list<array{target: string, rolled_back: bool, reason: string}>
     */
    public static function checkPendingMarkers(Config $config): array
    {
        $dir = BASE_PATH . '/' . self::MARKER_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $gd = is_array($arch) ? ($arch['guard_dog'] ?? []) : [];
        $threshold = max(5, (int)($gd['revenue_drop_threshold_pct'] ?? self::DEFAULT_DROP_THRESHOLD_PCT));
        $windowHours = max(1, (int)($gd['revenue_check_window_hours'] ?? self::DEFAULT_CHECK_WINDOW_HOURS));

        $files = glob($dir . '/*.json') ?: [];
        $results = [];
        $current = self::currentConversionMetrics();

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            $m = is_string($raw) ? @json_decode($raw, true) : null;
            if (!is_array($m)) {
                @unlink($file);
                continue;
            }

            $age = (time() - (int)($m['ts'] ?? 0)) / 3600;
            if ($age < $windowHours) {
                continue;
            }

            $target = (string)($m['target'] ?? '');
            $baseline = $m['baseline'] ?? [];
            $baselineRate = (float)($baseline['conversion_rate_pct'] ?? 0);
            $currentRate = (float)($current['conversion_rate_pct'] ?? 0);
            $baselineEvents = (int)($baseline['total_events'] ?? 0);
            $currentEvents = (int)($current['total_events'] ?? 0);

            if ($baselineEvents < self::MIN_EVENTS_FOR_COMPARISON || $currentEvents < self::MIN_EVENTS_FOR_COMPARISON) {
                $results[] = ['target' => $target, 'rolled_back' => false, 'reason' => 'Not enough events for comparison'];
                @unlink($file);
                continue;
            }

            $drop = $baselineRate > 0 ? (($baselineRate - $currentRate) / $baselineRate) * 100 : 0;

            if ($drop >= $threshold) {
                SelfHealingManager::purgePatch($target);
                SelfHealingManager::clearTwigCache();

                LearningLoopService::record([
                    'target' => $target,
                    'type' => 'revenue_rollback',
                    'severity' => 'ui_autofix',
                    'ok' => false,
                    'rolled_back' => true,
                    'rollback_reason' => "Revenue Guard: conversion dropped {$drop}% (baseline {$baselineRate}% -> current {$currentRate}%)",
                ]);

                EvolutionLogger::log('revenue_guard', 'rollback', [
                    'target' => $target,
                    'baseline_rate' => $baselineRate,
                    'current_rate' => $currentRate,
                    'drop_pct' => round($drop, 1),
                    'threshold' => $threshold,
                ]);

                $results[] = ['target' => $target, 'rolled_back' => true, 'reason' => "Conversion dropped " . round($drop, 1) . "% (threshold {$threshold}%)"];
            } else {
                $results[] = ['target' => $target, 'rolled_back' => false, 'reason' => 'Conversion stable'];
            }

            @unlink($file);
        }

        return $results;
    }

    /**
     * Public snapshot for Oracle / dashboards (last 24h CRO-derived conversion).
     *
     * @return array{total_events: int, views: int, conversions: int, conversion_rate_pct: float}
     */
    public static function conversionMetrics24h(): array
    {
        return self::currentConversionMetrics();
    }

    /**
     * Calculate current conversion metrics from CRO events (last 24h).
     *
     * @return array{total_events: int, views: int, conversions: int, conversion_rate_pct: float}
     */
    private static function currentConversionMetrics(): array
    {
        $path = BASE_PATH . '/storage/evolution/cro_events.jsonl';
        if (!is_file($path)) {
            return ['total_events' => 0, 'views' => 0, 'conversions' => 0, 'conversion_rate_pct' => 0];
        }

        $cutoff = gmdate('c', time() - 86400);
        $views = 0;
        $conversions = 0;
        $total = 0;

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice($lines, -2000) as $line) {
            $j = @json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $ts = (string)($j['timestamp'] ?? $j['ts'] ?? '');
            if ($ts < $cutoff) {
                continue;
            }
            $total++;
            $action = (string)($j['action'] ?? $j['event_type'] ?? '');
            if (in_array($action, ['view', 'page_view'], true)) {
                $views++;
            } elseif (in_array($action, ['conversion', 'success', 'click'], true)) {
                $conversions++;
            }
        }

        $rate = $views > 0 ? round(($conversions / $views) * 100, 2) : 0;

        return [
            'total_events' => $total,
            'views' => $views,
            'conversions' => $conversions,
            'conversion_rate_pct' => $rate,
        ];
    }
}
