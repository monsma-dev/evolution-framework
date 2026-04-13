<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Resource-aware scheduling: defer Ghost / heavy AI when loadavg or Pulse latency is high.
 * CLI: optional proc_nice(19) so batch work stays in the background.
 */
final class AdaptivePriorityGovernor
{
    private const STATE_FILE = 'storage/evolution/adaptive_priority_state.json';

    /**
     * Lower process priority on Unix CLI only (no-op on Windows / FPM).
     */
    public static function applyCliNiceness(Config $config): void
    {
        $ap = self::cfg($config);
        if (!is_array($ap) || !filter_var($ap['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }
        if (PHP_SAPI !== 'cli') {
            return;
        }
        if (!filter_var($ap['cli_proc_nice'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }
        if (!function_exists('proc_nice')) {
            return;
        }
        $delta = (int)($ap['nice_delta'] ?? 19);
        $delta = max(0, min(19, $delta));
        if ($delta <= 0) {
            return;
        }
        @proc_nice($delta);
        self::persist(['last_cli_nice' => $delta, 'ts' => gmdate('c')]);
    }

    /**
     * Skip Ghost cron when the box is busy (protects visitor-facing workers).
     */
    public static function shouldDeferGhostRun(Config $config): bool
    {
        $ap = self::cfg($config);
        if (!is_array($ap) || !filter_var($ap['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return false;
        }
        if (!filter_var($ap['ghost_defer_on_pressure'] ?? true, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $loadThr = (float)($ap['load_avg_threshold'] ?? 2.5);
        $pulseThr = (float)($ap['pulse_latency_ms_threshold'] ?? 200.0);

        $load = self::loadAvg1();
        if ($load !== null && $load >= $loadThr) {
            self::persist(['deferred_ghost' => true, 'reason' => 'load_avg', 'load_avg' => $load, 'ts' => gmdate('c')]);

            return true;
        }

        $pulse = EvolutionPulseService::lastState();
        $lat = (float)($pulse['latency_ms_total'] ?? 0);
        if ($lat > 0 && $lat >= $pulseThr) {
            self::persist(['deferred_ghost' => true, 'reason' => 'pulse_latency', 'latency_ms_total' => $lat, 'ts' => gmdate('c')]);

            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Config $config): array
    {
        $ap = self::cfg($config);
        $enabled = is_array($ap) && filter_var($ap['enabled'] ?? true, FILTER_VALIDATE_BOOL);

        return [
            'enabled' => $enabled,
            'load_avg_1' => self::loadAvg1(),
            'pulse_latency_ms_total' => EvolutionPulseService::lastState()['latency_ms_total'] ?? null,
            'state_file' => self::STATE_FILE,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $ap = is_array($evo) ? ($evo['adaptive_priority'] ?? null) : null;

        return is_array($ap) ? $ap : null;
    }

    private static function loadAvg1(): ?float
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }
        $la = @sys_getloadavg();
        if (!is_array($la) || !isset($la[0])) {
            return null;
        }

        return round((float)$la[0], 3);
    }

    /**
     * @param array<string, mixed> $merge
     */
    private static function persist(array $merge): void
    {
        $path = BASE_PATH . '/' . self::STATE_FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $prev = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $prev = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        }
        if (!is_array($prev)) {
            $prev = [];
        }
        $out = array_merge($prev, $merge);
        @file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
