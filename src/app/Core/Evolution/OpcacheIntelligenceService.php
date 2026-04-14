<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;
use App\Support\Ops\JitTelemetry;
use App\Support\Ops\JitWarmup;

/**
 * Performance intelligence: targeted OPcache invalidation after patches,
 * Twig precompile, JIT buffer monitoring, and ghost warmup.
 */
final class OpcacheIntelligenceService
{
    /**
     * Targeted opcache_invalidate for specific files (no full opcache_reset).
     *
     * @param list<string> $files absolute paths
     * @return array{invalidated: int, files: list<string>}
     */
    public static function invalidateFiles(array $files): array
    {
        $invalidated = 0;
        $done = [];
        if (!function_exists('opcache_invalidate')) {
            return ['invalidated' => 0, 'files' => []];
        }
        foreach ($files as $file) {
            $file = (string)$file;
            if ($file !== '' && is_file($file)) {
                opcache_invalidate($file, true);
                $invalidated++;
                $done[] = $file;
            }
        }
        if ($done !== []) {
            EvolutionLogger::log('opcache', 'targeted_invalidate', ['count' => $invalidated, 'files' => $done]);
        }

        return ['invalidated' => $invalidated, 'files' => $done];
    }

    /**
     * After a shadow patch: invalidate the patched file + original source file.
     */
    public static function invalidateForPatch(string $fqcn): array
    {
        $files = [];
        $patchPath = SelfHealingManager::shadowPatchPhpPath($fqcn);
        if ($patchPath !== null) {
            $files[] = $patchPath;
        }
        $relative = str_replace('\\', '/', substr($fqcn, 4));
        $srcPath = BASE_PATH . '/src/app/' . $relative . '.php';
        if (is_file($srcPath)) {
            $files[] = $srcPath;
        }

        return self::invalidateFiles($files);
    }

    /**
     * Twig precompile for specific templates (not full precompile).
     *
     * @param list<string> $templates relative Twig template paths
     */
    public static function precompileTwigTemplates(Container $container, array $templates): array
    {
        $compiled = 0;
        $errors = [];
        try {
            $view = $container->get('view');
            if (!method_exists($view, 'getTwig')) {
                return ['compiled' => 0, 'errors' => ['View has no getTwig method']];
            }
            $twig = $view->getTwig();
            foreach ($templates as $tpl) {
                try {
                    $twig->load($tpl);
                    $compiled++;
                } catch (\Throwable $e) {
                    $errors[] = $tpl . ': ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'bootstrap: ' . $e->getMessage();
        }
        if ($compiled > 0) {
            EvolutionLogger::log('opcache', 'twig_precompile', ['compiled' => $compiled, 'templates' => $templates]);
        }

        return ['compiled' => $compiled, 'errors' => $errors];
    }

    /**
     * Ghost warmup after a patch: fire HTTP requests to critical routes.
     */
    public static function ghostWarmupAfterPatch(Container $container, ?string $fqcn = null, ?array $extraPaths = null): array
    {
        $warmer = new PatchWarmupService($container);

        return $warmer->run($fqcn, $extraPaths);
    }

    /**
     * JIT/OPcache health snapshot for the AI prompt and admin telemetry.
     *
     * @return array<string, mixed>
     */
    public static function jitSnapshot(?Config $config = null): array
    {
        $snap = JitTelemetry::snapshot();
        $warnings = [];
        $bufferPct = (float) ($snap['buffer_usage_pct'] ?? 0);
        if ($bufferPct > 85) {
            $warnings[] = 'JIT buffer usage at ' . round($bufferPct, 1) . '% — consider increasing opcache.jit_buffer_size (IaC bridge / php.ini)';
        }
        $hitRate = (float) ($snap['opcache_hit_rate'] ?? 0);
        $wasteNot = 99.0;
        $softWarn = 90.0;
        if ($config !== null) {
            $oi = $config->get('evolution.opcache_intelligence', []);
            if (is_array($oi)) {
                $wasteNot = max(80.0, min(100.0, (float) ($oi['waste_not_hit_rate_pct'] ?? 99)));
                $softWarn = max(50.0, min(100.0, (float) ($oi['soft_warn_hit_rate_pct'] ?? 90)));
            }
        }
        if ($hitRate > 0 && $hitRate < $softWarn) {
            $warnings[] = 'OPcache hit rate critically low (' . round($hitRate, 1) . '%) — check memory_consumption, FPM reloads, or cold deploy.';
        } elseif ($hitRate > 0 && $hitRate < $wasteNot) {
            $warnings[] = 'WASTE_NOT: OPcache hit rate ' . round($hitRate, 2)
                . '% is below ' . $wasteNot . '% — batch AI patches and avoid frequent full invalidations.';
        }
        if (empty($snap['opcache_enable_cli'])) {
            $warnings[] = 'opcache.enable_cli=0 — CLI (ai_bridge.php, cron) will not use OPcache/JIT tuning; set opcache.enable_cli=1 in php.ini for dev parity.';
        }
        $snap['warnings'] = $warnings;
        $snap['waste_not_threshold_pct'] = $wasteNot;

        return $snap;
    }

    /**
     * Short system prompt block for Architect (JIT hot paths, targeted invalidation already used after patches).
     */
    public static function architectPromptAppend(Config $config): string
    {
        $evo = $config->get('evolution', []);
        $oi = is_array($evo) ? ($evo['opcache_intelligence'] ?? []) : [];
        if (is_array($oi) && isset($oi['architect_prompt_enabled']) && !filter_var($oi['architect_prompt_enabled'], FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $snap = self::jitSnapshot($config);
        $hit = (float) ($snap['opcache_hit_rate'] ?? 0);
        $buf = (float) ($snap['buffer_usage_pct'] ?? 0);
        $jitOn = (bool) ($snap['jit_active'] ?? false);

        return "\n\nOPCACHE_JIT_RUNTIME (advisory — hot Twig filters benefit from JIT after enough calls; patches use opcache_invalidate per file, not opcache_reset):\n"
            . '  hit_rate≈' . $hit . '%; jit_buffer≈' . round($buf, 1) . '%; jit_active=' . ($jitOn ? 'yes' : 'no')
            . '; opcache.enable_cli=' . (($snap['opcache_enable_cli'] ?? false) ? '1' : '0') . "\n"
            . '  If JIT buffer >85% in HealthSnapshot, suggest raising opcache.jit_buffer_size via IaC. If hit_rate < waste_not threshold, prefer batched changes.';
    }
}
