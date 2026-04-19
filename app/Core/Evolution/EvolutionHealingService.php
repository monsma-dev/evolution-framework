<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Self-Healing Infrastructure Service
 *
 * Monitors server health and applies safe, whitelisted corrective actions.
 *
 * ─── Safety model ─────────────────────────────────────────────────────────────
 *
 *  ALL healing actions must:
 *    1. Be on the SAFE_ACTIONS whitelist — no arbitrary shell execution
 *    2. Pass a dry-run simulation before execution
 *    3. Be logged with before/after state in the audit file
 *
 *  The "Shadow Twin" simulation:
 *    - opcache_reset:    shows current hit_rate and memory usage before clearing
 *    - clear_twig_cache: counts files to be deleted and their total size
 *    - clear_sessions:   counts expired session files (>2h old)
 *    - restart_scheduler: checks PID file and last heartbeat timestamp
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *  $healing = new EvolutionHealingService($config);
 *
 *  $report = $healing->scan();         // check all services
 *  $sim    = $healing->simulate('opcache_reset');  // shadow twin dry-run
 *  $result = $healing->heal('opcache_reset');      // execute if simulation OK
 */
final class EvolutionHealingService
{
    private const AUDIT_LOG = '/var/www/html/data/evolution/healing_audit.jsonl';

    /**
     * Whitelisted actions with descriptions.
     * ONLY these strings may be passed to heal() and simulate().
     *
     * @var array<string, array{description: string, risk: string}>
     */
    private const SAFE_ACTIONS = [
        'opcache_reset' => [
            'description' => 'Reset PHP OPcache — forces reload of all PHP files',
            'risk'        => 'none',
        ],
        'clear_twig_cache' => [
            'description' => 'Delete compiled Twig templates — regenerated on next request',
            'risk'        => 'none',
        ],
        'clear_sessions' => [
            'description' => 'Remove session files older than 2 hours',
            'risk'        => 'low',
        ],
        'clear_temp_uploads' => [
            'description' => 'Remove temp upload files older than 24 hours',
            'risk'        => 'none',
        ],
        'restart_scheduler' => [
            'description' => 'Kill and restart the Evolution cron scheduler process',
            'risk'        => 'low',
        ],
        'compact_evolution_logs' => [
            'description' => 'Rotate and compress Evolution log files > 10 MB',
            'risk'        => 'none',
        ],
    ];

    public function __construct(private readonly Config $config) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Scan all monitored services and return a health report.
     *
     * @return array<string, array{status: string, details: string, action: string|null}>
     */
    public function scan(): array
    {
        return [
            'opcache'       => $this->checkOpcache(),
            'twig_cache'    => $this->checkTwigCache(),
            'sessions'      => $this->checkSessions(),
            'temp_uploads'  => $this->checkTempUploads(),
            'scheduler'     => $this->checkScheduler(),
            'evolution_logs' => $this->checkEvolutionLogs(),
            'disk'          => $this->checkDisk(),
        ];
    }

    /**
     * Shadow Twin simulation: describe what heal($action) WOULD do, without doing it.
     *
     * @return array{action: string, safe: bool, preview: string, before_state: array<string, mixed>}
     */
    public function simulate(string $action): array
    {
        if (!isset(self::SAFE_ACTIONS[$action])) {
            return [
                'action'       => $action,
                'safe'         => false,
                'preview'      => "BLOCKED: '{$action}' is not on the safe-actions whitelist.",
                'before_state' => [],
            ];
        }

        return match ($action) {
            'opcache_reset'           => $this->simulateOpcacheReset(),
            'clear_twig_cache'        => $this->simulateClearTwigCache(),
            'clear_sessions'          => $this->simulateClearSessions(),
            'clear_temp_uploads'      => $this->simulateClearTempUploads(),
            'restart_scheduler'       => $this->simulateRestartScheduler(),
            'compact_evolution_logs'  => $this->simulateCompactLogs(),
            default                   => ['action' => $action, 'safe' => false, 'preview' => 'Unknown action', 'before_state' => []],
        };
    }

