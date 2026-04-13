<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Proposes schema-level optimizations (denormalization, etc.) from slow-query DNA.
 * Destructive DDL always requires human approval + Shadow Deploy on a DB copy — never auto-run here.
 */
final class EvolutionSchemaOptimizerService
{
    private const SLOW_FILE = 'storage/evolution/slow_queries.jsonl';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return list<array{suggestion: string, severity: string, evidence: string}>
     */
    public function analyze(): array
    {
        $cfg = $this->container->get('config');
        $so = $cfg->get('evolution.schema_optimizer', []);
        if (!is_array($so) || !filter_var($so['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $out = [];
        $path = BASE_PATH . '/' . self::SLOW_FILE;
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $joinHeavy = 0;
        $aggregateHeavy = 0;
        foreach (array_slice($lines, -500) as $line) {
            $j = @json_decode((string) $line, true);
            if (!is_array($j)) {
                continue;
            }
            $q = strtolower((string) ($j['query'] ?? ''));
            if (str_contains($q, ' join ')) {
                $joinHeavy++;
            }
            if (str_contains($q, 'count(') || str_contains($q, 'sum(')) {
                $aggregateHeavy++;
            }
        }

        if ($joinHeavy >= 20) {
            $out[] = [
                'suggestion' => 'Frequent JOIN-heavy queries logged — consider denormalized counters (e.g. total_orders on users) updated by triggers or queue workers, but only after profiling on a shadow DB copy.',
                'severity' => 'high',
                'evidence' => 'slow_queries.jsonl join count ~' . $joinHeavy . ' in sample',
            ];
        }
        if ($aggregateHeavy >= 15) {
            $out[] = [
                'suggestion' => 'Repeated aggregates in slow log — consider materialized summary tables or cached rollups (Redis/DB) with explicit invalidation.',
                'severity' => 'medium',
                'evidence' => 'aggregate patterns in slow_queries sample',
            ];
        }

        return $out;
    }

    public function promptSection(): string
    {
        $rows = $this->analyze();
        if ($rows === []) {
            return '';
        }
        $lines = ["\n\nSCHEMA OPTIMIZER (suggestions only — apply DDL on shadow/staging only):"];
        foreach ($rows as $r) {
            $lines[] = '  - [' . $r['severity'] . '] ' . $r['suggestion'];
            $lines[] = '    Evidence: ' . $r['evidence'];
        }

        return implode("\n", $lines);
    }
}
