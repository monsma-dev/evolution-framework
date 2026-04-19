<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Tenant Usage Tracker — Consumption Logger for Brain-as-a-Service.
 *
 * Stores per-licence resource consumption so the Economist can generate invoices.
 *
 * Storage layout:
 *   storage/evolution/tenant_usage/{YYYYMM}/{tenant_hash_prefix}.jsonl
 *   storage/evolution/tenant_usage/summary.json        ← aggregated for billing dashboard
 *
 * Each JSONL line:
 *   {"ts":"...","command":"evolve:neural","cpu_ms":1234,"tokens":800,"cost_usd":0.00016}
 *
 * Public API:
 *   TenantUsageTracker::record($tenantId, $command, $cpuMs, $tokens, $costUsd)
 *   TenantUsageTracker::monthlyCallCount($tenantId)       ← for quota checking in Gateway
 *   TenantUsageTracker::monthlyTokens($tenantId)
 *   TenantUsageTracker::tenantReport($tenantId, $month)   ← for billing
 *   TenantUsageTracker::billingSnapshot()                 ← all tenants this month
 *   TenantUsageTracker::topConsumers(10)
 */
final class TenantUsageTracker
{
    private const BASE_DIR   = '/var/www/html/data/evolution/tenant_usage';
    private const SUMMARY    = '/var/www/html/data/evolution/tenant_usage/summary.json';

    // ── Write ─────────────────────────────────────────────────────────────────

    public static function record(
        string $tenantId,
        string $command,
        float $cpuMs,
        int $tokensUsed,
        float $costUsd
    ): void {
        if ($tenantId === '' || $tenantId === 'bypass' || $tenantId === 'sovereign') {
            return;
        }

        $month   = date('Ym');
        $prefix  = substr($tenantId, 0, 16);
        $dir     = self::resolve(self::BASE_DIR) . '/' . $month;
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

        $line = json_encode([
            'ts'       => gmdate('c'),
            'command'  => $command,
            'cpu_ms'   => round($cpuMs, 1),
            'tokens'   => $tokensUsed,
            'cost_usd' => round($costUsd, 6),
        ], JSON_UNESCAPED_UNICODE) . "\n";

        @file_put_contents($dir . '/' . $prefix . '.jsonl', $line, FILE_APPEND | LOCK_EX);

        // Update rolling summary (lightweight, just totals)
        self::updateSummary($tenantId, $command, $cpuMs, $tokensUsed, $costUsd);
    }

    // ── Read — for Gateway quota checks ──────────────────────────────────────

    public static function monthlyCallCount(string $tenantId): int
    {
        return self::loadMonthlyLines($tenantId) ? count(self::loadMonthlyLines($tenantId)) : 0;
    }

    public static function monthlyTokens(string $tenantId): int
    {
        $lines = self::loadMonthlyLines($tenantId);
        return (int)array_sum(array_column($lines, 'tokens'));
    }

    public static function monthlyCost(string $tenantId): float
    {
        $lines = self::loadMonthlyLines($tenantId);
        return (float)array_sum(array_column($lines, 'cost_usd'));
    }

    // ── Reporting ─────────────────────────────────────────────────────────────

    /**
     * Full report for a tenant for a given month (YYYYMM, defaults to current).
     *
     * @return array{
     *   tenant_id: string,
     *   month: string,
     *   total_calls: int,
     *   total_tokens: int,
     *   total_cost_usd: float,
     *   by_command: array<string, array{calls: int, tokens: int, cost_usd: float}>,
     *   lines: array<int, array<string, mixed>>
     * }
     */
    public static function tenantReport(string $tenantId, string $month = ''): array
    {
        if ($month === '') { $month = date('Ym'); }
        $lines = self::loadMonthlyLines($tenantId, $month);

        $byCommand = [];
        foreach ($lines as $l) {
            $cmd = (string)($l['command'] ?? 'unknown');
            if (!isset($byCommand[$cmd])) {
                $byCommand[$cmd] = ['calls' => 0, 'tokens' => 0, 'cost_usd' => 0.0];
            }
            $byCommand[$cmd]['calls']++;
            $byCommand[$cmd]['tokens']   += (int)($l['tokens']   ?? 0);
            $byCommand[$cmd]['cost_usd'] += (float)($l['cost_usd'] ?? 0);
        }

        return [
            'tenant_id'      => $tenantId,
            'month'          => $month,
            'total_calls'    => count($lines),
            'total_tokens'   => (int)array_sum(array_column($lines, 'tokens')),
            'total_cost_usd' => round((float)array_sum(array_column($lines, 'cost_usd')), 4),
            'by_command'     => $byCommand,
            'lines'          => $lines,
        ];
    }