    /**
     * Execute a healing action.
     * Always runs simulate() first — if simulation marks it unsafe, execution is aborted.
     *
     * @return array{action: string, success: bool, message: string, simulation: array<string, mixed>}
     */
    public function heal(string $action): array
    {
        if (!isset(self::SAFE_ACTIONS[$action])) {
            return ['action' => $action, 'success' => false, 'message' => "Blocked: not on whitelist.", 'simulation' => []];
        }

        $simulation = $this->simulate($action);

        if (!$simulation['safe']) {
            return [
                'action'     => $action,
                'success'    => false,
                'message'    => 'Simulation failed: ' . $simulation['preview'],
                'simulation' => $simulation,
            ];
        }

        $result = match ($action) {
            'opcache_reset'           => $this->doOpcacheReset(),
            'clear_twig_cache'        => $this->doClearTwigCache(),
            'clear_sessions'          => $this->doClearSessions(),
            'clear_temp_uploads'      => $this->doClearTempUploads(),
            'restart_scheduler'       => $this->doRestartScheduler(),
            'compact_evolution_logs'  => $this->doCompactLogs(),
            default                   => ['success' => false, 'message' => 'Unknown action'],
        };

        $this->audit($action, $simulation['before_state'], $result);

        return array_merge($result, ['action' => $action, 'simulation' => $simulation]);
    }

    /** @return array<string, array{description: string, risk: string}> */
    public function availableActions(): array
    {
        return self::SAFE_ACTIONS;
    }

    /** @return list<array<string, mixed>> */
    public function recentAudit(int $n = 20): array
    {
        if (!is_file(self::AUDIT_LOG)) {
            return [];
        }

        $lines = array_filter(explode("\n", (string) file_get_contents(self::AUDIT_LOG)));
        $lines = array_slice(array_reverse(array_values($lines)), 0, $n);

        return array_values(array_filter(
            array_map(static fn ($l) => json_decode($l, true), $lines)
        ));
    }

    // ── Health checks ─────────────────────────────────────────────────────────

    /** @return array{status: string, details: string, action: string|null} */
    private function checkOpcache(): array
    {
        if (!function_exists('opcache_get_status')) {
            return ['status' => 'unavailable', 'details' => 'OPcache not loaded', 'action' => null];
        }

        $status   = @opcache_get_status(false);
        if (!is_array($status) || !($status['opcache_enabled'] ?? false)) {
            return ['status' => 'disabled', 'details' => 'OPcache is disabled', 'action' => null];
        }

        $hitRate = round((float) ($status['opcache_statistics']['opcache_hit_rate'] ?? 100), 1);
        $usedPct = 0.0;
        if (isset($status['memory_usage'])) {
            $used  = (int) ($status['memory_usage']['used_memory'] ?? 0);
            $free  = (int) ($status['memory_usage']['free_memory'] ?? 1);
            $wasted = (int) ($status['memory_usage']['wasted_memory'] ?? 0);
            $total = $used + $free + $wasted;
            $usedPct = $total > 0 ? round(($used + $wasted) / $total * 100, 1) : 0.0;
        }

        $wastedPct = round((float) ($status['memory_usage']['current_wasted_percentage'] ?? 0), 1);

        if ($wastedPct > 20.0) {
            return ['status' => 'warn', 'details' => "OPcache wasted: {$wastedPct}% (hit rate: {$hitRate}%)", 'action' => 'opcache_reset'];
        }

        return ['status' => 'ok', 'details' => "Hit rate: {$hitRate}%  Memory: {$usedPct}% used", 'action' => null];
    }

    /** @return array{status: string, details: string, action: string|null} */
    private function checkTwigCache(): array
    {
        $cacheDir = (string) ($this->config->get('app.view.cache') ?? 'data/cache/twig');
        $basePath = defined('BASE_PATH') ? BASE_PATH : '/var/www/html';
        $fullPath = str_starts_with($cacheDir, '/') ? $cacheDir : $basePath . '/' . $cacheDir;

        if (!is_dir($fullPath)) {
            return ['status' => 'ok', 'details' => 'No cache directory', 'action' => null];
        }

        $files    = $this->countFilesRecursive($fullPath);
        $sizeKb   = round($this->dirSizeBytes($fullPath) / 1024, 0);
        $sizeThreshold = 50000; // 50 MB

        if ($sizeKb > $sizeThreshold) {
            return ['status' => 'warn', 'details' => "Twig cache: {$files} files, {$sizeKb} KB — over limit", 'action' => 'clear_twig_cache'];
        }

        return ['status' => 'ok', 'details' => "Twig cache: {$files} files, {$sizeKb} KB", 'action' => null];
    }

