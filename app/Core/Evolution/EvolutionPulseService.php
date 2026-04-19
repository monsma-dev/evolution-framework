<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use PDO;
use PDOException;

/**
 * Autonomous heartbeat: DB + cache + optional HTTP self-check; logs degradations before users hit errors.
 */
final class EvolutionPulseService
{
    public const STATE_FILE = 'storage/evolution/pulse_state.json';
    public const LOG_FILE = 'storage/evolution/pulse_log.jsonl';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, status: string, checks: array<string, mixed>, latency_ms_total?: float}
     */
    public function runDeepPulse(): array
    {
        $cfg = $this->container->get('config');
        $p = $cfg->get('evolution.pulse', []);
        if (!is_array($p) || !filter_var($p['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'status' => 'disabled', 'checks' => []];
        }

        $t0 = microtime(true);
        $checks = [];
        $failed = false;

        $checks['db'] = $this->checkDb();
        if (!($checks['db']['ok'] ?? false)) {
            $failed = true;
        }

        $checks['cache'] = $this->checkCache();
        if (!($checks['cache']['ok'] ?? false)) {
            $failed = true;
        }

        if (filter_var($p['http_health_ping'] ?? true, FILTER_VALIDATE_BOOL)) {
            $checks['http'] = $this->checkHttpHealth();
            if (!($checks['http']['ok'] ?? false)) {
                $failed = true;
            }
        }

        $checks['ai_visibility'] = EvolutionAeoService::pulseCheck($cfg);

        $totalMs = round((microtime(true) - $t0) * 1000, 2);
        $slowMs = max(100.0, (float) ($p['slow_threshold_ms'] ?? 800.0));
        $degraded = $totalMs > $slowMs;

        $status = $failed ? 'failed' : ($degraded ? 'degraded' : 'ok');
        $this->persistState($status, $totalMs, $checks, $failed, $degraded);

        $line = json_encode([
            'ts' => gmdate('c'),
            'status' => $status,
            'latency_ms_total' => $totalMs,
            'checks' => $checks,
        ], JSON_UNESCAPED_UNICODE);
        if (is_string($line)) {
            @file_put_contents(BASE_PATH . '/' . self::LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
        }

        if ($failed || $degraded) {
            EvolutionLogger::log('pulse', $status, ['latency_ms' => $totalMs]);
        }
        if ($failed) {
            EvolutionFlightRecorder::capture($cfg, 'pulse_' . $status, [
                'latency_ms_total' => $totalMs,
                'checks' => $checks,
            ]);
        }

        return [
            'ok' => !$failed,
            'status' => $status,
            'checks' => $checks,
            'latency_ms_total' => $totalMs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lastState(): array
    {
        $path = BASE_PATH . '/' . self::STATE_FILE;
        if (!is_file($path)) {
            return ['status' => 'unknown'];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($j) ? $j : ['status' => 'unknown'];
    }

    /**
     * @return array{ok: bool, latency_ms?: float, error?: string}
     */
    private function checkDb(): array
    {
        $t0 = microtime(true);
        try {
            /** @var PDO $pdo */
            $pdo = $this->container->get('db');
            $pdo->query('SELECT 1');

            return ['ok' => true, 'latency_ms' => round((microtime(true) - $t0) * 1000, 2)];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, latency_ms?: float, error?: string}
     */
    private function checkCache(): array
    {
        $t0 = microtime(true);
        try {
            $cache = $this->container->get('cache');
            if (method_exists($cache, 'get')) {
                $cache->get('__evolution_pulse_probe__');
            }

            return ['ok' => true, 'latency_ms' => round((microtime(true) - $t0) * 1000, 2)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, http_status?: int, error?: string}
     */
    private function checkHttpHealth(): array
    {
        $cfg = $this->container->get('config');
        $base = rtrim((string) $cfg->get('site.url', ''), '/');
        if ($base === '') {
            return ['ok' => true];
        }
        $url = $base . '/api/v1/health';
        $ctx = stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true],
        ]);
        $http_response_header = [];
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if (!is_string($raw) || $code >= 400) {
            return ['ok' => false, 'http_status' => $code, 'error' => 'health endpoint failed'];
        }

        return ['ok' => true, 'http_status' => $code];
    }

    /**
     * @param array<string, mixed> $checks
     */
    private function persistState(string $status, float $totalMs, array $checks, bool $failed, bool $degraded): void
    {
        $path = BASE_PATH . '/' . self::STATE_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $prev = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $prev = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        }
        $failCount = (int) ($prev['consecutive_failures'] ?? 0);
        $failCount = $failed ? $failCount + 1 : 0;

        $payload = [
            'updated_at' => gmdate('c'),
            'status' => $status,
            'latency_ms_total' => $totalMs,
            'degraded' => $degraded,
            'consecutive_failures' => $failCount,
            'checks' => $checks,
            'note' => 'HotSwapService rolls back PHP fatals; pulse alerts surface before user traffic when monitors fail.',
        ];
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
