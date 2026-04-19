<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use OpenAI;
use Throwable;

/**
 * Semantic recall over learning_history using embeddings (OpenAI text-embedding-3-small).
 * Falls back to empty when disabled or API missing.
 */
final class LearningVectorMemoryService
{
    private const STORE = 'storage/evolution/learning_vectors.jsonl';
    private const MAX_LINES = 220;
    private const TOP_K = 4;

    /**
     * Index one learning entry (call from LearningLoopService::record).
     *
     * @param array<string, mixed> $entry
     */
    public static function indexEntry(Config $config, array $entry): void
    {
        $lv = $config->get('evolution.learning_vectors', []);
        if (!is_array($lv) || !filter_var($lv['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return;
        }
        $text = self::entryToText($entry);
        if ($text === '') {
            return;
        }
        $vec = self::embed($config, $text);
        if ($vec === null) {
            return;
        }
        $row = [
            'ts' => gmdate('c'),
            'text' => $text,
            'embedding' => $vec,
            'target' => (string) ($entry['target'] ?? ''),
            'ok' => (bool) ($entry['ok'] ?? false),
        ];
        $path = BASE_PATH . '/' . self::STORE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        self::trimStore($path);
    }

    /**
     * Prompt block: similar past failures to current user message.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function promptAppend(Config $config, array $messages): string
    {
        $lv = $config->get('evolution.learning_vectors', []);
        if (!is_array($lv) || !filter_var($lv['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $q = self::lastUserMessage($messages);
        if ($q === '') {
            return '';
        }
        $qVec = self::embed($config, $q);
        if ($qVec === null) {
            return self::fallbackTextPrompt($config);
        }
        $rows = self::loadVectors();
        if ($rows === []) {
            return '';
        }
        $scored = [];
        foreach ($rows as $r) {
            $e = $r['embedding'] ?? null;
            if (!is_array($e) || $e === []) {
                continue;
            }
            $sim = self::cosineSimilarity($qVec, array_map('floatval', $e));
            $scored[] = ['sim' => $sim, 'text' => (string) ($r['text'] ?? ''), 'target' => (string) ($r['target'] ?? '')];
        }
        usort($scored, static fn(array $a, array $b) => $b['sim'] <=> $a['sim']);
        $topK = self::topKFromConfig($config);
        $minSim = self::minSimilarityFromConfig($config);
        $top = array_slice($scored, 0, $topK);
        $top = array_filter($top, static fn(array $x) => $x['sim'] > $minSim);
        if ($top === []) {
            return '';
        }
        $lines = ["\n\nVECTOR_MEMORY (top {$topK} semantisch vergelijkbare eerdere auto-apply events — vermijd dezelfde fout):"];
        foreach ($top as $t) {
            $lines[] = '  - (' . round($t['sim'], 2) . ') ' . $t['target'] . ': ' . mb_substr($t['text'], 0, 280);
        }

        return implode("\n", $lines);
    }

    private static function fallbackTextPrompt(Config $config): string
    {
        return '';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function entryToText(array $entry): string
    {
        $parts = [
            (string) ($entry['target'] ?? ''),
            (string) ($entry['type'] ?? ''),
            (string) ($entry['severity'] ?? ''),
            ($entry['ok'] ?? false) ? 'ok' : 'fail',
            (string) ($entry['error'] ?? $entry['rollback_reason'] ?? $entry['policy_violation'] ?? ''),
        ];

        return trim(implode(' | ', array_filter($parts, static fn(string $s) => $s !== '')));
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function lastUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return trim((string) ($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function loadVectors(): array
    {
        $path = BASE_PATH . '/' . self::STORE;
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $j = @json_decode($line, true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }

        return $out;
    }

    private static function topKFromConfig(Config $config): int
    {
        $cv = $config->get('evolution.context_vault', []);
        if (is_array($cv) && filter_var($cv['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $k = (int) ($cv['vector_recall_top_k'] ?? 3);

            return max(1, min(12, $k));
        }

        return self::TOP_K;
    }

    private static function minSimilarityFromConfig(Config $config): float
    {
        $cv = $config->get('evolution.context_vault', []);
        if (is_array($cv) && filter_var($cv['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $m = (float) ($cv['vector_min_similarity'] ?? 0.42);

            return max(0.1, min(0.95, $m));
        }

        return 0.42;
    }

    private static function trimStore(string $path): void
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= self::MAX_LINES) {
            return;
        }
        $keep = array_slice($lines, -self::MAX_LINES);
        @file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
    }

    /**
     * @return list<float>|null
     */
    private static function embed(Config $config, string $text): ?array
    {
        $key = EvolutionProviderKeys::openAi($config, true);
        if ($key === '' || strlen($text) > 8000) {
            return null;
        }
        try {
            $client = OpenAI::factory()->withApiKey($key)->make();
            $resp = $client->embeddings()->create([
                'model' => 'text-embedding-3-small',
                'input' => $text,
            ]);
            $first = $resp->embeddings[0] ?? null;
            $data = $first !== null ? $first->embedding : null;
            if (!is_array($data)) {
                return null;
            }

            return array_map('floatval', $data);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        $den = sqrt($na) * sqrt($nb);

        return $den > 1e-12 ? $dot / $den : 0.0;
    }
}
