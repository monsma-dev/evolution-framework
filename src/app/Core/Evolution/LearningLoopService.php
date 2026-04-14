<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Learning Loop: records auto-apply outcomes (success/rollback/policy violation)
 * and feeds relevant history back into the next AI prompt so the model avoids
 * repeating mistakes and reinforces successful patterns.
 */
final class LearningLoopService
{
    private const HISTORY_FILE = 'storage/evolution/learning_history.jsonl';
    private const MAX_HISTORY_LINES = 500;
    private const PROMPT_RECENT_COUNT = 10;

    /**
     * Record an auto-apply outcome.
     */
    public static function record(array $outcome): void
    {
        $entry = [
            'ts' => gmdate('c'),
            'target' => (string)($outcome['target'] ?? ''),
            'type' => (string)($outcome['type'] ?? 'php'),
            'severity' => (string)($outcome['severity'] ?? ''),
            'ok' => (bool)($outcome['ok'] ?? false),
            'error' => $outcome['error'] ?? null,
            'rolled_back' => (bool)($outcome['rolled_back'] ?? false),
            'rollback_reason' => (string)($outcome['rollback_reason'] ?? ''),
            'policy_violation' => (string)($outcome['policy_violation'] ?? ''),
            'visual_regression' => (bool)($outcome['visual_regression'] ?? false),
            'elegance_rating' => isset($outcome['elegance_rating']) ? (float) $outcome['elegance_rating'] : null,
            'master_score' => isset($outcome['master_score']) ? (float) $outcome['master_score'] : null,
            'master_verdict' => isset($outcome['master_verdict']) ? (string) $outcome['master_verdict'] : null,
        ];

        $path = self::filePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        self::trimIfNeeded($path);

        try {
            $ctr = ($GLOBALS)['app_container'] ?? null;
            if (is_object($ctr) && method_exists($ctr, 'get')) {
                $config = $ctr->get('config');
                if ($config instanceof Config) {
                    LearningVectorMemoryService::indexEntry($config, $entry);
                    PromptDNAEvolutionService::onLearningRecord($config, $entry);
                }
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Record a Guard Dog rollback for a previously applied patch.
     */
    public static function recordRollback(string $target, string $reason): void
    {
        self::record([
            'target' => $target,
            'type' => str_starts_with($target, 'twig:') ? 'twig' : 'php',
            'severity' => 'auto',
            'ok' => false,
            'rolled_back' => true,
            'rollback_reason' => $reason,
        ]);
        self::bumpRollbackCountForEvolutionIgnore($target);
    }

    /**
     * After repeated rollbacks for the same target, append to .evolution_ignore (Ghost / risky paths).
     */
    private static function bumpRollbackCountForEvolutionIgnore(string $target): void
    {
        $target = trim($target);
        if ($target === '' || !defined('BASE_PATH')) {
            return;
        }
        $path = BASE_PATH . '/storage/evolution/rollback_counts.json';
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $data = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        }
        if (!is_array($data)) {
            $data = [];
        }
        $data[$target] = (int) ($data[$target] ?? 0) + 1;
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($data[$target] >= 3) {
            $pattern = str_contains($target, '\\') ? str_replace('\\', '/', $target) : $target;
            EvolutionIgnoreRegistry::appendPattern($pattern);
        }
    }

    /**
     * Get relevant history for a specific target (file/fqcn).
     *
     * @return list<array<string, mixed>>
     */
    public static function historyForTarget(string $target, int $limit = 5): array
    {
        $all = self::readAll();
        $filtered = [];
        foreach (array_reverse($all) as $entry) {
            if (($entry['target'] ?? '') === $target) {
                $filtered[] = $entry;
                if (count($filtered) >= $limit) {
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * Aantal opeenvolgende geslaagde auto-apply records aan het eind van de history (geen rollback).
     */
    public static function successStreak(): int
    {
        $all = self::readAll();
        if ($all === []) {
            return 0;
        }
        $streak = 0;
        for ($i = count($all) - 1; $i >= 0; $i--) {
            $e = $all[$i];
            $ok = (bool) ($e['ok'] ?? false);
            $rolled = (bool) ($e['rolled_back'] ?? false);
            if (!$ok || $rolled) {
                break;
            }
            $eleg = $e['elegance_rating'] ?? $e['master_score'] ?? null;
            if ($eleg !== null && (float) $eleg < 7.0) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /**
     * Build a prompt section with recent learning history.
     * Focuses on failures and rollbacks so the AI avoids repeating mistakes.
     */
    public static function promptAppend(): string
    {
        $all = self::readAll();
        if ($all === []) {
            return '';
        }

        $recent = array_slice($all, -self::PROMPT_RECENT_COUNT);
        $failures = array_filter($recent, static fn(array $e) => !($e['ok'] ?? true) || ($e['rolled_back'] ?? false));

        if ($failures === []) {
            $successCount = count(array_filter($recent, static fn(array $e) => ($e['ok'] ?? false)));

            return "\n\nLEARNING_HISTORY: {$successCount} successful auto-applies recently. No recent failures.";
        }

        $lines = ["\n\nLEARNING_HISTORY (recent failures — avoid repeating these patterns):"];
        foreach ($failures as $f) {
            $target = $f['target'] ?? '';
            $reason = $f['rollback_reason'] ?? $f['error'] ?? $f['policy_violation'] ?? 'unknown';
            $type = $f['type'] ?? 'php';
            $lines[] = "  - [{$type}] {$target}: {$reason}";
            if ($f['visual_regression'] ?? false) {
                $lines[] = "    ^ Caused visual regression — use a more targeted CSS selector next time.";
            }
        }

        $successCount = count(array_filter($recent, static fn(array $e) => ($e['ok'] ?? false)));
        $lines[] = "Recent success rate: {$successCount}/" . count($recent);

        return implode("\n", $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readAll(): array
    {
        $path = self::filePath();
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $entries = [];
        foreach ($lines as $line) {
            $j = @json_decode($line, true);
            if (is_array($j)) {
                $entries[] = $j;
            }
        }

        return $entries;
    }

    private static function filePath(): string
    {
        return BASE_PATH . '/' . self::HISTORY_FILE;
    }

    private static function trimIfNeeded(string $path): void
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= self::MAX_HISTORY_LINES) {
            return;
        }
        $trimmed = array_slice($lines, -self::MAX_HISTORY_LINES);
        @file_put_contents($path, implode("\n", $trimmed) . "\n", LOCK_EX);
    }
}
