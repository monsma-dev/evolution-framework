<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use PDO;

/**
 * Persistence layer for market_signals.
 * Stored queries in src/queries/MarketSignal.json.
 */
final class MarketSignalModel
{
    private PDO $db;
    /** @var array<string, string> */
    private array $queries;

    public function __construct(PDO $db, Config $config)
    {
        $this->db = $db;
        $qFile = (defined('BASE_PATH') ? BASE_PATH : '') . '/queries/MarketSignal.json';
        $raw = is_file($qFile) ? @file_get_contents($qFile) : false;
        $this->queries = (is_string($raw) ? json_decode($raw, true) : null) ?? [];
    }

    /**
     * Insert a new signal. Returns the new row ID or 0 on failure.
     *
     * @param array<string, mixed>|null $meta
     */
    public function insert(
        string $source,
        string $niche,
        string $rawContent,
        float $intentScore,
        ?array $meta = null
    ): int {
        $sql = $this->queries['insert'] ?? null;
        if ($sql === null) {
            return 0;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':source'       => mb_substr($source, 0, 64),
            ':niche'        => mb_substr($niche, 0, 128),
            ':raw_content'  => $rawContent,
            ':intent_score' => max(0.0, min(1.0, $intentScore)),
            ':signal_meta'  => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Top signals by intent score.
     *
     * @return list<array<string, mixed>>
     */
    public function getTopByIntentScore(float $minScore = 0.5, int $limit = 50): array
    {
        $sql = $this->queries['getTopByIntentScore'] ?? null;
        if ($sql === null) {
            return [];
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':min_score' => $minScore, ':limit' => $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Aggregate niche stats over the last N hours.
     *
     * @return list<array{niche: string, signal_count: int, avg_score: float, max_score: float}>
     */
    public function getTopNiches(int $hours = 24, int $limit = 10): array
    {
        $sql = $this->queries['getTopNiches'] ?? null;
        if ($sql === null) {
            return [];
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':hours' => $hours, ':limit' => $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Recent signals for a specific niche.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecentByNiche(string $niche, int $limit = 20): array
    {
        $sql = $this->queries['getRecentByNiche'] ?? null;
        if ($sql === null) {
            return [];
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':niche' => $niche, ':limit' => $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markProcessed(int $id): void
    {
        $sql = $this->queries['markProcessed'] ?? null;
        if ($sql === null) {
            return;
        }
        $this->db->prepare($sql)->execute([':id' => $id]);
    }

    public function countUnprocessed(): int
    {
        $sql = $this->queries['countUnprocessed'] ?? null;
        if ($sql === null) {
            return 0;
        }
        $stmt = $this->db->query($sql);

        return (int)(($stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [])['cnt'] ?? 0);
    }

    public function deleteOlderThan(int $days = 30): int
    {
        $sql = $this->queries['deleteOlderThan'] ?? null;
        if ($sql === null) {
            return 0;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':days' => $days]);

        return $stmt->rowCount();
    }
}
