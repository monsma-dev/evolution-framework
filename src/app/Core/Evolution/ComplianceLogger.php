<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use PDO;

/**
 * ComplianceLogger — EU AI Act Art. 50 + GDPR Art. 5 audit trail.
 *
 * Writes every Police-Agent decision to compliance_logs with:
 *   - agent_name        which agent took the action
 *   - action_taken      PII_ANONYMIZED | LOCAL_ROUTING_FORCED | TRANSPARENCY_ADDED |
 *                       PENDING_APPROVAL | PROMPT_BLOCKED | SIGNAL_DISCARDED
 *   - reason            human-readable explanation
 *   - pii_entities      JSON list of PII types found
 *   - compliance_hash   SHA-256(agent+action+reason+timestamp) for immutability proof
 *
 * Usage:
 *   ComplianceLogger::log($db, 'SignalDiscoveryService', 'PII_ANONYMIZED',
 *       'Email found in raw_content', ['email' => 2]);
 */
final class ComplianceLogger
{
    public const ACTION_PII_ANONYMIZED        = 'PII_ANONYMIZED';
    public const ACTION_LOCAL_ROUTING_FORCED  = 'LOCAL_ROUTING_FORCED';
    public const ACTION_TRANSPARENCY_ADDED    = 'TRANSPARENCY_ADDED';
    public const ACTION_PENDING_APPROVAL      = 'PENDING_APPROVAL';
    public const ACTION_PROMPT_BLOCKED        = 'PROMPT_BLOCKED';
    public const ACTION_SIGNAL_DISCARDED      = 'SIGNAL_DISCARDED';
    public const ACTION_STRATEGY_BLOCKED      = 'STRATEGY_BLOCKED';

    private static bool $tableChecked = false;

    /**
     * @param array<string, int|string> $piiEntities
     */
    public static function log(
        PDO    $db,
        string $agentName,
        string $actionTaken,
        string $reason,
        array  $piiEntities = []
    ): void {
        try {
            self::ensureTable($db);

            $ts   = date('Y-m-d H:i:s');
            $hash = hash('sha256', $agentName . $actionTaken . $reason . $ts);

            $stmt = $db->prepare(
                'INSERT INTO compliance_logs
                 (agent_name, action_taken, reason, pii_entities_found, compliance_hash, created_at)
                 VALUES (:agent, :action, :reason, :pii, :hash, :ts)'
            );
            $stmt->execute([
                ':agent'  => mb_substr($agentName, 0, 100),
                ':action' => mb_substr($actionTaken, 0, 80),
                ':reason' => mb_substr($reason, 0, 500),
                ':pii'    => $piiEntities !== [] ? json_encode($piiEntities, JSON_UNESCAPED_UNICODE) : null,
                ':hash'   => $hash,
                ':ts'     => $ts,
            ]);
        } catch (\Throwable $e) {
            EvolutionLogger::log('compliance', 'log_write_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Fetch last N compliance entries for the dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public static function getRecent(PDO $db, int $limit = 50): array
    {
        try {
            self::ensureTable($db);
            $stmt = $db->prepare(
                'SELECT * FROM compliance_logs ORDER BY created_at DESC LIMIT :lim'
            );
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{total: int, by_action: array<string, int>, pii_today: int, cloud_calls: int, local_calls: int}
     */
    public static function getSummary(PDO $db): array
    {
        try {
            self::ensureTable($db);
            $today = date('Y-m-d');

            $total = (int)(($db->query('SELECT COUNT(*) AS c FROM compliance_logs')?->fetch(PDO::FETCH_ASSOC) ?: [])['c'] ?? 0);

            $piiStmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM compliance_logs WHERE action_taken = 'PII_ANONYMIZED' AND DATE(created_at) = ?"
            );
            $piiStmt->execute([$today]);
            $piiToday = (int)(($piiStmt->fetch(PDO::FETCH_ASSOC) ?: [])['c'] ?? 0);

            $byActionStmt = $db->query(
                "SELECT action_taken, COUNT(*) AS cnt FROM compliance_logs GROUP BY action_taken ORDER BY cnt DESC"
            );
            $byAction = [];
            if ($byActionStmt) {
                foreach ($byActionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $byAction[(string)$row['action_taken']] = (int)$row['cnt'];
                }
            }

            $localCalls = (int)($byAction[self::ACTION_LOCAL_ROUTING_FORCED] ?? 0);
            $cloudCalls = $total - $localCalls;

            return [
                'total'       => $total,
                'by_action'   => $byAction,
                'pii_today'   => $piiToday,
                'cloud_calls' => max(0, $cloudCalls),
                'local_calls' => $localCalls,
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'by_action' => [], 'pii_today' => 0, 'cloud_calls' => 0, 'local_calls' => 0];
        }
    }

    private static function ensureTable(PDO $db): void
    {
        if (self::$tableChecked) {
            return;
        }
        $db->exec(
            'CREATE TABLE IF NOT EXISTS compliance_logs (
               id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               agent_name        VARCHAR(100)  NOT NULL,
               action_taken      VARCHAR(80)   NOT NULL,
               reason            VARCHAR(500)  NOT NULL DEFAULT \'\',
               pii_entities_found JSON,
               compliance_hash   CHAR(64)      NOT NULL,
               created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_action (action_taken),
               INDEX idx_created (created_at)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableChecked = true;
    }
}
