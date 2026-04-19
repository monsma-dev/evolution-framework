<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Evolution timeline + optional Code DNA history for dashboard storytelling.
 */
final class EvolutionHallOfFameService
{
    public const TIMELINE_FILE = 'storage/evolution/timeline.jsonl';
    public const DNA_HISTORY_FILE = 'storage/evolution/dna_monthly.json';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function recordMilestone(string $title, string $kind = 'milestone', array $meta = []): void
    {
        $cfg = $this->container->get('config');
        $h = $cfg->get('evolution.hall_of_fame', []);
        if (!is_array($h) || !filter_var($h['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return;
        }
        $path = BASE_PATH . '/' . self::TIMELINE_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = json_encode([
            'ts' => gmdate('c'),
            'title' => mb_substr($title, 0, 500),
            'kind' => $kind,
            'meta' => $meta,
        ], JSON_UNESCAPED_UNICODE);
        if (is_string($line)) {
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecentTimeline(int $limit = 20): array
    {
        $path = BASE_PATH . '/' . self::TIMELINE_FILE;
        if (!is_file($path)) {
            return [[
                'ts' => gmdate('c'),
                'title' => 'Evolution timeline started — milestones appear as the AI ships changes.',
                'kind' => 'system',
            ]];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach (array_slice($lines, -$limit) as $line) {
            $j = json_decode((string) $line, true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }
        if ($out === []) {
            return [[
                'ts' => gmdate('c'),
                'title' => 'Evolution timeline — milestones appear as AI ships changes.',
                'kind' => 'system',
            ]];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDnaMonthlyTrend(): array
    {
        $path = BASE_PATH . '/' . self::DNA_HISTORY_FILE;
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($j) ? $j : [];
    }

    /**
     * @param list<array{month: string, avg_score: float}> $rows
     */
    public static function saveDnaMonthly(array $rows): void
    {
        $path = BASE_PATH . '/' . self::DNA_HISTORY_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
