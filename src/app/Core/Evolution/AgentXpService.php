<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * AgentXpService — Evolution XP & Levelling System
 *
 * Elke agent (architect, hunter, designer) verdient XP bij succesvolle acties
 * en verliest XP bij sancties. Levels geven permissies vrij.
 *
 * XP-tabel (defaults, overschrijfbaar in evolution.json "xp.events"):
 *   lesson_completed   → +50 XP
 *   shadow_patch_ok    → +100 XP
 *   hunt_success       → +20 XP
 *   self_repair        → +150 XP
 *   academy_applied    → +75 XP  (GitHub-les succesvol toegepast)
 *   policy_violation   → -30 XP  (Police-Agent sanctie)
 *   syntax_error       → -15 XP
 *   rollback           → -50 XP
 *
 * Level thresholds (evolution.json "xp.levels"):
 *   1–10:   Junior Constructor  (blade views only)
 *   11–30:  Core Engineer       (models + controllers)
 *   31–50:  Sovereign Architect (shell + master bypass)
 */
final class AgentXpService
{
    private const DEFAULT_EVENTS = [
        'lesson_completed'  => 50,
        'shadow_patch_ok'   => 100,
        'hunt_success'      => 20,
        'self_repair'       => 150,
        'academy_applied'   => 75,
        'jit_lesson_loaded' => 25,
        'policy_violation'  => -30,
        'syntax_error'      => -15,
        'rollback'          => -50,
        'curiosity_trigger' => 10,
    ];

    private const DEFAULT_LEVELS = [
        ['min' => 0,    'max' => 500,   'level' => 1,  'rank' => 'Junior Constructor',  'tier' => 'junior'],
        ['min' => 501,  'max' => 1000,  'level' => 5,  'rank' => 'Constructor',          'tier' => 'junior'],
        ['min' => 1001, 'max' => 2000,  'level' => 10, 'rank' => 'Senior Constructor',   'tier' => 'junior'],
        ['min' => 2001, 'max' => 4000,  'level' => 15, 'rank' => 'Core Engineer',        'tier' => 'core'],
        ['min' => 4001, 'max' => 7000,  'level' => 20, 'rank' => 'Senior Engineer',      'tier' => 'core'],
        ['min' => 7001, 'max' => 11000, 'level' => 25, 'rank' => 'Lead Engineer',        'tier' => 'core'],
        ['min' => 11001,'max' => 16000, 'level' => 30, 'rank' => 'Principal Engineer',   'tier' => 'core'],
        ['min' => 16001,'max' => 24000, 'level' => 35, 'rank' => 'Architect',            'tier' => 'sovereign'],
        ['min' => 24001,'max' => 35000, 'level' => 40, 'rank' => 'Senior Architect',     'tier' => 'sovereign'],
        ['min' => 35001,'max' => 50000, 'level' => 45, 'rank' => 'Sovereign Architect',  'tier' => 'sovereign'],
        ['min' => 50001,'max' => PHP_INT_MAX, 'level' => 50, 'rank' => 'Master Architect', 'tier' => 'sovereign'],
    ];

    // ── Public API ──────────────────────────────────────────────────────────────

    /**
     * Geef XP aan een agent voor een actie.
     *
     * @param array<string, mixed> $config  evolution.json["xp"] (optioneel)
     * @param string               $context Optionele context voor het log
     */
    public static function award(
        \PDO   $db,
        string $agent,
        string $event,
        array  $config = [],
        string $context = '',
    ): int {
        $delta = self::resolveXpDelta($event, $config);
        if ($delta === 0) {
            return 0;
        }
        return self::applyXp($db, $agent, $event, $delta, $context);
    }

    /**
     * Trek XP af (Police-Agent sanctie).
     *
     * @param array<string, mixed> $config
     */
    public static function sanction(
        \PDO   $db,
        string $agent,
        string $event,
        int    $amount = 0,
        array  $config = [],
        string $context = '',
    ): int {
        $delta = $amount !== 0 ? -abs($amount) : self::resolveXpDelta($event, $config);
        return self::applyXp($db, $agent, $event, $delta, $context);
    }