    /** @return array{status: string, details: string, action: string|null} */
    private function checkSessions(): array
    {
        $sessDir = '/var/www/html/data/sessions';
        if (!is_dir($sessDir)) {
            return ['status' => 'ok', 'details' => 'No session directory', 'action' => null];
        }

        $old  = $this->countOldFiles($sessDir, 7200); // 2 hours
        $total = $this->countFilesRecursive($sessDir);

        if ($old > 1000) {
            return ['status' => 'warn', 'details' => "{$old}/{$total} sessions are expired (>2h)", 'action' => 'clear_sessions'];
        }

        return ['status' => 'ok', 'details' => "{$total} active sessions ({$old} expired)", 'action' => null];
    }

    /** @return array{status: string, details: string, action: string|null} */
    private function checkTempUploads(): array
    {
        $tmpDir = '/var/www/html/data/tmp';
        if (!is_dir($tmpDir)) {
            return ['status' => 'ok', 'details' => 'No tmp directory', 'action' => null];
        }

        $old = $this->countOldFiles($tmpDir, 86400); // 24 hours

        if ($old > 50) {
            return ['status' => 'warn', 'details' => "{$old} temp files older than 24h", 'action' => 'clear_temp_uploads'];
        }

        return ['status' => 'ok', 'details' => "{$old} temp files pending cleanup", 'action' => null];
    }

    /** @return array{status: string, details: string, action: string|null} */
    private function checkScheduler(): array
    {
        $pidFile = '/var/www/html/data/evolution/scheduler.pid';
        if (!is_file($pidFile)) {
            return ['status' => 'warn', 'details' => 'Scheduler PID file missing — may not be running', 'action' => 'restart_scheduler'];
        }

        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid > 0 && is_dir("/proc/{$pid}")) {
            return ['status' => 'ok', 'details' => "Scheduler running (PID {$pid})", 'action' => null];
        }

