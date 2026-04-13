<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * SRE advisory: zero-downtime DB major upgrades (e.g. MySQL 8.0 → 8.4) via shadow/staging — does not run cloud APIs.
 * Produces checklist + suggested human-run CLI snippets for Terraform / aws rds / mysqldump flows.
 */
final class EvolutionZeroDowntimeDbMigratorService
{
    public function __construct(private readonly Container $container)
    {
    }

    public function promptSection(): string
    {
        $cfg = $this->container->get('config');
        $m = $cfg->get('evolution.db_zero_downtime_migrator', []);
        if (!is_array($m) || !filter_var($m['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $target = trim((string) ($m['target_mysql_version'] ?? '8.4'));
        $lines = [
            "\n\nDB ZERO-DOWNTIME MIGRATOR (advisory — manual approval):",
            '  Target reference version: MySQL ' . $target . ' (adjust in evolution.db_zero_downtime_migrator.target_mysql_version).',
            '  Steps:',
            '    1. Clone: restore latest RDS snapshot OR Docker mysql:' . $target . ' with a copy of schema + anonymized data.',
            '    2. Run: phpunit + ChaosEngineeringService scenarios against the upgraded instance (connection string via env override).',
            '    3. Scan: grep/sql-lint for deprecated SQL (GROUP BY selects, utf8 vs utf8mb4, implicit sorts).',
            '    4. Cutover: blue/green or read-replica promotion — generate commands only; do not auto-execute.',
            '  Suggested CLI templates (review before run):',
            '    - aws rds describe-db-instances --query ...',
            '    - terraform plan (module rds engine_version bump)',
            '    - mysql_upgrade or logical dump/restore for major jumps.',
        ];

        return implode("\n", $lines);
    }
}
