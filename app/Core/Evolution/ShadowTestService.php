<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Shadow Mode: tests high-severity proposals before live application.
 *
 * For DB index changes: runs EXPLAIN before/after on sample queries to measure improvement.
 * For PHP patches: runs lint + static analysis on the proposed code.
 * Results are presented to the admin with concrete metrics.
 */
final class ShadowTestService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Test a proposed SQL migration (e.g. CREATE INDEX) by running EXPLAIN on
     * relevant queries before and after applying the index on a shadow basis.
     *
     * @return array{ok: bool, before: array, after: array, improvement_pct: float, recommendation: string, error?: string}
     */
    public function testIndexMigration(string $tableName, string $indexSql, string $sampleQuery): array
    {
        try {
            $db = $this->container->get('db');
            if (!method_exists($db, 'getConnection')) {
                return ['ok' => false, 'before' => [], 'after' => [], 'improvement_pct' => 0, 'recommendation' => '', 'error' => 'No DB connection'];
            }
            $pdo = $db->getConnection();

            $before = $this->runExplainSafe($pdo, $sampleQuery);
            if ($before === null) {
                return ['ok' => false, 'before' => [], 'after' => [], 'improvement_pct' => 0, 'recommendation' => '', 'error' => 'EXPLAIN failed on sample query'];
            }

            $indexApplied = false;
            $indexName = 'shadow_test_' . bin2hex(random_bytes(4));
            try {
                $safeIndexSql = $this->rewriteIndexName($indexSql, $indexName);
                $pdo->exec($safeIndexSql);
                $indexApplied = true;
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'before' => $before,
                    'after' => [],
                    'improvement_pct' => 0,
                    'recommendation' => 'Index creation failed: ' . $e->getMessage(),
                    'error' => $e->getMessage(),
                ];
            }

            $after = $this->runExplainSafe($pdo, $sampleQuery) ?? [];

            if ($indexApplied) {
                try {
                    $pdo->exec("DROP INDEX `{$indexName}` ON `{$tableName}`");
                } catch (\Throwable) {
                }
            }

            $beforeRows = max(1, (int)($before['rows'] ?? 1));
            $afterRows = max(1, (int)($after['rows'] ?? $beforeRows));
            $improvement = round((1 - ($afterRows / $beforeRows)) * 100, 1);

            $recommendation = $this->buildRecommendation($before, $after, $improvement);

            EvolutionLogger::log('shadow_test', 'index_migration', [
                'table' => $tableName,
                'before_type' => $before['type'] ?? '',
                'after_type' => $after['type'] ?? '',
                'before_rows' => $beforeRows,
                'after_rows' => $afterRows,
                'improvement_pct' => $improvement,
            ]);

            return [
                'ok' => true,
                'before' => $before,
                'after' => $after,
                'improvement_pct' => $improvement,
                'recommendation' => $recommendation,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'before' => [], 'after' => [], 'improvement_pct' => 0, 'recommendation' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * Test a PHP patch by running lint + basic static checks.
     *
     * @return array{ok: bool, lint_passed: bool, issues: list<string>}
     */
    public function testPhpPatch(string $fqcn, string $phpSource): array
    {
        $tmp = sys_get_temp_dir() . '/shadow_test_' . bin2hex(random_bytes(8)) . '.php';
        @file_put_contents($tmp, $phpSource);

        $issues = [];

        $lint = @shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
        $lintPassed = $lint !== null && str_contains($lint, 'No syntax errors');
        if (!$lintPassed) {
            $issues[] = 'Lint failed: ' . trim((string)$lint);
        }

        if (preg_match('/\beval\s*\(/', $phpSource)) {
            $issues[] = 'Contains eval() — security risk';
        }
        if (preg_match('/\b(shell_exec|exec|system|passthru)\s*\(\s*\$/', $phpSource)) {
            $issues[] = 'Unescaped shell execution with variables';
        }

        $methodCount = preg_match_all('/\b(public|protected|private)\s+(?:static\s+)?function\s/', $phpSource);
        if ($methodCount > 25) {
            $issues[] = "Very high method count ({$methodCount}) — consider splitting";
        }

        @unlink($tmp);

        return [
            'ok' => $lintPassed && $issues === [],
            'lint_passed' => $lintPassed,
            'issues' => $issues,
        ];
    }

    /**
     * @return array{type: string, rows: int, key: string, extra: string}|null
     */
    private function runExplainSafe(\PDO $pdo, string $query): ?array
    {
        if (!preg_match('/^\s*SELECT\b/i', $query)) {
            return null;
        }
        try {
            $stmt = $pdo->query('EXPLAIN ' . $query);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return is_array($row) ? [
                'type' => (string)($row['type'] ?? ''),
                'rows' => (int)($row['rows'] ?? 0),
                'key' => (string)($row['key'] ?? ''),
                'extra' => (string)($row['Extra'] ?? ''),
            ] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function rewriteIndexName(string $sql, string $newName): string
    {
        return preg_replace(
            '/CREATE\s+INDEX\s+`?\w+`?/i',
            'CREATE INDEX `' . $newName . '`',
            $sql,
            1
        ) ?? $sql;
    }

    private function buildRecommendation(array $before, array $after, float $improvement): string
    {
        $beforeType = $before['type'] ?? 'unknown';
        $afterType = $after['type'] ?? 'unknown';

        if ($improvement >= 80) {
            return "Sterke verbetering: scan type {$beforeType} -> {$afterType}, {$improvement}% minder rijen. Aanbevolen om live te zetten.";
        }
        if ($improvement >= 30) {
            return "Goede verbetering: {$improvement}% minder rijen gescand. Overweeg live toepassing.";
        }
        if ($improvement > 0) {
            return "Marginale verbetering: {$improvement}%. Weeg de complexiteit van de migratie af.";
        }

        return "Geen meetbare verbetering. De index helpt mogelijk niet voor deze query-patronen.";
    }
}
