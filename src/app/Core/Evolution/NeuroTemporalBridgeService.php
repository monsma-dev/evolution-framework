<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Speculative JIT-hydration: intent signals (hover/checkout) → opcache_compile_file + optional APCu touch + ghost HTTP warmup.
 */
final class NeuroTemporalBridgeService
{
    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    public static function hydrateFromIntent(Container $container, string $intent, array $meta = []): array
    {
        $cfg = $container->get('config');
        $nt = $cfg->get('evolution.neuro_temporal', []);
        if (!is_array($nt) || !filter_var($nt['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'neuro_temporal disabled'];
        }

        $intent = strtolower(trim($intent));
        $map = $nt['intent_map'] ?? [];
        if (!is_array($map) || !isset($map[$intent]) || !is_array($map[$intent])) {
            return ['ok' => false, 'error' => 'unknown_intent', 'intent' => $intent];
        }

        $spec = $map[$intent];
        $compiled = 0;
        $compileErrors = [];
        $base = defined('BASE_PATH') ? BASE_PATH : '';

        foreach ($spec['compile_classes'] ?? [] as $fqcn) {
            if (!is_string($fqcn)) {
                continue;
            }
            $rel = MasterToolboxService::relativePathFromFqcn($fqcn);
            if ($rel === '') {
                continue;
            }
            $abs = $base . '/' . $rel;
            if (!is_file($abs)) {
                $compileErrors[] = $rel . ': missing file';

                continue;
            }
            if (function_exists('opcache_compile_file')) {
                if (@opcache_compile_file($abs)) {
                    $compiled++;
                }
            } else {
                $compileErrors[] = 'opcache_compile_file unavailable';
            }
        }

        $apcu = self::apcuPrime($intent, $spec, $meta);
        $warm = [];
        $paths = [];
        if (is_array($spec['warm_paths'] ?? null)) {
            foreach ($spec['warm_paths'] as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $paths[] = $p;
                }
            }
        }
        if ($paths !== []) {
            $warm = (new PatchWarmupService($container))->run(null, $paths);
        }

        EvolutionLogger::log('neuro_temporal', 'hydrate', [
            'intent' => $intent,
            'compiled' => $compiled,
            'warmed' => $warm['warmed'] ?? null,
        ]);

        $out = [
            'ok' => true,
            'intent' => $intent,
            'opcache_compiled' => $compiled,
            'compile_errors' => $compileErrors,
            'apcu' => $apcu,
            'warmup' => $warm,
        ];

        self::recordLevel8MilestoneIfNeeded($container);

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private static function apcuPrime(string $intent, array $spec, array $meta): array
    {
        if (!function_exists('apcu_store')) {
            return ['ok' => false, 'skipped' => 'apcu unavailable'];
        }
        $ttl = max(30, min(600, (int) ($spec['apcu_ttl_seconds'] ?? 120)));
        $key = 'neuro_intent:' . substr(hash('sha256', $intent . json_encode($meta)), 0, 24);
        apcu_store($key, ['intent' => $intent, 'ts' => time(), 'meta' => $meta], $ttl);

        $touched = 0;
        foreach ($spec['apcu_fetch_keys'] ?? [] as $k) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if (function_exists('apcu_exists') && apcu_exists($k)) {
                apcu_fetch($k);
                $touched++;
            }
        }

        return ['ok' => true, 'prime_key' => $key, 'ttl' => $ttl, 'fetched_existing' => $touched];
    }

    public static function recordLevel8MilestoneIfNeeded(Container $container): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        $flag = BASE_PATH . '/storage/evolution/.neuro_temporal_level8_done';
        if (is_file($flag)) {
            return;
        }
        (new EvolutionHallOfFameService($container))->recordMilestone(
            'Continuum: Level 8 Sovereign State Reached — Neuro-Temporal live; the Framework is now Perfect.',
            'milestone',
            ['neuro_temporal' => true, 'level' => 8, 'continuum' => true]
        );
        @file_put_contents($flag, gmdate('c') . "\n");
    }

    public static function assertAuthorized(Config $config, ?string $headerKey, ?string $queryKey): bool
    {
        $nt = $config->get('evolution.neuro_temporal', []);
        $secret = '';
        if (is_array($nt)) {
            $secret = trim((string) ($nt['ingest_secret'] ?? ''));
        }
        if ($secret === '') {
            $secret = trim((string) getenv('EVOLUTION_NEURO_KEY'));
        }
        if ($secret === '') {
            return false;
        }
        $got = trim((string) ($headerKey ?? ''));
        if ($got === '' && $queryKey !== null) {
            $got = trim((string) $queryKey);
        }

        return hash_equals($secret, $got);
    }
}
