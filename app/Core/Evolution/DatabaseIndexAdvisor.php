<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Database Index Advisor: detects slow queries and missing indexes.
 *
 * Reads MySQL slow query log or runs EXPLAIN on known hot queries.
 * Proposes index migrations as high severity (DB schema changes need approval).
 */
final class DatabaseIndexAdvisor
{
    private const SLOW_QUERY_PATTERNS_FILE = 'storage/evolution/slow_queries.jsonl';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Collect slow query data from multiple sources.
     *
     * @return array{ok: bool, slow_queries: list<array{query: string, time_ms: float, table: string, suggestion: string}>, index_suggestions: list<string>}
     */
    public function analyze(): array
    {
        $queries = [];
        $suggestions = [];

        $fromLog = $this->readSlowQueryLog();
        $queries = array_merge($queries, $fromLog);

        $fromProcesslist = $this->checkProcesslist();
        $queries = array_merge($queries, $fromProcesslist);

        foreach ($queries as $q) {
            $table = $q['table'] ?? '';
            $query = $q['query'] ?? '';
            if ($table !== '') {
                $explain = $this->runExplain($query);
                if ($explain !== null && ($explain['type'] ?? '') === 'ALL') {
                    $suggestions[] = "Table `{$table}` has a full table scan. Consider adding an index on the WHERE/JOIN columns.";
                }
            }
        }

        $tableStats = $this->getTableStats();
        foreach ($tableStats as $stat) {
            if (($stat['rows'] ?? 0) > 10000 && ($stat['index_count'] ?? 0) <= 1) {
                $suggestions[] = "Table `{$stat['table']}` has {$stat['rows']} rows but only {$stat['index_count']} index(es). Review query patterns.";
            }
        }

        EvolutionLogger::log('db_index_advisor', 'analyzed', [
            'slow_queries' => count($queries),
            'suggestions' => count($suggestions),
        ]);

        return [
            'ok' => true,
            'slow_queries' => array_slice($queries, 0, 20),
            'index_suggestions' => $suggestions,
        ];
    }

    /**
     * Build a prompt section for Ghost Mode.
     */
    public function promptSection(): string
    {
        $result = $this->analyze();
        if ($result['slow_queries'] === [] && $result['index_suggestions'] === []) {
            return '';
        }

        $lines = ["\n\nDATABASE INDEX ADVISOR:"];

        if ($result['slow_queries'] !== []) {
            $lines[] = 'Slow queries detected:';
            foreach (array_slice($result['slow_queries'], 0, 5) as $q) {
                $time = round($q['time_ms'] ?? 0);
                $lines[] = "  - ({$time}ms) {$q['query']}";
            }
        }

        if ($result['index_suggestions'] !== []) {
            $lines[] = 'Index suggestions:';
            foreach ($result['index_suggestions'] as $s) {
                $lines[] = "  - {$s}";
            }
            $lines[] = "Propose DB index changes as severity 'high' (requires admin approval for schema migrations).";
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{query: string, time_ms: float, table: string}>
     */
    private function readSlowQueryLog(): array
    {
        $path = BASE_PATH . '/' . self::SLOW_QUERY_PATTERNS_FILE;
        if (!is_file($path)) {
            return [];
        }
        $entries = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice($lines, -100) as $line) {
            $j = @json_decode($line, true);
            if (is_array($j)) {
                $entries[] = [
                    'query' => (string)($j['query'] ?? ''),
                    'time_ms' => (float)($j['time_ms'] ?? $j['duration_ms'] ?? 0),
                    'table' => (string)($j['table'] ?? ''),
                ];
            }
        }

        return $entries;
    }

    /**
     * @return list<array{query: string, time_ms: float, table: string}>
     */
    private function checkProcesslist(): array
    {
        try {
            $db = $this->container->get('db');
            if (!method_exists($db, 'getConnection')) {
                return [];
            }
            $pdo = $db->getConnection();
            $stmt = $pdo->query("SELECT INFO as query_text, TIME as seconds FROM INFORMATION_SCHEMA.PROCESSLIST WHERE COMMAND != 'Sleep' AND TIME > 1 AND INFO IS NOT NULL LIMIT 5");
            $results = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $query = (string)($row['query_text'] ?? '');
                $table = $this->extractTableFromQuery($query);
                $results[] = [
                    'query' => substr($query, 0, 200),
                    'time_ms' => ((float)($row['seconds'] ?? 0)) * 1000,
                    'table' => $table,
                ];
            }

            return $results;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{type: string, rows: int, key: string}|null
     */
    private function runExplain(string $query): ?array
    {
        if ($query === '' || !preg_match('/^\s*SELECT\b/i', $query)) {
            return null;
        }
        try {
            $db = $this->container->get('db');
            if (!method_exists($db, 'getConnection')) {
                return null;
            }
            $pdo = $db->getConnection();
            $stmt = $pdo->query('EXPLAIN ' . $query);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }

            return [
                'type' => (string)($row['type'] ?? ''),
                'rows' => (int)($row['rows'] ?? 0),
                'key' => (string)($row['key'] ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{table: string, rows: int, index_count: int}>
     */
    private function getTableStats(): array
    {
        try {
            $db = $this->container->get('db');
            if (!method_exists($db, 'getConnection')) {
                return [];
            }
            $pdo = $db->getConnection();
            $stmt = $pdo->query(
                "SELECT t.TABLE_NAME as tbl, t.TABLE_ROWS as rows, " .
                "COUNT(DISTINCT s.INDEX_NAME) as idx_count " .
                "FROM INFORMATION_SCHEMA.TABLES t " .
                "LEFT JOIN INFORMATION_SCHEMA.STATISTICS s ON t.TABLE_NAME = s.TABLE_NAME AND t.TABLE_SCHEMA = s.TABLE_SCHEMA " .
                "WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' " .
                "GROUP BY t.TABLE_NAME, t.TABLE_ROWS " .
                "HAVING t.TABLE_ROWS > 1000 " .
                "ORDER BY t.TABLE_ROWS DESC LIMIT 20"
            );
            $results = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $results[] = [
                    'table' => (string)($row['tbl'] ?? ''),
                    'rows' => (int)($row['rows'] ?? 0),
                    'index_count' => (int)($row['idx_count'] ?? 0),
                ];
            }

            return $results;
        } catch (\Throwable) {
            return [];
        }
    }

    private function extractTableFromQuery(string $query): string
    {
        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $query, $m)) {
            return $m[1];
        }

        return '';
    }
}