    /**
     * All tenants this month — for the billing dashboard.
     *
     * @return array<string, array{calls: int, tokens: int, cost_usd: float, last_seen: string}>
     */
    public static function billingSnapshot(): array
    {
        $path = self::resolve(self::SUMMARY);
        if (!is_readable($path)) { return []; }
        $d = json_decode((string)file_get_contents($path), true);
        if (!is_array($d)) { return []; }
        $month = date('Ym');
        return (array)($d['months'][$month] ?? []);
    }

    /**
     * @return array<int, array{tenant_id: string, calls: int, cost_usd: float}>
     */
    public static function topConsumers(int $limit = 10): array
    {
        $snapshot = self::billingSnapshot();
        uasort($snapshot, static fn(array $a, array $b) => (float)($b['cost_usd'] ?? 0) <=> (float)($a['cost_usd'] ?? 0));
        $result = [];
        foreach (array_slice($snapshot, 0, $limit, true) as $tenantId => $data) {
            $result[] = [
                'tenant_id' => $tenantId,
                'calls'     => (int)($data['calls'] ?? 0),
                'cost_usd'  => (float)($data['cost_usd'] ?? 0),
            ];
        }
        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadMonthlyLines(string $tenantId, string $month = ''): array
    {
        if ($month === '') { $month = date('Ym'); }
        $prefix = substr($tenantId, 0, 16);
        $path   = self::resolve(self::BASE_DIR) . '/' . $month . '/' . $prefix . '.jsonl';
        if (!is_readable($path)) { return []; }

        $lines = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) { $lines[] = $decoded; }
        }
        return $lines;
    }

    private static function updateSummary(
        string $tenantId,
        string $command,
        float $cpuMs,
        int $tokens,
        float $costUsd
    ): void {
        $path   = self::resolve(self::SUMMARY);
        $dir    = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

        $data  = is_readable($path) ? (json_decode((string)file_get_contents($path), true) ?? []) : [];
        if (!is_array($data)) { $data = []; }
        $month = date('Ym');
        $tid   = substr($tenantId, 0, 16);

        if (!isset($data['months'][$month][$tid])) {
            $data['months'][$month][$tid] = ['calls' => 0, 'tokens' => 0, 'cost_usd' => 0.0, 'last_seen' => ''];
        }

        $data['months'][$month][$tid]['calls']++;
        $data['months'][$month][$tid]['tokens']   += $tokens;
        $data['months'][$month][$tid]['cost_usd']  = round(
            (float)$data['months'][$month][$tid]['cost_usd'] + $costUsd, 6
        );
        $data['months'][$month][$tid]['last_seen'] = gmdate('c');

        // Keep only last 12 months in summary
        if (isset($data['months']) && count($data['months']) > 12) {
            $data['months'] = array_slice($data['months'], -12, null, true);
        }

        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function resolve(string $path): string
    {
        if (str_starts_with($path, '/var/www/html') && is_dir('/var/www/html')) {
            return $path;
        }
        $base = defined('BASE_PATH') ? BASE_PATH : '/var/www/html';
        return rtrim($base, '/') . '/' . ltrim(str_replace('/var/www/html/', '', $path), '/');
    }
}