    /**
     * @return array{xp_total: int, xp_level: int, rank: string, tier: string, next_level_xp: int, progress_pct: int}|null
     */
    public static function getStatus(\PDO $db, string $agent = 'architect'): ?array
    {
        try {
            $stmt = $db->prepare("SELECT xp_total, xp_level FROM agent_xp WHERE agent = :agent LIMIT 1");
            $stmt->execute([':agent' => $agent]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        $xp    = (int)$row['xp_total'];
        $level = self::calcLevel($xp);

        return [
            'xp_total'     => $xp,
            'xp_level'     => $level['level'],
            'rank'         => $level['rank'],
            'tier'         => $level['tier'],
            'next_level_xp'=> $level['next_level_xp'],
            'progress_pct' => $level['progress_pct'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getRecentLog(\PDO $db, string $agent = 'architect', int $limit = 20): array
    {
        try {
            $stmt = $db->prepare("
                SELECT event, xp_delta, xp_after, level_after, context, created_at
                FROM xp_log
                WHERE agent = :agent
                ORDER BY created_at DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':agent' => $agent]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Controleer of agent een bepaalde actie mag uitvoeren (permission gate).
     *
     * Tiers:
     *   junior    → blade/twig views only
     *   core      → models + controllers
     *   sovereign → shell + master bypass
     */
    public static function hasPermission(\PDO $db, string $agent, string $permission): bool
    {
        $status = self::getStatus($db, $agent);
        if ($status === null) {
            return false;
        }

        $tier = $status['tier'];

        $permMap = [
            'modify_views'       => ['junior', 'core', 'sovereign'],
            'modify_models'      => ['core', 'sovereign'],
            'modify_controllers' => ['core', 'sovereign'],
            'shell_access'       => ['sovereign'],
            'master_bypass'      => ['sovereign'],
            'auto_apply'         => ['core', 'sovereign'],
        ];

        $allowed = $permMap[$permission] ?? [];
        return in_array($tier, $allowed, true);
    }

    /**
     * Bouw een beknopte prompt-injectie met XP-status voor de Architect.
     */
    public static function promptStatusLine(\PDO $db, string $agent = 'architect'): string
    {
        $status = self::getStatus($db, $agent);
        if ($status === null) {
            return '';
        }

        return sprintf(
            "\n\nAGENT_STATUS: Level %d | Rank: %s | XP: %d | Volgende level: %d XP nodig | Progress: %d%%",
            $status['xp_level'],
            $status['rank'],
            $status['xp_total'],
            $status['next_level_xp'],
            $status['progress_pct'],
        );
    }

    // ── Private helpers ──────────────────────────────────────────────────────────

    private static function applyXp(\PDO $db, string $agent, string $event, int $delta, string $context): int
    {
        try {
            // Upsert XP
            $db->prepare("
                INSERT INTO agent_xp (agent, xp_total, xp_level)
                VALUES (:agent, GREATEST(0, :delta), 1)
                ON DUPLICATE KEY UPDATE
                    xp_total = GREATEST(0, CAST(xp_total AS SIGNED) + :delta2),
                    xp_level = xp_level
            ")->execute([':agent' => $agent, ':delta' => max(0, $delta), ':delta2' => $delta]);

            // Haal nieuw totaal op
            $stmt = $db->prepare("SELECT xp_total FROM agent_xp WHERE agent = :agent");
            $stmt->execute([':agent' => $agent]);
            $newTotal = (int)($stmt->fetchColumn() ?: 0);

            $level = self::calcLevel($newTotal);

            // Update level
            $db->prepare("UPDATE agent_xp SET xp_level = :lv WHERE agent = :agent")
               ->execute([':lv' => $level['level'], ':agent' => $agent]);

            // Update event counters
            self::updateEventCounter($db, $agent, $event, $delta);

            // Log
            $db->prepare("
                INSERT INTO xp_log (agent, event, xp_delta, xp_after, level_after, context)
                VALUES (:agent, :event, :delta, :after, :level, :ctx)
            ")->execute([
                ':agent' => $agent,
                ':event' => $event,
                ':delta' => $delta,
                ':after' => $newTotal,
                ':level' => $level['level'],
                ':ctx'   => mb_substr($context, 0, 500),
            ]);

            EvolutionLogger::log('xp', $event, [
                'agent' => $agent,
                'delta' => $delta,
                'total' => $newTotal,
                'level' => $level['level'],
            ]);

            return $newTotal;
        } catch (\Exception $e) {
            EvolutionLogger::log('xp', 'error', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private static function updateEventCounter(\PDO $db, string $agent, string $event, int $delta): void
    {
        $col = match (true) {
            str_contains($event, 'shadow_patch')  => 'shadow_patches_ok',
            str_contains($event, 'hunt')          => 'hunts_ok',
            str_contains($event, 'self_repair')   => 'self_repairs_ok',
            str_contains($event, 'lesson')        => 'lessons_completed',
            $delta < 0                            => 'sanctions_total',
            default                               => null,
        };
        if ($col === null) {
            return;
        }
        try {
            $increment = $delta < 0 ? abs($delta) : 1;
            $db->prepare("UPDATE agent_xp SET {$col} = {$col} + :inc WHERE agent = :agent")
               ->execute([':inc' => $increment, ':agent' => $agent]);
        } catch (\Exception $e) {}
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveXpDelta(string $event, array $config): int
    {
        $events = array_merge(self::DEFAULT_EVENTS, (array)($config['events'] ?? []));
        return (int)($events[$event] ?? 0);
    }

    /**
     * @return array{level: int, rank: string, tier: string, next_level_xp: int, progress_pct: int}
     */
    private static function calcLevel(int $xp): array
    {
        $levels  = self::DEFAULT_LEVELS;
        $current = $levels[0];
        $next    = $levels[1] ?? null;

        foreach ($levels as $i => $l) {
            if ($xp >= $l['min']) {
                $current = $l;
                $next    = $levels[$i + 1] ?? null;
            }
        }

        $nextXp      = $next ? $next['min'] : $current['max'];
        $rangeSize   = max(1, $nextXp - $current['min']);
        $progress    = (int)round(min(100, max(0, ($xp - $current['min']) / $rangeSize * 100)));

        return [
            'level'         => $current['level'],
            'rank'          => $current['rank'],
            'tier'          => $current['tier'],
            'next_level_xp' => $nextXp,
            'progress_pct'  => $progress,
        ];
    }
}
