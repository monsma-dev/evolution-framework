<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Consensus Memory — shared failure + insight log across ALL AI agents.
 *
 * Every agent (Junior, Claude, local Llama, DeepSeek) reads this BEFORE
 * starting a task. Every simulation violation, Jury rejection, and CoVe
 * critique gets written HERE so the next agent doesn't repeat the mistake.
 *
 * This is the "Global Consciousness" of the framework:
 *   - Local AI learns from Claude's rejections
 *   - DeepSeek learns from yesterday's SQL injection finding
 *   - Claude's thought traces become training examples
 *
 * Storage: storage/evolution/consensus_memory.json (append-only array)
 * Max entries: 500 (FIFO eviction of oldest on overflow)
 */
final class EvolutionConsensusMemory
{
    private const MEMORY_FILE  = '/var/www/html/storage/evolution/consensus_memory.json';
    private const MAX_ENTRIES  = 500;
    private const MAX_CONTEXT_ENTRIES = 10;  // Entries injected into prompt

    // ── Entry types ──────────────────────────────────────────────────────────
    public const TYPE_SIMULATION_FAIL = 'simulation_fail';  // Simulation Room found a vulnerability
    public const TYPE_JURY_REJECTION  = 'jury_rejection';   // Jury rejected skill/code
    public const TYPE_COVE_CRITIQUE   = 'cove_critique';    // CoVe critique found issues
    public const TYPE_SKILL_PROMOTED  = 'skill_promoted';   // Skill approved and added to KR
    public const TYPE_THOUGHT_TRACE   = 'thought_trace';    // Claude's reasoning chain

    // ── Write ────────────────────────────────────────────────────────────────

    /**
     * Record a failure or insight into shared memory.
     *
     * @param string               $type    One of the TYPE_* constants
     * @param string               $agent   e.g. "junior", "claude", "jury", "simulation"
     * @param string               $task    Short description of the task being attempted
     * @param string               $finding What went wrong (violation, rejection reason, etc.)
     * @param array<string, mixed> $context Additional data (skill_id, file, evidence[], etc.)
     */
    public static function record(
        string $type,
        string $agent,
        string $task,
        string $finding,
        array $context = []
    ): void {
        $entry = [
            'id'        => self::generateId(),
            'timestamp' => gmdate('c'),
            'type'      => $type,
            'agent'     => $agent,
            'task'      => mb_substr($task, 0, 200),
            'finding'   => mb_substr($finding, 0, 500),
            'context'   => $context,
        ];

        $memory = self::load();
        array_unshift($memory, $entry);  // newest first

        // FIFO eviction: keep only the most recent MAX_ENTRIES
        if (count($memory) > self::MAX_ENTRIES) {
            $memory = array_slice($memory, 0, self::MAX_ENTRIES);
        }

        self::save($memory);
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * Retrieve relevant memory entries for a given task/query.
     * Returns up to MAX_CONTEXT_ENTRIES most-relevant entries as a prompt context string.
     *
     * @param  string   $task    Current task description — used for relevance scoring
     * @param  string[] $types   Filter by entry types; empty = all types
     * @param  int      $limit   Max entries to return
     */
    public static function retrieve(string $task = '', array $types = [], int $limit = 0): string
    {
        $limit   = $limit > 0 ? $limit : self::MAX_CONTEXT_ENTRIES;
        $memory  = self::load();

        if (empty($memory)) {
            return '';
        }

        // Filter by type
        if (!empty($types)) {
            $memory = array_values(array_filter($memory, static fn(array $e) => in_array($e['type'] ?? '', $types, true)));
        }

        // Score relevance if task given
        if ($task !== '') {
            $taskLower = strtolower($task);
            usort($memory, static function (array $a, array $b) use ($taskLower): int {
                return self::relevanceScore($b, $taskLower) <=> self::relevanceScore($a, $taskLower);
            });
        }

        $entries = array_slice($memory, 0, $limit);

        if (empty($entries)) {
            return '';
        }

        $lines = ["\n--- CONSENSUS MEMORY (past failures & insights — read before you act) ---"];
        foreach ($entries as $e) {
            $ts    = date('Y-m-d H:i', strtotime((string)($e['timestamp'] ?? '')));
            $type  = strtoupper((string)($e['type']    ?? 'unknown'));
            $agent = (string)($e['agent']   ?? '?');
            $task  = (string)($e['task']    ?? '');
            $find  = (string)($e['finding'] ?? '');
            $ctx   = (array)($e['context']  ?? []);

            $lines[] = "[{$ts}][{$type}][{$agent}] Task: {$task}";
            $lines[] = "  Finding: {$find}";

            if (!empty($ctx['skill_id'])) {
                $lines[] = "  Skill: {$ctx['skill_id']}";
            }
            if (!empty($ctx['attack_vector'])) {
                $lines[] = "  Vector: {$ctx['attack_vector']}";
            }
            if (!empty($ctx['evidence']) && is_array($ctx['evidence'])) {
                $lines[] = "  Evidence: " . implode(' | ', array_slice((array)$ctx['evidence'], 0, 2));
            }
        }
        $lines[] = "--- END CONSENSUS MEMORY ---\n";

        return implode("\n", $lines);
    }

    /**
     * Return recent entries as a raw array (for JSON serialization / reports).
     *
     * @param  int      $limit
     * @param  string[] $types
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 20, array $types = []): array
    {
        $memory = self::load();
        if (!empty($types)) {
            $memory = array_values(array_filter($memory, static fn(array $e) => in_array($e['type'] ?? '', $types, true)));
        }
        return array_slice($memory, 0, $limit);
    }

    /**
     * Count total entries.
     */
    public static function count(): int
    {
        return count(self::load());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $entry */
    private static function relevanceScore(array $entry, string $taskLower): int
    {
        $score = 0;
        $text  = strtolower(($entry['task'] ?? '') . ' ' . ($entry['finding'] ?? '') . ' ' . json_encode($entry['context'] ?? []));

        // Higher score for FAIL/REJECTION types
        $type = (string)($entry['type'] ?? '');
        if (in_array($type, [self::TYPE_SIMULATION_FAIL, self::TYPE_JURY_REJECTION], true)) {
            $score += 3;
        }

        // Word overlap between task and entry
        $words = preg_split('/\W+/', $taskLower) ?: [];
        foreach ($words as $word) {
            if (strlen($word) >= 4 && str_contains($text, $word)) {
                $score += 2;
            }
        }

        // Recency bonus (newer = slightly higher priority for ties)
        $ts = strtotime((string)($entry['timestamp'] ?? '')) ?: 0;
        if ($ts > time() - 86400) { $score += 1; }  // last 24h

        return $score;
    }

    private static function generateId(): string
    {
        return substr(md5(uniqid('cm_', true)), 0, 12);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function load(): array
    {
        $path = self::resolvePath();
        if (!is_readable($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int, array<string, mixed>> $memory
     */
    private static function save(array $memory): void
    {
        $path = self::resolvePath();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($memory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function resolvePath(): string
    {
        if (str_starts_with(self::MEMORY_FILE, '/var/www/html') && is_dir('/var/www/html')) {
            return self::MEMORY_FILE;
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 5);
        return rtrim($base, '/') . '/storage/evolution/consensus_memory.json';
    }
}
