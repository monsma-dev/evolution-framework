<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Read-only snapshots of whitelisted server config; outputs high-severity manual-apply suggestions only.
 */
final class InfrastructureAsCodeBridgeService
{
    /**
     * @return array<string, mixed>
     */
    public static function collectHints(Container $container): array
    {
        $cfg = $container->get('config');
        $bridge = $cfg->get('evolution.iac_bridge', []);
        if (!is_array($bridge) || !filter_var($bridge['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['enabled' => false];
        }

        $paths = $bridge['read_paths'] ?? ['.htaccess'];
        if (!is_array($paths)) {
            $paths = ['.htaccess'];
        }

        $healthProbe = [
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'error_count_today' => (new HealthSnapshotService())->countErrorsToday(),
        ];
        $snapshots = [];
        $iniPath = function_exists('php_ini_loaded_file') ? php_ini_loaded_file() : false;
        if (is_string($iniPath) && $iniPath !== '') {
            $snapshots['php_ini'] = self::snapshotFile($iniPath, 12000);
        }
        $snapshots['php_runtime'] = [
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache.enable' => ini_get('opcache.enable'),
            'opcache.enable_cli' => ini_get('opcache.enable_cli'),
            'opcache.preload' => ini_get('opcache.preload'),
            'realpath_cache_size' => ini_get('realpath_cache_size'),
        ];

        foreach ($paths as $rel) {
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $rel = str_replace(['../', '..\\'], '', $rel);
            $full = BASE_PATH . '/' . ltrim(str_replace('\\', '/', $rel), '/');
            if (!self::isUnderBase($full)) {
                continue;
            }
            $key = 'project:' . $rel;
            $snapshots[$key] = self::snapshotFile($full, 8000);
        }

        $recommendations = self::recommendFromHealth($healthProbe, $snapshots['php_runtime'] ?? []);

        return [
            'enabled' => true,
            'severity' => 'high_severity',
            'manual_only' => true,
            'instruction' => 'Do not auto-apply. Review diffs or run generated shell snippets manually after backup.',
            'snapshots' => $snapshots,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Short text block for prompts (no full file dumps).
     */
    public static function promptAppend(Container $container): string
    {
        $h = self::collectHints($container);
        if (empty($h['enabled'])) {
            return '';
        }
        $rec = $h['recommendations'] ?? [];
        if (!is_array($rec) || $rec === []) {
            return "\n\nIAC_BRIDGE (high_severity, manual): runtime memory_limit=" . (string)ini_get('memory_limit') . ', opcache.preload=' . (string)ini_get('opcache.preload');
        }
        $lines = ["\n\nIAC_BRIDGE (high_severity — manual apply only):"];
        foreach (array_slice($rec, 0, 6) as $r) {
            if (is_string($r)) {
                $lines[] = ' - ' . $r;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function recommendFromHealth(array $health, array $phpRuntime): array
    {
        $out = [];
        $peak = (float)($health['memory_peak_mb'] ?? 0);
        if ($peak > 128) {
            $out[] = 'Consider raising memory_limit in php.ini (current runtime peak ~' . round($peak, 1) . ' MB in snapshot) if OOM risk on heavy admin routes — apply only after profiling.';
        }
        $errToday = (int)($health['error_count_today'] ?? 0);
        if ($errToday > 200) {
            $out[] = 'High error volume today (' . $errToday . '); consider max_execution_time and opcache settings after identifying slow endpoints — manual change only.';
        }
        $preload = (string)($phpRuntime['opcache.preload'] ?? '');
        if ($preload === '' || $preload === '0') {
            $out[] = 'opcache.preload is empty — for large App\\ autoload trees, a curated preload script can cut cold-start; high_severity: validate in staging first.';
        }
        $cliEn = filter_var($phpRuntime['opcache.enable_cli'] ?? ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL);
        if (!$cliEn) {
            $out[] = 'opcache.enable_cli is off — ai_bridge.php and cron jobs will not benefit from OPcache/JIT; set opcache.enable_cli=1 in php.ini (CLI) for tuning parity.';
        }

        return $out;
    }

    private static function isUnderBase(string $path): bool
    {
        $realBase = realpath(BASE_PATH);
        $real = realpath(dirname($path));
        if ($realBase === false || $real === false) {
            return str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', BASE_PATH) . '/');
        }

        return str_starts_with($real . '/', $realBase . DIRECTORY_SEPARATOR) || $real === $realBase;
    }

    /**
     * @return array{readable: bool, path: string, excerpt: string, line_count: int}|array{error: string}
     */
    private static function snapshotFile(string $path, int $maxChars): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['error' => 'unreadable', 'path' => $path];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return ['error' => 'empty', 'path' => $path];
        }
        $excerpt = strlen($raw) > $maxChars ? (substr($raw, 0, $maxChars) . "\n… [truncated]") : $raw;
        $lines = substr_count($raw, "\n") + 1;

        return [
            'readable' => true,
            'path' => $path,
            'excerpt' => $excerpt,
            'line_count' => $lines,
        ];
    }
}
