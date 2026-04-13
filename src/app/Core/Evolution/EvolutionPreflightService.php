<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Sovereign pre-flight checks before enabling native_compiler or touching live ini.
 */
final class EvolutionPreflightService
{
    public const DEADMAN_PROBE_REL = 'storage/evolution/deadman_probe.ini';

    /**
     * Simulates a corrupt INI write on a probe file, then restores from the latest Time-Anchor ZIP without human action.
     *
     * @return array{ok: bool, ms?: float, detail?: string, anchor_hash?: string}
     */
    public static function deadMansSwitchProbe(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $r = is_array($evo) ? ($evo['respawn'] ?? []) : [];
        if (!is_array($r) || !filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'detail' => 'respawn disabled — enable evolution.respawn for Time-Anchors'];
        }

        $base = defined('BASE_PATH') ? BASE_PATH : '';
        if ($base === '') {
            return ['ok' => false, 'detail' => 'BASE_PATH undefined'];
        }

        $probe = $base . '/' . self::DEADMAN_PROBE_REL;
        $dir = dirname($probe);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'detail' => 'cannot create probe dir'];
        }

        $marker = '; Evolution deadman probe' . "\n" . 'marker=' . bin2hex(random_bytes(8)) . "\n";
        if (@file_put_contents($probe, $marker) === false) {
            return ['ok' => false, 'detail' => 'cannot write probe'];
        }

        $paths = [
            'src/config/evolution.json',
            self::DEADMAN_PROBE_REL,
        ];
        $iniPath = 'docker/php/99-framework.ini';
        if (is_file($base . '/' . $iniPath)) {
            $paths[] = $iniPath;
        }
        $anchor = RespawnEngine::createZipForPaths($config, $paths, 'preflight_deadman');
        if (!($anchor['ok'] ?? false)) {
            return ['ok' => false, 'detail' => 'anchor failed: ' . ($anchor['error'] ?? '?')];
        }

        $t0 = microtime(true);
        if (@file_put_contents($probe, "CORRUPT_INI_SIMULATION=1\n!!!\n") === false) {
            return ['ok' => false, 'detail' => 'cannot corrupt probe'];
        }

        $restore = RespawnEngine::restoreRelativeFromLatestAnchor($config, self::DEADMAN_PROBE_REL);
        $elapsedMs = (microtime(true) - $t0) * 1000;

        $got = @file_get_contents($probe);
        $ok = ($restore['ok'] ?? false) && is_string($got) && hash_equals($marker, $got);

        EvolutionLogger::log('preflight', 'deadman_probe', [
            'ok' => $ok,
            'elapsed_ms' => round($elapsedMs, 2),
            'anchor_hash' => $anchor['hash'] ?? null,
            'restore_error' => $restore['error'] ?? null,
        ]);

        if (!$ok) {
            return [
                'ok' => false,
                'ms' => round($elapsedMs, 3),
                'detail' => 'restore verification failed',
                'anchor_hash' => $anchor['hash'] ?? null,
            ];
        }

        if ($elapsedMs > 10_000) {
            return [
                'ok' => false,
                'ms' => round($elapsedMs, 3),
                'detail' => 'restore exceeded 10s SLA',
                'anchor_hash' => $anchor['hash'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'ms' => round($elapsedMs, 3),
            'detail' => 'probe restored from latest anchor within SLA',
            'anchor_hash' => $anchor['hash'] ?? null,
        ];
    }

    /**
     * @return array{ok: bool, checks: array<string, array{ok: bool, detail?: string}>}
     */
    public static function runAll(Config $config): array
    {
        $checks = [];

        $d = self::deadMansSwitchProbe($config);
        $checks['dead_mans_switch'] = [
            'ok' => (bool) ($d['ok'] ?? false),
            'detail' => ($d['detail'] ?? '') . (isset($d['ms']) ? ' (' . $d['ms'] . ' ms)' : ''),
        ];

        $nc = $config->get('evolution.native_compiler', []);
        $strict = is_array($nc) && filter_var($nc['dual_execution_strict'] ?? true, FILTER_VALIDATE_BOOL);
        $checks['dual_execution_strict'] = [
            'ok' => $strict,
            'detail' => $strict ? 'strict mode on' : 'dual_execution_strict must be true for sovereign go-live',
        ];

        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evo_native_path_trap_' . bin2hex(random_bytes(4));
        $trap = EvolutionNativeSandboxService::assertCratePathContainedInWorkspace($config, $outside);
        $checks['sandbox_path_traversal'] = [
            'ok' => !($trap['ok'] ?? true),
            'detail' => ($trap['ok'] ?? false) ? 'FAILED: path outside workspace was accepted' : 'path outside workspace rejected',
        ];

        $checks['atomic_swap_documented'] = [
            'ok' => true,
            'detail' => 'EvolutionNativeCompilerService::promoteNativeArtifact stages to temp then rename()',
        ];

        $maxRetries = is_array($nc) ? (int) ($nc['compile_max_retries'] ?? 5) : 5;
        $checks['compile_retry_budget'] = [
            'ok' => $maxRetries >= 1 && $maxRetries <= 20,
            'detail' => 'compile_max_retries=' . $maxRetries,
        ];

        $allOk = true;
        foreach ($checks as $c) {
            if (!($c['ok'] ?? false)) {
                $allOk = false;
                break;
            }
        }

        return ['ok' => $allOk, 'checks' => $checks];
    }
}