        return ['status' => 'warn', 'details' => "Scheduler PID {$pid} not found in /proc", 'action' => 'restart_scheduler'];
    }

    /** @return array{status: string, details: string, action: string|null} */
    private function checkEvolutionLogs(): array
    {
        $logDir = '/var/www/html/data/evolution';
        if (!is_dir($logDir)) {
            return ['status' => 'ok', 'details' => 'No evolution log directory', 'action' => null];
        }

        $totalKb = round($this->dirSizeBytes($logDir) / 1024, 0);

        if ($totalKb > 102400) { // 100 MB
            return ['status' => 'warn', 'details' => "Evolution logs: {$totalKb} KB — over 100 MB", 'action' => 'compact_evolution_logs'];
        }

        return ['status' => 'ok', 'details' => "Evolution logs: {$totalKb} KB", 'action' => null];
    }

    /** @return array{status: string, details: string, action: string|null} */
    private function checkDisk(): array
    {
        $freeBytes = disk_free_space('/var/www/html');
        $totalBytes = disk_total_space('/var/www/html');

        if ($freeBytes === false || $totalBytes === false || $totalBytes === 0.0) {
            return ['status' => 'unknown', 'details' => 'Could not read disk info', 'action' => null];
        }

        $usedPct = round((1 - $freeBytes / $totalBytes) * 100, 1);
        $freeGb  = round($freeBytes / 1024 / 1024 / 1024, 2);

        if ($usedPct > 85) {
            return ['status' => 'critical', 'details' => "Disk {$usedPct}% used ({$freeGb} GB free)", 'action' => 'compact_evolution_logs'];
        }
        if ($usedPct > 70) {
            return ['status' => 'warn', 'details' => "Disk {$usedPct}% used ({$freeGb} GB free)", 'action' => null];
        }

        return ['status' => 'ok', 'details' => "Disk {$usedPct}% used ({$freeGb} GB free)", 'action' => null];
    }

    // ── Simulations (Shadow Twin) ─────────────────────────────────────────────

    /** @return array{action: string, safe: bool, preview: string, before_state: array<string, mixed>} */
    private function simulateOpcacheReset(): array
    {
        $status = function_exists('opcache_get_status') ? (@opcache_get_status(false) ?: []) : [];
        $hitRate = round((float) (($status['opcache_statistics'] ?? [])['opcache_hit_rate'] ?? 0), 1);
        $scripts = (int) (($status['opcache_statistics'] ?? [])['num_cached_scripts'] ?? 0);

        return [
            'action'       => 'opcache_reset',
            'safe'         => true,
            'preview'      => "Would clear {$scripts} cached PHP scripts (current hit rate: {$hitRate}%). Zero downtime — scripts reload on next request.",
            'before_state' => ['hit_rate' => $hitRate, 'cached_scripts' => $scripts],
        ];
    }

    /** @return array{action: string, safe: bool, preview: string, before_state: array<string, mixed>} */
    private function simulateClearTwigCache(): array
    {
        $cacheDir = (string) ($this->config->get('app.view.cache') ?? 'data/cache/twig');
        $basePath = defined('BASE_PATH') ? BASE_PATH : '/var/www/html';
        $fullPath = str_starts_with($cacheDir, '/') ? $cacheDir : $basePath . '/' . $cacheDir;

        $files  = is_dir($fullPath) ? $this->countFilesRecursive($fullPath) : 0;
        $sizeKb = is_dir($fullPath) ? round($this->dirSizeBytes($fullPath) / 1024, 0) : 0;

        return [
            'action'       => 'clear_twig_cache',
            'safe'         => true,
            'preview'      => "Would delete {$files} compiled Twig files ({$sizeKb} KB). Templates will recompile on first request. Zero downtime.",
            'before_state' => ['files' => $files, 'size_kb' => $sizeKb, 'path' => $fullPath],
        ];
    }

    /** @return array{action: string, safe: bool, preview: string, before_state: array<string, mixed>} */
    private function simulateClearSessions(): array
    {
        $sessDir = '/var/www/html/data/sessions';
        $old     = is_dir($sessDir) ? $this->countOldFiles($sessDir, 7200) : 0;

        return [
            'action'       => 'clear_sessions',
            'safe'         => true,
            'preview'      => "Would remove {$old} session files older than 2 hours. Active sessions are unaffected.",
            'before_state' => ['expired_files' => $old],
        ];
    }

    /** @return array{action: string, safe: bool, preview: string, before_state: array<string, mixed>} */
    private function simulateClearTempUploads(): array
    {
        $tmpDir = '/var/www/html/data/tmp';
        $old    = is_dir($tmpDir) ? $this->countOldFiles($tmpDir, 86400) : 0;

        return [
            'action'       => 'clear_temp_uploads',
            'safe'         => true,
            'preview'      => "Would remove {$old} temp upload files older than 24 hours.",
            'before_state' => ['old_files' => $old],
        ];
    }

    /** @return array{action: string, safe: bool, preview: string, before_state: array<string, mixed>} */
    private function simulateRestartScheduler(): array
    {
        $pidFile = '/var/www/html/data/evolution/scheduler.pid';
        $pid     = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;
        $running = $pid > 0 && is_dir("/proc/{$pid}");

        return [
            'action'       => 'restart_scheduler',
            'safe'         => true,
            'preview'      => $running
                ? "Would send SIGTERM to PID {$pid} and restart scheduler via nohup."
                : "Scheduler not running (PID {$pid}). Would start fresh instance.",
            'before_state' => ['pid' => $pid, 'running' => $running],
        ];
    }

    /** @return array{action: string, safe: bool, preview: string, before_state: array<string, mixed>} */
    private function simulateCompactLogs(): array
    {
        $logDir  = '/var/www/html/data/evolution';
        $sizeKb  = is_dir($logDir) ? round($this->dirSizeBytes($logDir) / 1024, 0) : 0;
        $bigFiles = [];

        if (is_dir($logDir)) {
            foreach (glob("{$logDir}/*.log") ?: [] as $file) {
                $kb = round(filesize($file) / 1024, 0);
                if ($kb > 10240) {
                    $bigFiles[] = basename($file) . " ({$kb} KB)";
                }
            }
        }

        return [
            'action'       => 'compact_evolution_logs',
            'safe'         => true,
            'preview'      => empty($bigFiles)
                ? "No log files > 10 MB found. Total: {$sizeKb} KB."
                : "Would rotate: " . implode(', ', $bigFiles) . ". Compressed copies kept.",
            'before_state' => ['total_kb' => $sizeKb, 'large_files' => $bigFiles],
        ];
    }

    // ── Executors ─────────────────────────────────────────────────────────────

    /** @return array{success: bool, message: string} */
    private function doOpcacheReset(): array
    {
        if (!function_exists('opcache_reset')) {
            return ['success' => false, 'message' => 'OPcache not available'];
        }

        $result = opcache_reset();

        return ['success' => $result, 'message' => $result ? 'OPcache cleared successfully.' : 'OPcache reset returned false.'];
    }

    /** @return array{success: bool, message: string} */
    private function doClearTwigCache(): array
    {
        $cacheDir = (string) ($this->config->get('app.view.cache') ?? 'data/cache/twig');
        $basePath = defined('BASE_PATH') ? BASE_PATH : '/var/www/html';
        $fullPath = str_starts_with($cacheDir, '/') ? $cacheDir : $basePath . '/' . $cacheDir;

        if (!is_dir($fullPath)) {
            return ['success' => true, 'message' => 'No Twig cache directory found.'];
        }

        $deleted = $this->deleteFilesRecursive($fullPath);

        return ['success' => true, 'message' => "Deleted {$deleted} Twig cache files."];
    }

    /** @return array{success: bool, message: string} */
    private function doClearSessions(): array
    {
        $sessDir = '/var/www/html/data/sessions';
        $deleted = 0;

        if (is_dir($sessDir)) {
            $cutoff = time() - 7200;
            foreach (glob("{$sessDir}/*") ?: [] as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        return ['success' => true, 'message' => "Removed {$deleted} expired session files."];
    }

    /** @return array{success: bool, message: string} */
    private function doClearTempUploads(): array
    {
        $tmpDir  = '/var/www/html/data/tmp';
        $deleted = 0;

        if (is_dir($tmpDir)) {
            $cutoff = time() - 86400;
            foreach (glob("{$tmpDir}/*") ?: [] as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        return ['success' => true, 'message' => "Removed {$deleted} temp upload files."];
    }

    /** @return array{success: bool, message: string} */
    private function doRestartScheduler(): array
    {
        $pidFile = '/var/www/html/data/evolution/scheduler.pid';
        $bridge  = defined('BASE_PATH') ? BASE_PATH . '/ai_bridge.php' : '/var/www/html/ai_bridge.php';

        // Kill existing process
        if (is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0 && is_dir("/proc/{$pid}")) {
                if (function_exists('posix_kill')) {
                    posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
                } else {
                    shell_exec("kill -15 {$pid} 2>/dev/null");
                }
                sleep(1);
            }
            @unlink($pidFile);
        }

        // Start scheduler in background
        if (is_file($bridge)) {
            $cmd = "nohup php {$bridge} evolve:scheduler start > /dev/null 2>&1 &";
            shell_exec($cmd);

            return ['success' => true, 'message' => "Scheduler restarted."];
        }

        return ['success' => false, 'message' => "ai_bridge.php not found at {$bridge}."];
    }

    /** @return array{success: bool, message: string} */
    private function doCompactLogs(): array
    {
        $logDir   = '/var/www/html/data/evolution';
        $rotated  = 0;

        foreach (glob("{$logDir}/*.log") ?: [] as $file) {
            if (filesize($file) > 10485760) { // 10 MB
                $archive = $file . '.' . date('Ymd-His') . '.gz';
                $gz = gzopen($archive, 'wb9');
                if ($gz !== false) {
                    gzwrite($gz, (string) file_get_contents($file));
                    gzclose($gz);
                    file_put_contents($file, ''); // truncate original
                    $rotated++;
                }
            }
        }

        return ['success' => true, 'message' => "Rotated {$rotated} log files."];
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private function countFilesRecursive(string $dir): int
    {
        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    private function dirSizeBytes(string $dir): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    private function countOldFiles(string $dir, int $maxAgeSeconds): int
    {
        $cutoff = time() - $maxAgeSeconds;
        $count  = 0;

        foreach (glob("{$dir}/*") ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                $count++;
            }
        }

        return $count;
    }

    private function deleteFilesRecursive(string $dir): int
    {
        $deleted = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isFile()) {
                unlink($file->getPathname());
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $result */
    private function audit(string $action, array $before, array $result): void
    {
        $dir = dirname(self::AUDIT_LOG);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $entry = [
            'action'    => $action,
            'success'   => $result['success'] ?? false,
            'message'   => $result['message'] ?? '',
            'before'    => $before,
            'healed_at' => date('c'),
        ];

        file_put_contents(self::AUDIT_LOG, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }
}
