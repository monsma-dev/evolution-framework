<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * JSONL-log van autonome code-aanpassingen (Architect / auto-apply / shadow patches).
 * Pad: storage/evolution/code_agent_changes.jsonl
 */
final class AgentCodeChangeLogger
{
    private const LOG_FILE = 'storage/evolution/code_agent_changes.jsonl';

    /**
     * @param array{kind: string, file?: string, fqcn?: string, line_start?: int, line_end?: int, agent?: string, note?: string, severity?: string} $row
     */
    public static function append(array $row): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        $path = BASE_PATH . '/' . self::LOG_FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $line = json_encode([
            'ts' => gmdate('c'),
            'kind' => $row['kind'] ?? 'unknown',
            'file' => $row['file'] ?? null,
            'fqcn' => $row['fqcn'] ?? null,
            'line_start' => (int)($row['line_start'] ?? 0),
            'line_end' => (int)($row['line_end'] ?? 0),
            'agent' => $row['agent'] ?? 'Architect',
            'note' => $row['note'] ?? '',
            'severity' => $row['severity'] ?? null,
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function todayEntries(?string $basePath = null): array
    {
        $root = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : '');
        if ($root === '') {
            return [];
        }
        $path = $root . '/' . self::LOG_FILE;
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $today = gmdate('Y-m-d');
        $out = [];
        foreach (array_reverse($lines) as $ln) {
            $j = json_decode($ln, true);
            if (!is_array($j)) {
                continue;
            }
            $ts = (string)($j['ts'] ?? '');
            if ($ts !== '' && str_starts_with($ts, $today)) {
                $out[] = $j;
            }
            if (count($out) >= 200) {
                break;
            }
        }

        return $out;
    }
}
