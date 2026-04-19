<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * MicroContextService — Reasoning-Distillation / Micro-Context Logging.
 *
 * Na elke AI-taakvoltooiing wordt een compacte "Summary-Snippet" (max 3 zinnen,
 * ≈ 60-80 tokens) opgeslagen in storage/evolution/micro_context/.
 *
 * Voordeel: Als de agent morgen weer aan dezelfde taak werkt, leest hij die 3
 * zinnen in plaats van 10.000 tokens aan oude logs. Bespaart 30-50% op tokens
 * bij langlopende projecten.
 *
 * Opslag: JSON-bestanden per context-key (hash van de laatste user-message).
 * Geen DB-migratie nodig; bestanden zijn gitignored via storage/.
 *
 * Gebruik:
 *   MicroContextService::save($contextKey, $replyText, $taskType);
 *   $snippet = MicroContextService::load($contextKey);
 */
final class MicroContextService
{
    private const STORAGE_DIR   = 'storage/evolution/micro_context';
    private const MAX_ENTRIES   = 500;   // rotate oldest when limit is hit
    private const MAX_SNIPPET   = 600;   // chars ≈ ~150 tokens max per entry
    private const SENTENCE_LIMIT = 3;

    // ─── Public API ──────────────────────────────────────────────────────────────

    /**
     * Extract and persist a 3-sentence summary from the completed AI reply.
     * No extra API call — summary is derived from the existing response.
     *
     * @param array<string, mixed> $messages  Conversation messages (to derive context key)
     */
    public static function save(
        string $replyText,
        string $taskType = 'core',
        array  $messages = []
    ): void {
        if (trim($replyText) === '') {
            return;
        }

        $key     = self::contextKey($taskType, $messages);
        $snippet = self::extractSentences($replyText, self::SENTENCE_LIMIT);
        if ($snippet === '') {
            return;
        }

        $dir  = self::storageDir();
        $path = $dir . '/' . $key . '.json';

        $entry = [
            'key'        => $key,
            'task_type'  => $taskType,
            'snippet'    => $snippet,
            'saved_at'   => gmdate('c'),
            'char_count' => strlen($snippet),
        ];

        @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        // Rotate oldest entries if we exceed MAX_ENTRIES
        self::rotateIfNeeded($dir);

        EvolutionLogger::log('micro_context', 'snippet_saved', [
            'key'       => $key,
            'task_type' => $taskType,
            'chars'     => strlen($snippet),
        ]);
    }

    /**
     * Load a previously stored micro-context snippet by key.
     * Returns empty string if not found.
     */
    public static function load(string $taskType, array $messages = []): string
    {
        $key  = self::contextKey($taskType, $messages);
        $path = self::storageDir() . '/' . $key . '.json';

        if (!is_file($path)) {
            return '';
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return '';
        }

        $data = json_decode($raw, true);
        return is_array($data) ? (string)($data['snippet'] ?? '') : '';
    }

    /**
     * Format a loaded snippet as a system-prompt injection block.
     * Returns empty string when no snippet is stored.
     */
    public static function promptBlock(string $taskType, array $messages = []): string
    {
        $snippet = self::load($taskType, $messages);
        if ($snippet === '') {
            return '';
        }

        return "\n\n--- Micro-Context (vorige sessie samenvatting) ---\n"
            . $snippet
            . "\n--- Einde Micro-Context ---\n";
    }

    /**
     * List the most recent N snippets (for admin inspection).
     * @return list<array{key: string, task_type: string, snippet: string, saved_at: string}>
     */
    public static function listRecent(int $limit = 20): array
    {
        $dir = self::storageDir();
        $files = glob($dir . '/*.json') ?: [];
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $result = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $raw = @file_get_contents($file);
            if (!is_string($raw)) {
                continue;
            }
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $result[] = $data;
            }
        }

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /**
     * Extract up to $count sentences from the reply text.
     * Truncates at MAX_SNIPPET chars as a hard safety cap.
     */
    private static function extractSentences(string $text, int $count): string
    {
        // Remove markdown code fences and reasoning tags
        $clean = preg_replace('/```[\s\S]*?```/', '', $text) ?? $text;
        $clean = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        if ($clean === '') {
            return '';
        }

        // Split on sentence-ending punctuation
        $sentences = preg_split('/(?<=[.!?])\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_slice($sentences, 0, $count);
        $result = implode(' ', $kept);

        return mb_substr($result, 0, self::MAX_SNIPPET);
    }

    /**
     * Deterministic key from task type + last user message content hash.
     */
    private static function contextKey(string $taskType, array $messages): string
    {
        $lastUserContent = '';
        foreach (array_reverse($messages) as $m) {
            if (is_array($m) && ($m['role'] ?? '') === 'user') {
                $lastUserContent = (string)($m['content'] ?? '');
                break;
            }
        }

        $raw = $taskType . '|' . mb_substr($lastUserContent, 0, 256);
        return substr(hash('sha256', $raw), 0, 24);
    }

    private static function storageDir(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $dir  = $base . '/' . self::STORAGE_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function rotateIfNeeded(string $dir): void
    {
        $files = glob($dir . '/*.json') ?: [];
        if (count($files) <= self::MAX_ENTRIES) {
            return;
        }
        usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
        $toDelete = array_slice($files, 0, count($files) - self::MAX_ENTRIES);
        foreach ($toDelete as $old) {
            @unlink($old);
        }
    }
}
