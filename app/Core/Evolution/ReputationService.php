<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Tenant Reputation Score — The Competitive Moat.
 *
 * Tenants who feed the system HIGH-QUALITY code (approved by the Super Jury,
 * resulting in merged patches, contributing to collective micro-lessons) earn
 * a Reputation Score. The more valuable their data is to the collective brain,
 * the more they are rewarded.
 *
 * ─── Score Components (0–1000 points) ───────────────────────────────────────
 *
 *  Component               Weight   Description
 *  ─────────────────────   ──────   ─────────────────────────────────────────
 *  jury_approvals          +10 pts  Super Jury approved a patch suggestion
 *  patches_applied         +25 pts  Applied patch improved the codebase
 *  lessons_contributed     +15 pts  Micro-lesson added to collective bank
 *  immunity_shared         +20 pts  Red-Team finding shared to swarm
 *  jury_rejections         -5  pts  Jury rejected a suggestion (noise penalty)
 *  hallucination_flag      -15 pts  AI hallucinated (model disagreement found)
 *
 *  Score decay: -2 pts/week of inactivity to encourage ongoing engagement.
 *
 * ─── Tiers and Benefits ──────────────────────────────────────────────────────
 *
 *  Score    Tier          Benefit
 *  ──────   ───────────   ──────────────────────────────────────────────────
 *  0–299    Learner       Standard pricing, standard quota
 *  300–599  Contributor   5% monthly discount, +10% quota
 *  600–799  Trusted       10% discount, priority swarm sync, +25% quota
 *  800–949  Expert        20% discount, early access to new Brains
 *  950+     Elite         30% discount, co-development partnership, ∞ swarm sync
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *   ReputationService::record($tenantId, 'jury_approvals');
 *   ReputationService::record($tenantId, 'patches_applied', 2);
 *   ReputationService::scorecard($tenantId)
 *   ReputationService::leaderboard(10)
 */
final class ReputationService
{
    private const STORE = '/var/www/html/data/evolution/reputation.json';

    // ── Event weights ─────────────────────────────────────────────────────────

    private const WEIGHTS = [
        'jury_approvals'       => +10,
        'patches_applied'      => +25,
        'lessons_contributed'  => +15,
        'immunity_shared'      => +20,
        'swarm_syncs'          => +5,
        'jury_rejections'      => -5,
        'hallucination_flag'   => -15,
    ];

    // ── Score → tier mapping ──────────────────────────────────────────────────

    private const TIERS = [
        950  => ['name' => 'Elite',       'discount' => 30, 'quota_bonus' => 100, 'perks' => ['early_brain_access', 'co_development', 'infinite_swarm']],
        800  => ['name' => 'Expert',      'discount' => 20, 'quota_bonus' => 50,  'perks' => ['early_brain_access', 'priority_swarm']],
        600  => ['name' => 'Trusted',     'discount' => 10, 'quota_bonus' => 25,  'perks' => ['priority_swarm']],
        300  => ['name' => 'Contributor', 'discount' => 5,  'quota_bonus' => 10,  'perks' => []],
        0    => ['name' => 'Learner',     'discount' => 0,  'quota_bonus' => 0,   'perks' => []],
    ];

    // ── Record an event ───────────────────────────────────────────────────────

    public static function record(string $tenantId, string $event, int $multiplier = 1): void
    {
        if ($tenantId === '' || $tenantId === 'bypass' || $tenantId === 'sovereign') { return; }

        $weight = self::WEIGHTS[$event] ?? 0;
        if ($weight === 0) { return; }

        $store = self::load();
        $tid   = substr($tenantId, 0, 16);

        if (!isset($store[$tid])) { $store[$tid] = self::defaultEntry($tid); }

        $store[$tid]['events'][$event]   = ($store[$tid]['events'][$event] ?? 0) + $multiplier;
        $store[$tid]['score']            = self::calculateScore($store[$tid]['events']);
        $store[$tid]['tier']             = self::resolveTier($store[$tid]['score'])['name'];
        $store[$tid]['last_activity_at'] = gmdate('c');
        $store[$tid]['updated_at']       = gmdate('c');

        self::save($store);

        EvolutionLogger::log('reputation', 'event_recorded', [
            'tenant' => $tid,
            'event'  => $event,
            'delta'  => $weight * $multiplier,
            'score'  => $store[$tid]['score'],
            'tier'   => $store[$tid]['tier'],
        ]);
    }

    // ── Read score for a tenant ───────────────────────────────────────────────

    public static function score(string $tenantId): int
    {
        $tid   = substr($tenantId, 0, 16);
        $store = self::load();
        return (int)($store[$tid]['score'] ?? 0);
    }

