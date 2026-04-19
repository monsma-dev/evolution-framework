<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Ancestral Swarm Intelligence — Master Vault
 *
 * Stores anonymous micro-lessons from framework users.
 * Each contribution is stripped of identifiers before storage.
 * The vault grows into the world's most intelligent PHP/Rust knowledge base.
 */
final class SwarmVaultService
{
    private const VAULT_DIR   = '/var/www/html/data/evolution/swarm_vault';
    private const INDEX_FILE  = '/var/www/html/data/evolution/swarm_vault/index.jsonl';
    private const STATS_FILE  = '/var/www/html/data/evolution/swarm_vault/stats.json';
    private const MAX_LESSON_BYTES = 8192;
    private const MAX_DAILY_PER_IP = 10;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Store an anonymous micro-lesson from a framework user.
     *
     * @param array{
     *   category: string,
     *   lesson: string,
     *   language?: string,
     *   framework_version?: string,
     *   tags?: list<string>
     * } $payload
     * @return array{ok: bool, id?: string, error?: string}
     */
    public function contribute(array $payload, string $ipHash = ''): array
    {
        if (!$this->ensureDir()) {
            return ['ok' => false, 'error' => 'Storage unavailable'];
        }

        $lesson   = trim((string)($payload['lesson'] ?? ''));
        $category = trim((string)($payload['category'] ?? 'general'));

        if ($lesson === '') {
            return ['ok' => false, 'error' => 'lesson is required'];
        }
        if (strlen($lesson) > self::MAX_LESSON_BYTES) {
            return ['ok' => false, 'error' => 'lesson must be < 8 KB'];
        }
        if (!$this->isAllowedCategory($category)) {
            return ['ok' => false, 'error' => 'Unknown category'];
        }
        if ($ipHash !== '' && $this->dailyCountForIp($ipHash) >= self::MAX_DAILY_PER_IP) {
            return ['ok' => false, 'error' => 'Daily contribution limit reached'];
        }

        $id = 'sl_' . bin2hex(random_bytes(10));
        $entry = [
            'id'                => $id,
            'category'          => $category,
            'lesson'            => $lesson,
            'language'          => substr(trim((string)($payload['language'] ?? 'php')), 0, 20),
            'framework_version' => substr(trim((string)($payload['framework_version'] ?? '')), 0, 20),
            'tags'              => $this->sanitizeTags((array)($payload['tags'] ?? [])),
            'quality_score'     => 0,      // set by vector similarity later
            'contributed_at'    => date('c'),
            'ip_hash'           => $ipHash, // for rate limiting only — not exposed
        ];

        // Write to daily shard file
        $shard = self::VAULT_DIR . '/shard_' . date('Y-m-d') . '.jsonl';
        file_put_contents($shard, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

        // Write to global index (without ip_hash)
        $indexEntry = $entry;
        unset($indexEntry['ip_hash']);
        file_put_contents(self::INDEX_FILE, json_encode($indexEntry) . "\n", FILE_APPEND | LOCK_EX);

        $this->updateStats($category);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Get vault statistics for the portal display.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        if (!is_file(self::STATS_FILE)) {
            return $this->computeStats();
        }

        $data = json_decode((string)file_get_contents(self::STATS_FILE), true);
        if (!is_array($data)) {
            return $this->computeStats();
        }

        // Refresh if older than 5 minutes
        if ((time() - (int)($data['computed_at'] ?? 0)) > 300) {
            return $this->computeStats();
        }

        return $data;
    }

    /**
     * Most recent lessons across all categories.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $n = 10): array
    {
        if (!is_file(self::INDEX_FILE)) {
            return [];
        }

        $lines = array_filter(explode("\n", (string)file_get_contents(self::INDEX_FILE)));
        $lines = array_slice(array_reverse(array_values($lines)), 0, $n);

        return array_values(array_filter(array_map(static function (string $l): ?array {
            $d = json_decode($l, true);
            return is_array($d) ? $d : null;
        }, $lines)));
    }

    /**
     * Search lessons by keyword (simple text match).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        if (!is_file(self::INDEX_FILE) || trim($query) === '') {
            return [];
        }

        $q     = mb_strtolower(trim($query));
        $lines = array_filter(explode("\n", (string)file_get_contents(self::INDEX_FILE)));
        $found = [];

        foreach (array_reverse(array_values($lines)) as $line) {
            $d = json_decode($line, true);
            if (!is_array($d)) {
                continue;
            }
            if (mb_stripos((string)($d['lesson'] ?? ''), $q) !== false
                || mb_stripos((string)($d['category'] ?? ''), $q) !== false) {
                $found[] = $d;
                if (count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function ensureDir(): bool
    {
        if (!is_dir(self::VAULT_DIR)) {
            return mkdir(self::VAULT_DIR, 0755, true);
        }
        return true;
    }

    private function isAllowedCategory(string $cat): bool
    {
        return in_array($cat, [
            'security', 'performance', 'architecture', 'testing',
            'database', 'api', 'caching', 'debugging', 'general',
            'php', 'rust', 'javascript', 'deployment', 'patterns',
        ], true);
    }

    /** @param list<mixed> $tags @return list<string> */
    private function sanitizeTags(array $tags): array
    {
        return array_values(array_slice(
            array_filter(
                array_map(static fn ($t) => is_string($t) ? substr(preg_replace('/[^a-z0-9\-]/', '', strtolower($t)), 0, 30) : null, $tags),
                static fn ($t) => $t !== null && $t !== ''
            ),
            0, 10
        ));
    }

    private function dailyCountForIp(string $ipHash): int
    {
        $shard = self::VAULT_DIR . '/shard_' . date('Y-m-d') . '.jsonl';
        if (!is_file($shard)) {
            return 0;
        }
        $count = 0;
        foreach (explode("\n", (string)file_get_contents($shard)) as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && ($d['ip_hash'] ?? '') === $ipHash) {
                $count++;
            }
        }
        return $count;
    }

    private function updateStats(string $category): void
    {
        $stats = $this->computeStats();
        file_put_contents(self::STATS_FILE, json_encode($stats), LOCK_EX);
    }

    /** @return array<string, mixed> */
    private function computeStats(): array
    {
        $total = 0;
        $categories = [];
        $todayCount = 0;
        $today = date('Y-m-d');

        if (is_file(self::INDEX_FILE)) {
            foreach (array_filter(explode("\n", (string)file_get_contents(self::INDEX_FILE))) as $line) {
                $d = json_decode($line, true);
                if (!is_array($d)) {
                    continue;
                }
                $total++;
                $cat = (string)($d['category'] ?? 'general');
                $categories[$cat] = ($categories[$cat] ?? 0) + 1;
                if (str_starts_with((string)($d['contributed_at'] ?? ''), $today)) {
                    $todayCount++;
                }
            }
        }

        arsort($categories);

        $stats = [
            'total_lessons'    => $total,
            'today_lessons'    => $todayCount,
            'top_categories'   => array_slice($categories, 0, 5, true),
            'shard_count'      => count(glob(self::VAULT_DIR . '/shard_*.jsonl') ?: []),
            'computed_at'      => time(),
        ];

        file_put_contents(self::STATS_FILE, json_encode($stats), LOCK_EX);

        return $stats;
    }
}
