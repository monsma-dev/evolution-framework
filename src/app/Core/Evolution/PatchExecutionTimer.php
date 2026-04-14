<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Pipeline\PipelineStep;

/**
 * Times individual {@see PipelineStep}::handle() calls when a shadow patch is active for that step class.
 * If execution exceeds configured limits vs. the baseline in the sidecar reasoning.json, the patch is removed.
 */
final class PatchExecutionTimer
{
    /** @var array<string, true> */
    private static array $hasPatchCache = [];

    public static function forgetFqcn(string $fqcn): void
    {
        unset(self::$hasPatchCache[$fqcn]);
    }

    public static function runStep(PipelineStep $step, mixed $payload, \Closure $next): mixed
    {
        if (!self::guardEnabled()) {
            return $step->handle($payload, $next);
        }

        $fqcn = get_class($step);
        if (!self::shadowPatchExists($fqcn)) {
            return $step->handle($payload, $next);
        }

        $t0 = microtime(true);
        try {
            return $step->handle($payload, $next);
        } finally {
            $ms = (microtime(true) - $t0) * 1000.0;
            self::evaluate($fqcn, $ms);
        }
    }

    private static function guardEnabled(): bool
    {
        if (!isset(($GLOBALS)['app_container'])) {
            return false;
        }
        try {
            $cfg = ($GLOBALS)['app_container']->get('config');
            $evo = $cfg->get('evolution', []);
            $pg = is_array($evo) ? ($evo['patch_guard'] ?? []) : [];

            return is_array($pg) && filter_var($pg['enabled'] ?? true, FILTER_VALIDATE_BOOL);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function shadowPatchExists(string $fqcn): bool
    {
        if (isset(self::$hasPatchCache[$fqcn])) {
            return true;
        }
        $path = SelfHealingManager::shadowPatchPhpPath($fqcn);
        $ok = $path !== null && is_file($path);
        if ($ok) {
            self::$hasPatchCache[$fqcn] = true;
        }

        return $ok;
    }

    private static function evaluate(string $fqcn, float $stepMs): void
    {
        if (!isset(($GLOBALS)['app_container'])) {
            return;
        }
        try {
            $cfg = ($GLOBALS)['app_container']->get('config');
        } catch (\Throwable) {
            return;
        }

        $evo = $cfg->get('evolution', []);
        $pg = is_array($evo) ? ($evo['patch_guard'] ?? []) : [];
        $maxWall = max(1.0, (float)($pg['max_wall_ms'] ?? 200.0));
        $ratio = max(1.0, (float)($pg['slow_ratio'] ?? 20.0));
        $baseline = self::baselineMsForFqcn($fqcn);

        $tooSlowAbsolute = $stepMs >= $maxWall;
        $tooSlowVsBaseline = $baseline > 0 && $stepMs >= $baseline * $ratio;
        $tooSlow = $tooSlowAbsolute || $tooSlowVsBaseline;

        self::writeGuardMeta($fqcn, $stepMs, $tooSlow, $baseline, $maxWall, $ratio);

        if (!$tooSlow) {
            return;
        }

        SelfHealingManager::purgePatch($fqcn);
        unset(self::$hasPatchCache[$fqcn]);

        EvolutionLogger::log('patch_guard', 'timing_rollback', [
            'fqcn' => $fqcn,
            'step_ms' => round($stepMs, 3),
            'baseline_ms' => $baseline,
            'max_wall_ms' => $maxWall,
            'slow_ratio' => $ratio,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readGuardSnapshot(string $fqcn): ?array
    {
        $path = self::guardMetaPath($fqcn);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $j = json_decode($raw, true);

        return is_array($j) ? $j : null;
    }

    public static function deleteGuardMeta(string $fqcn): void
    {
        $path = self::guardMetaPath($fqcn);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function guardMetaPath(string $fqcn): string
    {
        $h = hash('sha256', $fqcn);
        $dir = BASE_PATH . '/storage/patches/.meta';

        return $dir . '/guard_' . $h . '.json';
    }

    /**
     * @return void
     */
    private static function writeGuardMeta(
        string $fqcn,
        float $stepMs,
        bool $rolledBack,
        float $baseline,
        float $maxWall,
        float $ratio
    ): void {
        $dir = BASE_PATH . '/storage/patches/.meta';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $path = self::guardMetaPath($fqcn);
        $payload = [
            'fqcn' => $fqcn,
            'last_step_ms' => round($stepMs, 4),
            'rolled_back' => $rolledBack,
            'baseline_ms' => round($baseline, 4),
            'max_wall_ms' => $maxWall,
            'slow_ratio' => $ratio,
            'updated_at' => gmdate('c'),
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($path, $json);
        }
    }

    private static function baselineMsForFqcn(string $fqcn): float
    {
        $path = SelfHealingManager::reasoningJsonPathForFqcn($fqcn);
        if ($path === null || !is_file($path)) {
            return (float)self::defaultBaselineFromConfig();
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return (float)self::defaultBaselineFromConfig();
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return (float)self::defaultBaselineFromConfig();
        }
        $b = (float)($j['original_baseline_ms'] ?? $j['expected_baseline_ms'] ?? 0);

        return $b > 0 ? $b : (float)self::defaultBaselineFromConfig();
    }

    private static function defaultBaselineFromConfig(): float
    {
        if (!isset(($GLOBALS)['app_container'])) {
            return 10.0;
        }
        try {
            $cfg = ($GLOBALS)['app_container']->get('config');
            $evo = $cfg->get('evolution', []);
            $pg = is_array($evo) ? ($evo['patch_guard'] ?? []) : [];

            return max(0.1, (float)($pg['default_baseline_ms'] ?? 10.0));
        } catch (\Throwable) {
            return 10.0;
        }
    }
}