    /**
     * Full scorecard for a tenant.
     *
     * @return array{
     *   tenant_id: string,
     *   score: int,
     *   tier: string,
     *   discount_pct: int,
     *   quota_bonus_pct: int,
     *   perks: list<string>,
     *   events: array<string, int>,
     *   rank: int,
     *   last_activity_at: string
     * }
     */
    public static function scorecard(string $tenantId): array
    {
        $tid   = substr($tenantId, 0, 16);
        $store = self::load();
        $entry = $store[$tid] ?? self::defaultEntry($tid);

        $score    = (int)($entry['score'] ?? 0);
        $tierData = self::resolveTier($score);
        $rank     = self::computeRank($tid, $store);

        return [
            'tenant_id'       => $tid,
            'score'           => $score,
            'tier'            => $tierData['name'],
            'discount_pct'    => (int)$tierData['discount'],
            'quota_bonus_pct' => (int)$tierData['quota_bonus'],
            'perks'           => (array)($tierData['perks'] ?? []),
            'events'          => (array)($entry['events'] ?? []),
            'rank'            => $rank,
            'last_activity_at'=> (string)($entry['last_activity_at'] ?? ''),
        ];
    }

    /**
     * Global leaderboard.
     *
     * @return list<array{rank: int, tenant_id: string, score: int, tier: string, discount_pct: int}>
     */
    public static function leaderboard(int $limit = 10): array
    {
        $store = self::load();
        uasort($store, static fn(array $a, array $b) => (int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0));

        $result = [];
        $rank   = 1;
        foreach (array_slice($store, 0, $limit, true) as $tid => $entry) {
            $score    = (int)($entry['score'] ?? 0);
            $tierData = self::resolveTier($score);
            $result[] = [
                'rank'         => $rank++,
                'tenant_id'    => $tid,
                'score'        => $score,
                'tier'         => $tierData['name'],
                'discount_pct' => (int)$tierData['discount'],
            ];
        }
        return $result;
    }

    /**
     * Apply weekly score decay for inactive tenants (-2 pts per week inactive).
     */
    public static function applyDecay(): int
    {
        $store   = self::load();
        $decayed = 0;
        $cutoff  = time() - (7 * 86400);

        foreach ($store as $tid => &$entry) {
            $lastActivity = strtotime((string)($entry['last_activity_at'] ?? '')) ?: 0;
            if ($lastActivity < $cutoff && ($entry['score'] ?? 0) > 0) {
                $entry['score'] = max(0, (int)($entry['score'] ?? 0) - 2);
                $entry['tier']  = self::resolveTier((int)$entry['score'])['name'];
                $decayed++;
            }
        }
        unset($entry);

        if ($decayed > 0) { self::save($store); }
        return $decayed;
    }

    // ── Score calculation ─────────────────────────────────────────────────────

    private static function calculateScore(array $events): int
    {
        $score = 0;
        foreach ($events as $event => $count) {
            $score += ($count * (self::WEIGHTS[$event] ?? 0));
        }
        return max(0, min(1000, $score));
    }

    /** @return array{name: string, discount: int, quota_bonus: int, perks: list<string>} */
    private static function resolveTier(int $score): array
    {
        foreach (self::TIERS as $threshold => $data) {
            if ($score >= $threshold) { return $data; }
        }
        return self::TIERS[0];
    }

    private static function computeRank(string $tid, array $store): int
    {
        uasort($store, static fn(array $a, array $b) => (int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0));
        $rank = 1;
        foreach ($store as $id => $_) {
            if ($id === $tid) { return $rank; }
            $rank++;
        }
        return $rank;
    }

    /** @return array<string, mixed> */
    private static function defaultEntry(string $tid): array
    {
        return [
            'tid'              => $tid,
            'score'            => 0,
            'tier'             => 'Learner',
            'events'           => [],
            'created_at'       => gmdate('c'),
            'updated_at'       => gmdate('c'),
            'last_activity_at' => gmdate('c'),
        ];
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private static function load(): array
    {
        $path = self::resolve(self::STORE);
        if (!is_readable($path)) { return []; }
        return (array)(json_decode((string)file_get_contents($path), true) ?? []);
    }

    /** @param array<string, array<string, mixed>> $data */
    private static function save(array $data): void
    {
        $path = self::resolve(self::STORE);
        $dir  = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function resolve(string $path): string
    {
        if (str_starts_with($path, '/var/www/html') && is_dir('/var/www/html')) { return $path; }
        $base = defined('BASE_PATH') ? BASE_PATH : '/var/www/html';
        return rtrim($base, '/') . '/' . ltrim(str_replace('/var/www/html/', '', $path), '/');
    }
}
