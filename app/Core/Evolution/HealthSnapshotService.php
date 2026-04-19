<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use App\Support\Error\ErrorLogRepository;

/**
 * Collects system health metrics for the Architect AI prompt context.
 * Gives the model awareness of cache status, error pressure, and active patches.
 */
final class HealthSnapshotService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(Container $container): array
    {
        $cacheDriver = 'unknown';
        $cacheAvailable = false;
        try {
            $cache = $container->get('cache');
            if (method_exists($cache, 'getDriverName')) {
                $cacheDriver = (string)$cache->getDriverName();
            } elseif (isset($cache->driver)) {
                $cacheDriver = (string)$cache->driver;
            }
            $cacheAvailable = true;
        } catch (\Throwable) {
        }

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;

        $cfg = $container->get('config');
        $iac = InfrastructureAsCodeBridgeService::collectHints($container);
        $abPerf = AbPerformanceService::isEnabled($cfg) ? AbPerformanceService::evaluate($cfg) : null;

        $warnings = $this->resourceWarnings($load);
        $webVitals = EvolutionWebVitalsService::summary($container);
        if (isset($webVitals['fid_p75_ms']) && is_numeric($webVitals['fid_p75_ms'])
            && (float) $webVitals['fid_p75_ms'] > 300.0
            && (int) ($webVitals['samples_24h'] ?? 0) >= 5) {
            $warnings[] = 'Web Vitals FID p75 is ' . $webVitals['fid_p75_ms']
                . ' ms — consider deferring heavy scripts or reducing page_libraries on hot paths.';
        }

        $infraSentinel = (new EvolutionInfrasentinelService($container))->snapshotForHealth();
        $pulse = EvolutionPulseService::lastState();

        return [
            'cache_driver' => $cacheDriver,
            'cache_available' => $cacheAvailable,
            'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'cpu_load_1m' => is_array($load) ? round($load[0] ?? 0, 2) : null,
            'cpu_load_5m' => is_array($load) ? round($load[1] ?? 0, 2) : null,
            'cpu_load_15m' => is_array($load) ? round($load[2] ?? 0, 2) : null,
            'disk_free_mb' => round(@disk_free_space(BASE_PATH) / 1048576, 0),
            'disk_total_mb' => round(@disk_total_space(BASE_PATH) / 1048576, 0),
            'error_count_today' => $this->countErrorsToday(),
            'error_count_last_5m' => $this->countErrorsLastMinutes(5),
            'cro_data_status' => $this->croHealthy() ? 'OK' : 'DEGRADED',
            'active_patches' => count((new SelfHealingManager($container))->listPatches()),
            'twig_overrides' => $this->countTwigOverrides(),
            'css_override_bytes' => $this->cssOverrideSize($container),
            'theme_tokens_css_bytes' => $this->themeTokensCssSize($container),
            'web_vitals' => $webVitals,
            'opcache_jit' => OpcacheIntelligenceService::jitSnapshot($cfg),
            'resource_warnings' => $warnings,
            'iac_bridge' => $iac,
            'ab_performance' => $abPerf,
            'infrastructure_sentinel' => $infraSentinel,
            'pulse' => $pulse,
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float}|null $load
     * @return list<string>
     */
    private function resourceWarnings(?array $load): array
    {
        $w = [];
        if (is_array($load) && ($load[0] ?? 0) > 4.0) {
            $w[] = 'CPU load 1m is ' . round($load[0], 2) . ' — high pressure, defer non-critical patches';
        }
        $freeMb = @disk_free_space(BASE_PATH) / 1048576;
        if ($freeMb < 500) {
            $w[] = 'Disk free space is ' . round($freeMb) . ' MB — critically low';
        }
        $memMb = memory_get_usage(true) / 1048576;
        if ($memMb > 200) {
            $w[] = 'PHP memory usage is ' . round($memMb, 1) . ' MB — unusually high for this request';
        }

        return $w;
    }

    public function countErrorsToday(): int
    {
        $file = BASE_PATH . '/data/logs/errors/' . date('Y-m-d') . '.log';
        if (!is_file($file)) {
            return 0;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? count($lines) : 0;
    }

    public function countErrorsLastMinutes(int $minutes): int
    {
        $file = BASE_PATH . '/data/logs/errors/' . date('Y-m-d') . '.log';
        if (!is_file($file)) {
            return 0;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return 0;
        }

        $cutoff = gmdate('c', time() - ($minutes * 60));
        $count = 0;
        foreach (array_reverse($lines) as $line) {
            $j = @json_decode((string)$line, true);
            if (!is_array($j)) {
                continue;
            }
            $ts = (string)($j['time'] ?? $j['ts'] ?? '');
            if ($ts === '' || $ts < $cutoff) {
                break;
            }
            $count++;
        }

        return $count;
    }

    public function croHealthy(): bool
    {
        $path = BASE_PATH . '/data/evolution/cro_events.jsonl';

        return is_file($path) && filesize($path) > 10;
    }

    private function countTwigOverrides(): int
    {
        $dir = BASE_PATH . '/data/evolution/twig_overrides';
        if (!is_dir($dir)) {
            return 0;
        }
        $files = glob($dir . '/**/*.twig') ?: [];
        $top = glob($dir . '/*.twig') ?: [];

        return count($files) + count($top);
    }

    private function cssOverrideSize(Container $container): int
    {
        try {
            $fe = new FrontendEvolutionService($container);
            $path = $fe->cssFilePath();

            return is_file($path) ? (int)filesize($path) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function themeTokensCssSize(Container $container): int
    {
        try {
            $fe = new FrontendEvolutionService($container);
            $path = $fe->themeTokensFilePath();

            return is_file($path) ? (int) filesize($path) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
