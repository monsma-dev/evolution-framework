<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * API Contract Watchdog: monitors external API health and detects schema drift.
 *
 * Reads structured logs for external API calls (Stripe, SendGrid, Mollie, etc.),
 * analyzes response times, error rates, and detects new/removed JSON fields
 * compared to the last known schema snapshot.
 */
final class ApiContractWatchdog
{
    private const SCHEMA_DIR = 'storage/evolution/api_schemas';
    private const EXTERNAL_API_PATTERNS = [
        'stripe' => '/api\.stripe\.com/i',
        'mollie' => '/api\.mollie\.(nl|com)/i',
        'sendgrid' => '/api\.sendgrid\.com/i',
        'tavily' => '/api\.tavily\.com/i',
        'openai' => '/api\.openai\.com/i',
    ];

    /**
     * @return array{ok: bool, apis: list<array{name: string, status: string, avg_latency_ms: float, error_rate_pct: float, schema_drift: list<string>}>, warnings: list<string>, mock_hints: list<string>}
     */
    public function analyze(?Config $config = null): array
    {
        $logEntries = $this->loadExternalApiLogs();
        $apis = [];
        $warnings = [];
        $mockHints = [];

        $grouped = [];
        foreach ($logEntries as $entry) {
            $service = $this->detectService($entry);
            if ($service === null) {
                continue;
            }
            $grouped[$service][] = $entry;
        }

        foreach ($grouped as $name => $entries) {
            $latencies = [];
            $errors = 0;
            $total = count($entries);
            $responseFields = [];

            foreach ($entries as $e) {
                $ms = (float)($e['duration_ms'] ?? $e['latency_ms'] ?? 0);
                if ($ms > 0) {
                    $latencies[] = $ms;
                }
                $status = (int)($e['http_status'] ?? $e['status'] ?? 200);
                if ($status >= 400) {
                    $errors++;
                }
                $fields = $e['response_fields'] ?? null;
                if (is_array($fields)) {
                    foreach ($fields as $f) {
                        $responseFields[(string)$f] = ($responseFields[(string)$f] ?? 0) + 1;
                    }
                }
            }

            $avgLatency = $latencies !== [] ? round(array_sum($latencies) / count($latencies), 1) : 0;
            $errorRate = $total > 0 ? round(($errors / $total) * 100, 1) : 0;

            $schemaDrift = $this->detectSchemaDrift($name, $responseFields, $total);

            $status = 'healthy';
            if ($errorRate > 10) {
                $status = 'degraded';
                $warnings[] = "{$name}: error rate {$errorRate}% — check integration.";
            }
            if ($avgLatency > 2000) {
                $status = 'slow';
                $warnings[] = "{$name}: avg latency {$avgLatency}ms — consider timeout/retry tuning.";
            }
            if ($schemaDrift !== []) {
                $warnings[] = "{$name}: schema drift detected — " . implode(', ', array_slice($schemaDrift, 0, 3));
            }

            $apis[] = [
                'name' => $name,
                'status' => $status,
                'total_calls' => $total,
                'avg_latency_ms' => $avgLatency,
                'error_rate_pct' => $errorRate,
                'schema_drift' => $schemaDrift,
            ];

            if ($config !== null) {
                $sm = $config->get('evolution.semantic_api_mock', []);
                $auto = is_array($sm) && filter_var($sm['auto_from_watchdog'] ?? true, FILTER_VALIDATE_BOOL)
                    && filter_var($sm['enabled'] ?? true, FILTER_VALIDATE_BOOL);
                if ($auto && ($status === 'slow' || $status === 'degraded')) {
                    SemanticApiMockRegistry::markDegraded($name, true, [
                        'reason' => 'watchdog',
                        'status' => $status,
                        'avg_latency_ms' => $avgLatency,
                        'error_rate_pct' => $errorRate,
                    ]);
                    $mockHints[] = "{$name}: semantic mock flag set — use SemanticApiMockRegistry::getMockBody('{$name}') for non-critical paths.";
                }
            }
        }

        return ['ok' => true, 'apis' => $apis, 'warnings' => $warnings, 'mock_hints' => $mockHints];
    }

