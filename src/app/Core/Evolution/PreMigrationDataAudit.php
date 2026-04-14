<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use PDO;

/**
 * Shadow-DB guard: sample existing rows before applying NOT NULL / UNIQUE-heavy migrations.
 */
final class PreMigrationDataAudit
{
    /**
     * @return array{ok: bool, violations?: list<string>, sampled_rows?: int, error?: string}
     */
    public static function auditNotNullFeasibility(
        Config $config,
        PDO $pdo,
        string $table,
        string $columnToAdd,
        bool $willBeNotNull
    ): array {
        $g = self::cfg($config);
        if ($g === null || !filter_var($g['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true];
        }
        if (!$willBeNotNull) {
            return ['ok' => true];
        }

        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        $columnToAdd = preg_replace('/[^a-zA-Z0-9_]/', '', $columnToAdd) ?? '';
        if ($table === '' || $columnToAdd === '') {
            return ['ok' => false, 'error' => 'invalid table or column'];
        }

        $limit = max(100, min(5000, (int)($g['sample_limit'] ?? 1000)));

        try {
            $st = $pdo->query('SELECT COUNT(*) AS c FROM `' . $table . '`');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            $total = is_array($row) ? (int)($row['c'] ?? 0) : 0;
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'table' => $table,
            'new_column' => $columnToAdd,
            'note' => 'NOT NULL nieuwe kolom op bestaande tabel: zorg voor DEFAULT of backfill; huidige rijen: ' . $total,
            'sampled_rows' => min($limit, $total),
        ];
    }

    /**
     * Check duplicate values for a column if a UNIQUE index would be added.
     *
     * @return array{ok: bool, duplicate_groups?: int, error?: string}
     */
    public static function auditUniqueFeasibility(
        Config $config,
        PDO $pdo,
        string $table,
        string $existingColumn
    ): array {
        $g = self::cfg($config);
        if ($g === null || !filter_var($g['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true];
        }

        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        $existingColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $existingColumn) ?? '';
        if ($table === '' || $existingColumn === '') {
            return ['ok' => false, 'error' => 'invalid table or column'];
        }

        try {
            $sql = 'SELECT `' . $existingColumn . '`, COUNT(*) c FROM `' . $table . '` GROUP BY `' . $existingColumn . '` HAVING c > 1 LIMIT 50';
            $st = $pdo->query($sql);
            $dup = 0;
            if ($st) {
                while ($st->fetch()) {
                    $dup++;
                }
            }

            return [
                'ok' => $dup === 0,
                'duplicate_groups' => $dup,
                'error' => $dup > 0 ? "UNIQUE op `{$existingColumn}` zou falen: {$dup} duplicate-groepen (sample)." : null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $g = is_array($evo) ? ($evo['pre_migration_audit'] ?? null) : null;

        return is_array($g) ? $g : null;
    }
}
