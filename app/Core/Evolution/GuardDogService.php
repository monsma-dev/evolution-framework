<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Guard Dog: rate-limits auto-applies and rolls back patches when error rates spike.
 *
 * Rate limit: counts patch+frontend apply entries in evolution.log from the last hour.
 * Error spike: writes marker files after auto-apply; on the next admin request
 *   (via CheckRollbackMiddleware or piggybacked call), compares error counts and
 *   purges the patch if the spike exceeds the configured threshold.
 */
final class GuardDogService
{
    private const MARKER_DIR = 'storage/evolution/.guard_dog';

    public function isAutoApplyAllowed(Config $config): bool
    {
        if (EvolutionKillSwitchService::isPaused($config)) {
            return false;
        }
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];

        return is_array($aa) && filter_var($aa['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return list<string> allowed severity values for auto-apply
     */
    public function allowedSeverities(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];
        $sev = is_array($aa) ? ($aa['allowed_severities'] ?? []) : [];

        return is_array($sev) ? array_values(array_filter($sev, 'is_string')) : [];
    }

    public function maxFilesPerAuto(Config $config): int
    {
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];

        return max(1, (int)($aa['max_files_per_auto'] ?? 3));
    }

    /**
     * @return array{allowed: bool, count: int, max: int}
     */
    public function checkRateLimit(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];
        $max = max(1, (int)($aa['max_per_hour'] ?? 5));

        $logPath = EvolutionLogger::logPath();
        if (!is_file($logPath)) {
            return ['allowed' => true, 'count' => 0, 'max' => $max];
        }

        $cutoff = gmdate('c', time() - 3600);
        $count = 0;
        $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return ['allowed' => true, 'count' => 0, 'max' => $max];
        }

        foreach (array_reverse($lines) as $line) {
            $j = @json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $ts = (string)($j['ts'] ?? '');
            if ($ts < $cutoff) {
                break;
            }
            $ch = (string)($j['channel'] ?? '');
            $msg = (string)($j['message'] ?? '');
            if (($ch === 'patch' && $msg === 'apply') || ($ch === 'frontend' && str_contains($msg, 'override'))) {
                $count++;
            }
        }

        return ['allowed' => $count < $max, 'count' => $count, 'max' => $max];
    }

    /**
     * Schedules an error-spike check for a recently auto-applied patch.
     */
    public function scheduleErrorCheck(string $fqcnOrTemplate, Container $container): void
    {
        $cfg = $container->get('config');
        $evo = $cfg->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $gd = is_array($arch) ? ($arch['guard_dog'] ?? []) : [];
        if (!is_array($gd) || !filter_var($gd['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return;
        }
        if (!filter_var($gd['error_spike_rollback'] ?? false, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $dir = BASE_PATH . '/' . self::MARKER_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $health = new HealthSnapshotService();
        $marker = [
            'fqcn' => $fqcnOrTemplate,
            'ts' => time(),
            'error_count_before' => $health->countErrorsToday(),
        ];
        $file = $dir . '/' . sha1($fqcnOrTemplate) . '.json';
        @file_put_contents($file, json_encode($marker, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Checks pending markers and rolls back patches if error rate spiked.
     * Called piggybacked on admin requests (CheckRollbackMiddleware).
     *
     * @return list<array{fqcn: string, rolled_back: bool, reason: string}>
     */
    public function runPendingChecks(Container $container): array
    {
        $cfg = $container->get('config');
        $evo = $cfg->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $gd = is_array($arch) ? ($arch['guard_dog'] ?? []) : [];
        if (!is_array($gd) || !filter_var($gd['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $delay = max(10, (int)($gd['error_check_delay_seconds'] ?? 60));
        $threshold = max(1, (int)($gd['error_spike_threshold_pct'] ?? 20));
        $clearCache = filter_var($gd['rollback_clears_cache'] ?? true, FILTER_VALIDATE_BOOL);

        $dir = BASE_PATH . '/' . self::MARKER_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json') ?: [];
        $results = [];
        $health = new HealthSnapshotService();
        $nowErrors = $health->countErrorsToday();

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            $m = is_string($raw) ? @json_decode($raw, true) : null;
            if (!is_array($m)) {
                @unlink($file);
                continue;
            }

            $age = time() - (int)($m['ts'] ?? 0);
            if ($age < $delay) {
                continue;
            }

            $before = max(1, (int)($m['error_count_before'] ?? 0));
            $spike = (($nowErrors - $before) / $before) * 100;
            $fqcn = (string)($m['fqcn'] ?? '');

            $loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
            $cpuSpike = is_array($loadAvg) && ($loadAvg[0] ?? 0) > 6.0;
            $shouldRollback = ($spike >= $threshold && $fqcn !== '') || ($cpuSpike && $fqcn !== '');
            $reason = '';

            if ($spike >= $threshold) {
                $reason = "Error spike " . round($spike, 1) . "% >= {$threshold}%";
            }
            if ($cpuSpike) {
                $reason .= ($reason !== '' ? ' + ' : '') . 'CPU load 1m=' . round($loadAvg[0], 2);
            }

            if ($shouldRollback) {
                SelfHealingManager::purgePatch($fqcn);

                if ($clearCache) {
                    SelfHealingManager::clearTwigCache();
                }

                EvolutionLogger::log('guard_dog', 'rollback', [
                    'fqcn' => $fqcn,
                    'error_before' => $before,
                    'error_now' => $nowErrors,
                    'spike_pct' => round($spike, 1),
                    'cpu_load_1m' => is_array($loadAvg) ? round($loadAvg[0] ?? 0, 2) : null,
                    'reason' => $reason,
                ]);

                LearningLoopService::recordRollback($fqcn, $reason);
                $results[] = ['fqcn' => $fqcn, 'rolled_back' => true, 'reason' => $reason];
            } else {
                $results[] = ['fqcn' => $fqcn, 'rolled_back' => false, 'reason' => 'OK'];
            }

            @unlink($file);
        }

        return $results;
    }
}