    /**
     * Build prompt section for Ghost Mode.
     */
    public function promptSection(?Config $config = null): string
    {
        $result = $this->analyze($config);
        if ($result['apis'] === [] && $result['warnings'] === []) {
            return '';
        }

        $lines = ["\n\nAPI CONTRACT WATCHDOG:"];
        foreach ($result['apis'] as $api) {
            $drift = $api['schema_drift'] !== [] ? ' | DRIFT: ' . implode(', ', array_slice($api['schema_drift'], 0, 2)) : '';
            $lines[] = "  - [{$api['status']}] {$api['name']}: {$api['total_calls']} calls, {$api['avg_latency_ms']}ms avg, {$api['error_rate_pct']}% errors{$drift}";
        }
        if ($result['warnings'] !== []) {
            $lines[] = 'Warnings:';
            foreach ($result['warnings'] as $w) {
                $lines[] = "  * {$w}";
            }
            $lines[] = "Propose integration updates as low_autofix if the fix is in our code, or medium if it requires API version changes.";
        }
        $hints = $result['mock_hints'] ?? [];
        if (is_array($hints) && $hints !== []) {
            $lines[] = 'SEMANTIC MOCK (non-critical fallbacks):';
            foreach ($hints as $h) {
                $lines[] = '  * ' . $h;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadExternalApiLogs(): array
    {
        $entries = [];

        $logFile = BASE_PATH . '/data/logs/structured.jsonl';
        if (is_file($logFile)) {
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_slice($lines, -500) as $line) {
                $j = @json_decode($line, true);
                if (is_array($j) && isset($j['url'])) {
                    $entries[] = $j;
                }
            }
        }

        $evoLog = EvolutionLogger::logPath();
        if (is_file($evoLog)) {
            $lines = @file($evoLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_slice($lines, -200) as $line) {
                $j = @json_decode($line, true);
                if (is_array($j) && isset($j['context']['url'])) {
                    $entries[] = array_merge($j['context'], ['channel' => $j['channel'] ?? '']);
                }
            }
        }

        return $entries;
    }

    private function detectService(array $entry): ?string
    {
        $url = (string)($entry['url'] ?? $entry['endpoint'] ?? '');
        if ($url === '') {
            return null;
        }
        foreach (self::EXTERNAL_API_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $url)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $currentFields field => occurrence count
     * @return list<string>
     */
    private function detectSchemaDrift(string $service, array $currentFields, int $totalCalls): array
    {
        if ($currentFields === [] || $totalCalls < 5) {
            return [];
        }

        $schemaFile = BASE_PATH . '/' . self::SCHEMA_DIR . '/' . $service . '.json';
        $dir = dirname($schemaFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (!is_file($schemaFile)) {
            @file_put_contents($schemaFile, json_encode(['fields' => array_keys($currentFields), 'updated' => gmdate('c')], JSON_PRETTY_PRINT));

            return [];
        }

        $stored = @json_decode((string)@file_get_contents($schemaFile), true);
        $knownFields = is_array($stored) ? ($stored['fields'] ?? []) : [];

        $drift = [];
        $newFields = array_diff(array_keys($currentFields), $knownFields);
        foreach ($newFields as $f) {
            $drift[] = "new field: {$f}";
        }
        $removedFields = array_diff($knownFields, array_keys($currentFields));
        foreach ($removedFields as $f) {
            $drift[] = "missing field: {$f}";
        }

        if ($newFields !== []) {
            @file_put_contents($schemaFile, json_encode([
                'fields' => array_unique(array_merge($knownFields, array_keys($currentFields))),
                'updated' => gmdate('c'),
                'drift_history' => array_merge($stored['drift_history'] ?? [], [['ts' => gmdate('c'), 'new' => $newFields, 'removed' => array_values($removedFields)]]),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $drift;
    }
}
