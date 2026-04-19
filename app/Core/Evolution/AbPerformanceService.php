<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Autonomous A/B for admin: assigns variant A/B cookie, records peak memory + wall time per request.
 * After enough samples, evaluate() picks the leaner/faster variant (for AI-driven experiments).
 */
final class AbPerformanceService
{
    private const COOKIE = 'fw_ab_perf';
    private const METRICS = 'storage/evolution/ab_perf_metrics.jsonl';

    public static function isEnabled(?Config $config = null): bool
    {
        $cfg = $config ?? self::cfg();
        if ($cfg === null) {
            return false;
        }
        $ab = $cfg->get('evolution.ab_performance', []);

        return is_array($ab) && filter_var($ab['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Ensure cookie; returns variant A or B.
     */
    public static function ensureVariantCookie(): string
    {
        if (!empty($_COOKIE[self::COOKIE]) && in_array($_COOKIE[self::COOKIE], ['A', 'B'], true)) {
            return $_COOKIE[self::COOKIE];
        }
        $v = (random_int(0, 1) === 0) ? 'A' : 'B';
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE, $v, [
            'expires' => time() + 86400 * 30,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $v;

        return $v;
    }

    /**
     * Append one request sample (call after response, from middleware).
     */
    public static function recordSample(string $path, float $startMicrotime, string $variant): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $line = json_encode([
            'ts' => gmdate('c'),
            'variant' => $variant,
            'path' => $path,
            'peak_bytes' => memory_get_peak_usage(true),
            'wall_ms' => round((microtime(true) - $startMicrotime) * 1000, 3),
        ], JSON_UNESCAPED_UNICODE) . "\n";

        $p = BASE_PATH . '/' . self::METRICS;
        $dir = dirname($p);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($p, $line, FILE_APPEND | LOCK_EX);
        self::trimMetrics($p);
    }

    /**
     * Aggregate last N lines; returns winner variant or null if inconclusive.
     *
     * @return array{ok: bool, winner: ?string, stats: array<string, array{count: int, avg_peak_mb: float, avg_wall_ms: float}>}
     */
    public static function evaluate(?Config $config = null): array
    {
        $cfg = $config ?? self::cfg();
        $minSamples = 100;
        if ($cfg !== null) {
            $ab = $cfg->get('evolution.ab_performance', []);
            $minSamples = max(20, (int) ($ab['min_samples'] ?? 100));
        }
        $p = BASE_PATH . '/' . self::METRICS;
        if (!is_file($p)) {
            return ['ok' => true, 'winner' => null, 'stats' => []];
        }
        $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $rows = [];
        foreach (array_slice($lines, -2000) as $line) {
            $j = @json_decode($line, true);
            if (is_array($j) && isset($j['variant'])) {
                $rows[] = $j;
            }
        }
        $stats = ['A' => ['count' => 0, 'peak' => 0.0, 'wall' => 0.0], 'B' => ['count' => 0, 'peak' => 0.0, 'wall' => 0.0]];
        foreach ($rows as $r) {
            $v = $r['variant'] ?? '';
            if (!isset($stats[$v])) {
                continue;
            }
            $stats[$v]['count']++;
            $stats[$v]['peak'] += (int) ($r['peak_bytes'] ?? 0);
            $stats[$v]['wall'] += (float) ($r['wall_ms'] ?? 0);
        }
        $out = [];
        foreach (['A', 'B'] as $v) {
            $c = $stats[$v]['count'];
            $out[$v] = [
                'count' => $c,
                'avg_peak_mb' => $c > 0 ? round($stats[$v]['peak'] / $c / 1048576, 4) : 0.0,
                'avg_wall_ms' => $c > 0 ? round($stats[$v]['wall'] / $c, 3) : 0.0,
            ];
        }
        if ($out['A']['count'] < $minSamples || $out['B']['count'] < $minSamples) {
            return ['ok' => true, 'winner' => null, 'stats' => $out];
        }
        $scoreA = $out['A']['avg_peak_mb'] * 0.5 + $out['A']['avg_wall_ms'] / 1000 * 0.5;
        $scoreB = $out['B']['avg_peak_mb'] * 0.5 + $out['B']['avg_wall_ms'] / 1000 * 0.5;
        $winner = $scoreA <= $scoreB ? 'A' : 'B';

        return ['ok' => true, 'winner' => $winner, 'stats' => $out];
    }

    public static function promptSection(): string
    {
        $e = self::evaluate();
        if ($e['winner'] === null) {
            return "\n\nAB_PERFORMANCE: nog niet genoeg samples (min per variant) — blijf meten op admin-verkeer.";
        }
        $w = $e['winner'];
        $st = $e['stats'][$w];

        return "\n\nAB_PERFORMANCE (admin traffic): winnaar variant {$w} — avg peak {$st['avg_peak_mb']} MB, avg wall {$st['avg_wall_ms']} ms (combine score). Gebruik dit om performance-fixes te valideren.";
    }

    private static function trimMetrics(string $path): void
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= 5000) {
            return;
        }
        $keep = array_slice($lines, -4000);
        @file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
    }

    private static function cfg(): ?\App\Core\Config
    {
        try {
            $c = ($GLOBALS)['app_container'] ?? null;
            if (is_object($c) && method_exists($c, 'get')) {
                return $c->get('config');
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
