<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Hall of Reflection: the Master's private audit log (growth / recurring corrections).
 */
final class MasterReflectionService
{
    private const FILE = 'storage/evolution/master_reflection.jsonl';

    /**
     * @param array<string, mixed> $meta
     */
    public static function append(Config $config, string $line, array $meta = []): void
    {
        $tb = $config->get('evolution.master_toolbox', []);
        if (!is_array($tb) || !filter_var($tb['reflection_enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }
        if (!defined('BASE_PATH')) {
            return;
        }
        $path = BASE_PATH . '/' . self::FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $row = [
            'ts' => gmdate('c'),
            'text' => mb_substr(trim($line), 0, 4000),
            'meta' => $meta,
        ];
        @file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 20): array
    {
        if (!defined('BASE_PATH')) {
            return [];
        }
        $path = BASE_PATH . '/' . self::FILE;
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $out = [];
        foreach (array_slice($lines, -$limit) as $ln) {
            $j = json_decode((string) $ln, true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }

        return $out;
    }
}
