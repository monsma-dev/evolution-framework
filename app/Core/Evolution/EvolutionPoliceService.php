<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Evolution Police — load snapshot, integrity vs baseline, deploy-session awareness,
 * agent "cell" (API block), evidence tails, optional runaway PID hints (safe defaults).
 */
final class EvolutionPoliceService
{
    private const DEFAULT_MAX_FILES = 2000;

    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        if (!is_array($evo)) {
            return null;
        }
        $p = $evo['police'] ?? [];

        return is_array($p) && filter_var($p['enabled'] ?? true, FILTER_VALIDATE_BOOL) ? $p : null;
    }

    public static function beginDeploySession(Config $config): void
    {
        $p = self::cfg($config);
        if ($p === null) {
            return;
        }
        $path = self::flagPath($config, (string) ($p['deploy_session_flag'] ?? 'storage/evolution/police_deploy_session.flag'));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, gmdate('c') . "\n", LOCK_EX);
    }

    public static function endDeploySession(Config $config): void
    {
        $p = self::cfg($config);
        if ($p === null) {
            return;
        }
        $path = self::flagPath($config, (string) ($p['deploy_session_flag'] ?? 'storage/evolution/police_deploy_session.flag'));
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function isDeploySessionActive(Config $config): bool
    {
        $p = self::cfg($config);
        if ($p === null) {
            return false;
        }
        $path = self::flagPath($config, (string) ($p['deploy_session_flag'] ?? 'storage/evolution/police_deploy_session.flag'));

        return is_file($path);
    }

    /**
     * @return array<string, mixed>
     */
    public static function patrol(Config $config): array
    {
        $p = self::cfg($config);
        if ($p === null) {
            return ['enabled' => false];
        }
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        $session = self::isDeploySessionActive($config);
        $integrity = self::compareIntegrity($config, $session);
        $pressure = self::evaluateLoadPressure($config);
        self::maybeArrestOnHighLoad($config, $pressure);

        return [
            'enabled' => true,
            'ts' => gmdate('c'),
            'load' => $load,
            'load_pressure' => $pressure,
            'deploy_session_active' => $session,
            'integrity' => $integrity,
            'cells' => self::readCells($config),
        ];
    }

    /**
     * First briefing for consensus / Officer: AWS + integrity + load.
     *
     * @return array<string, mixed>
     */
    public static function securityScanForMeeting(Config $config): array
    {
        $p = self::cfg($config);
        if ($p === null) {
            return ['enabled' => false, 'note' => 'police disabled'];
        }
        $patrol = self::patrol($config);
        $host = trim((string) (getenv('EVOLUTION_DEPLOY_SSH_HOST') ?: ''));

        return [
            'enabled' => true,
            'ts' => gmdate('c'),
            'load' => $patrol['load'] ?? null,
            'load_pressure' => $patrol['load_pressure'] ?? self::evaluateLoadPressure($config),
            'integrity' => $patrol['integrity'] ?? [],
            'aws_deploy_host_configured' => $host !== '',
            'aws_region' => trim((string) (getenv('AWS_DEFAULT_REGION') ?: '')),
            'deploy_session_active' => $patrol['deploy_session_active'] ?? false,
            'cells' => $patrol['cells'] ?? [],
        ];
    }

    /**
     * @return array{status: string, unauthorized_changes?: list<array<string, string>>, message?: string}
     */
    public static function compareIntegrity(Config $config, bool $deploySessionActive): array
    {
        $p = self::cfg($config);
        if ($p === null) {
            return ['status' => 'disabled'];
        }
        if ($deploySessionActive) {
            return ['status' => 'skipped_during_deploy_session', 'message' => 'Integrity diff suppressed while deploy session flag is set.'];
        }
        $baselinePath = self::flagPath($config, (string) ($p['baseline_file'] ?? 'storage/evolution/police_integrity_baseline.json'));
        if (!is_file($baselinePath)) {
            return ['status' => 'baseline_missing', 'message' => 'Run: php ai_bridge.php evolution:police baseline'];
        }
        $raw = @file_get_contents($baselinePath);
        $baseline = is_string($raw) ? json_decode($raw, true) : null;
        $files = is_array($baseline) && isset($baseline['files']) && is_array($baseline['files']) ? $baseline['files'] : [];
        $current = self::hashScanPaths($config);
        if (isset($current['error'])) {
            return ['status' => 'scan_error', 'message' => (string) $current['error']];
        }
        /** @var array<string, string> $curMap */
        $curMap = $current['files'] ?? [];
        $violations = [];
        foreach ($curMap as $rel => $hash) {
            if (!isset($files[$rel])) {
                $violations[] = ['kind' => 'new_file', 'path' => $rel, 'hash' => $hash];

                continue;
            }
            if ($files[$rel] !== $hash) {
                $violations[] = ['kind' => 'modified', 'path' => $rel, 'expected' => $files[$rel], 'actual' => $hash];
            }
        }
        foreach ($files as $rel => $_h) {
            if (!isset($curMap[$rel])) {
                $violations[] = ['kind' => 'removed', 'path' => $rel];
            }
        }

        if ($violations !== []) {
            EvolutionLogger::log('police_patrol', 'integrity_alert', ['count' => count($violations)]);

            return ['status' => 'alert', 'unauthorized_changes' => $violations];
        }

        return ['status' => 'ok'];
    }

    /**
     * @return array{ok: bool, files_hashed?: int, error?: string}|array{files: array<string, string>}
     */
    private static function hashScanPaths(Config $config): array
    {
        $p = self::cfg($config);
        if ($p === null) {
            return ['error' => 'police disabled'];
        }
        $roots = $p['integrity_scan_paths'] ?? ['app/Core/Evolution'];
        if (!is_array($roots)) {
            $roots = ['app/Core/Evolution'];
        }
        $max = max(100, min(10000, (int) ($p['max_scan_files'] ?? self::DEFAULT_MAX_FILES)));
        $out = [];
        $n = 0;
        foreach ($roots as $rel) {
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $base = BASE_PATH . '/' . str_replace('\\', '/', trim($rel, '/'));
            if (!is_dir($base) && is_file($base)) {
                $h = @hash_file('sha256', $base);
                if (is_string($h)) {
                    $out[self::relKey($rel)] = $h;
                    ++$n;
                }

                continue;
            }
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($n >= $max) {
                    return ['error' => 'max_scan_files exceeded (' . $max . ')'];
                }
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (!str_ends_with(strtolower($path), '.php')) {
                    continue;
                }
                $relPath = ltrim(str_replace('\\', '/', substr($path, strlen(BASE_PATH))), '/');
                $h = @hash_file('sha256', $path);
                if (is_string($h)) {
                    $out[$relPath] = $h;
                    ++$n;
                }
            }
        }

        return ['files' => $out];
    }

    private static function relKey(string $rel): string
    {
        return ltrim(str_replace('\\', '/', $rel), '/');
    }

    /**
     * @return array{ok: bool, files_hashed: int, path: string}|array{ok: bool, error: string}
     */
    public static function writeBaseline(Config $config): array
    {
        $p = self::cfg($config);
        if ($p === null) {
            return ['ok' => false, 'error' => 'police disabled'];
        }
        $current = self::hashScanPaths($config);
        if (isset($current['error'])) {
            return ['ok' => false, 'error' => (string) $current['error']];
        }
        /** @var array<string, string> $files */
        $files = $current['files'] ?? [];
        $baselinePath = self::flagPath($config, (string) ($p['baseline_file'] ?? 'storage/evolution/police_integrity_baseline.json'));
        $dir = dirname($baselinePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = json_encode([
            'ts' => gmdate('c'),
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload) || @file_put_contents($baselinePath, $payload . "\n", LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'write failed'];
        }

        return ['ok' => true, 'files_hashed' => count($files), 'path' => $baselinePath];
    }

    /**
     * @return array<string, mixed>
     */
    public static function collectEvidence(Config $config): array
    {
        $p = self::cfg($config);
        if ($p === null) {
            return ['enabled' => false];
        }
        $paths = $p['evidence_log_paths'] ?? [
            'data/logs',
        ];
        if (!is_array($paths)) {
            $paths = [];
        }
        $lines = max(10, min(200, (int) ($p['evidence_tail_lines'] ?? 50)));
        $out = [];
        foreach ($paths as $rel) {
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $full = BASE_PATH . '/' . str_replace('\\', '/', trim($rel, '/'));
            if (is_file($full)) {
                $out[self::relKey($rel)] = self::tailFile($full, $lines);

                continue;
            }
            if (is_dir($full)) {
                $globbed = array_merge(
                    glob($full . '/*.log') ?: [],
                    glob($full . '/**/*.log') ?: []
                );
                foreach (array_slice($globbed, 0, 12) as $gf) {
                    if (is_string($gf) && is_file($gf)) {
                        $rk = ltrim(str_replace('\\', '/', substr($gf, strlen(BASE_PATH))), '/');
                        $out[$rk] = self::tailFile($gf, $lines);
                    }
                }
            }
        }

        $evidenceDir = BASE_PATH . '/data/evolution/police_evidence';
        if (!is_dir($evidenceDir)) {
            @mkdir($evidenceDir, 0755, true);
        }
        $file = $evidenceDir . '/last_evidence_' . gmdate('Ymd_His') . '.json';
        @file_put_contents($file, json_encode(['ts' => gmdate('c'), 'tails' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['enabled' => true, 'ts' => gmdate('c'), 'tails' => $out, 'written' => $file];
    }

    /**
     * @return list<string>
     */
    private static function tailFile(string $path, int $lines): array
    {
        $content = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($content)) {
            return [];
        }
        $slice = array_slice($content, -$lines);

        return array_values($slice);
    }

    private static function flagPath(Config $config, string $rel): string
    {
        return BASE_PATH . '/' . ltrim(str_replace('\\', '/', $rel), '/');
    }

    /**
     * @return array<string, mixed>
     */
    private static function readCells(Config $config): array
    {
        $p = self::cfg($config);
        if ($p === null) {
            return [];
        }
        $path = self::flagPath($config, (string) ($p['cell_file'] ?? 'storage/evolution/police_cell.json'));
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($j) ? $j : [];
    }

    private static function writeCells(Config $config, array $data): void
    {
        $p = self::cfg($config);
        if ($p === null) {
            return;
        }
        $path = self::flagPath($config, (string) ($p['cell_file'] ?? 'storage/evolution/police_cell.json'));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
    }

    public static function arrestAgent(Config $config, string $agentId, int $minutes, string $reason): void
    {
        if (self::cfg($config) === null) {
            return;
        }
        $minutes = max(1, min(24 * 60, $minutes));
        $cells = self::readCells($config);
        if (!isset($cells['agents']) || !is_array($cells['agents'])) {
            $cells['agents'] = [];
        }
        /** @var array<string, mixed> $agents */
        $agents = $cells['agents'];
        $until = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $minutes . ' minutes');
        $agents[$agentId] = [
            'until' => $until->format('c'),
            'reason' => mb_substr($reason, 0, 500),
            'since' => gmdate('c'),
        ];
        $cells['agents'] = $agents;
        self::writeCells($config, $cells);
        EvolutionLogger::log('police_cell', 'arrest', ['agent' => $agentId, 'minutes' => $minutes]);
    }

    public static function releaseAgent(Config $config, string $agentId): void
    {
        if (self::cfg($config) === null) {
            return;
        }
        $cells = self::readCells($config);
        $agents = $cells['agents'] ?? [];
        if (is_array($agents) && isset($agents[$agentId])) {
            unset($agents[$agentId]);
            $cells['agents'] = $agents;
            self::writeCells($config, $cells);
            EvolutionLogger::log('police_cell', 'release', ['agent' => $agentId]);
        }
    }

    public static function isAgentInCell(Config $config, string $agentId): bool
    {
        $cells = self::readCells($config);
        $agents = $cells['agents'] ?? [];
        if (!is_array($agents) || !isset($agents[$agentId]) || !is_array($agents[$agentId])) {
            return false;
        }
        $row = $agents[$agentId];
        $until = isset($row['until']) ? strtotime((string) $row['until']) : false;
        if ($until === false || $until < time()) {
            self::releaseAgent($config, $agentId);

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getCellInfo(Config $config, string $agentId): array
    {
        $cells = self::readCells($config);
        $agents = $cells['agents'] ?? [];

        return is_array($agents) && isset($agents[$agentId]) && is_array($agents[$agentId]) ? $agents[$agentId] : [];
    }

    /**
     * Safe default: no kills. Returns hints for manual / systemd intervention.
     *
     * @return array<string, mixed>
     */
    public static function runawayProcessHints(Config $config): array
    {
        $p = self::cfg($config);
        if ($p === null) {
            return ['enabled' => false];
        }
        $k = $p['kill_runaway'] ?? [];
        if (!is_array($k) || !filter_var($k['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return [
                'enabled' => false,
                'note' => 'Set evolution.police.kill_runaway.enabled=true only on controlled Linux workers; use systemd limits in production.',
            ];
        }

        return [
            'enabled' => true,
            'note' => 'Automated kill is not executed from PHP in this build; use officer-approved shell after inspecting PIDs.',
            'pattern' => (string) ($k['pgrep_pattern'] ?? 'php'),
        ];
    }

    public static function detectCpuCores(): int
    {
        if (function_exists('shell_exec')) {
            $n = @shell_exec('nproc 2>/dev/null');
            if (is_string($n) && ctype_digit(trim($n))) {
                return max(1, (int) trim($n));
            }
        }
        $w = getenv('NUMBER_OF_PROCESSORS');

        return ($w !== false && ctype_digit((string) $w)) ? max(1, (int) $w) : 4;
    }

    /**
     * Approximate "CPU pressure" via loadavg / cores (Linux-friendly). Not identical to CPU %.
     *
     * @return array<string, mixed>
     */
    public static function evaluateLoadPressure(Config $config): array
    {
        $p = self::cfg($config);
        $la = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        $l1 = is_array($la) && isset($la[0]) ? (float) $la[0] : 0.0;
        $cores = self::detectCpuCores();
        $ratio = $cores > 0 ? $l1 / $cores : $l1;
        $thr = 0.8;
        if (is_array($p)) {
            $laCfg = $p['load_arrest'] ?? [];
            if (is_array($laCfg) && isset($laCfg['ratio_threshold'])) {
                $thr = max(0.1, min(5.0, (float) $laCfg['ratio_threshold']));
            }
        }

        return [
            'loadavg_1' => $l1,
            'cores' => $cores,
            'load_per_core_ratio' => round($ratio, 4),
            'threshold_ratio' => $thr,
            'over_threshold' => $ratio >= $thr,
            'summary' => 'load1=' . $l1 . ' cores=' . $cores . ' ratio=' . round($ratio, 3) . ' thr=' . $thr,
        ];
    }

    /**
     * Under high load: Arrest() targets the Architect API (cell), not OS kill — safer than killing PHP from PHP.
     *
     * @param array<string, mixed>|null $pressure precomputed from evaluateLoadPressure
     */
    public static function maybeArrestOnHighLoad(Config $config, ?array $pressure = null): void
    {
        $p = self::cfg($config);
        if ($p === null) {
            return;
        }
        $laCfg = $p['load_arrest'] ?? [];
        if (!is_array($laCfg) || !filter_var($laCfg['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return;
        }
        $pressure ??= self::evaluateLoadPressure($config);
        if (!($pressure['over_threshold'] ?? false)) {
            return;
        }

        $cooldown = max(60, (int) ($laCfg['cooldown_seconds'] ?? 300));
        $stampPath = BASE_PATH . '/data/evolution/police_load_arrest_ts.txt';
        $dir = dirname($stampPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_file($stampPath)) {
            $last = (int) (string) @file_get_contents($stampPath);
            if ($last > 0 && $last > time() - $cooldown) {
                return;
            }
        }
        @file_put_contents($stampPath, (string) time(), LOCK_EX);

        $agent = (string) ($laCfg['target_agent'] ?? 'architect');
        $mins = max(1, (int) ($laCfg['arrest_minutes'] ?? 20));
        $reason = 'Load pressure (loadavg/cores ≥ threshold): ' . (string) ($pressure['summary'] ?? '');
        self::arrestAgent($config, $agent, $mins, $reason);
    }
}
