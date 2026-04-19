<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Hall of Consciousness: searchable JSONL of strategy plans, peer review, and rationale.
 */
final class IntentLogService
{
    private const LOG_FILE = 'storage/evolution/intent_log.jsonl';

    /**
     * @param array<string, mixed> $payload
     */
    public static function append(Config $config, string $kind, array $payload): void
    {
        $il = self::cfg($config);
        if ($il === null || !filter_var($il['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $path = BASE_PATH . '/' . self::LOG_FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $row = [
            'ts' => gmdate('c'),
            'kind' => $kind,
            'payload' => $payload,
        ];
        $line = json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        EvolutionWikiService::appendFromIntent($config, $row);

        $maxLines = max(1000, min(100000, (int)($il['max_lines'] ?? 5000)));
        self::trimIfNeeded($path, $maxLines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function searchRecent(Config $config, string $query, int $limit = 20): array
    {
        $il = self::cfg($config);
        if ($il === null || !filter_var($il['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $path = BASE_PATH . '/' . self::LOG_FILE;
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $q = strtolower(trim($query));
        $out = [];
        foreach (array_reverse($lines) as $line) {
            if ($q !== '' && stripos($line, $q) === false) {
                continue;
            }
            $j = @json_decode($line, true);
            if (is_array($j)) {
                $out[] = $j;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $il = is_array($evo) ? ($evo['intent_log'] ?? null) : null;

        return is_array($il) ? $il : null;
    }

    private static function trimIfNeeded(string $path, int $maxLines): void
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= $maxLines) {
            return;
        }
        $keep = array_slice($lines, -$maxLines);
        @file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
    }
}
