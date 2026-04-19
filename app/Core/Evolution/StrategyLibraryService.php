<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * "Thought Collector": caches approved think-step strategy_plan snippets for similar future tasks.
 */
final class StrategyLibraryService
{
    private const DEFAULT_PATH = 'storage/evolution/vault/strategy_library.json';

    public static function isEnabled(Config $config): bool
    {
        $cv = $config->get('evolution.context_vault', []);

        return is_array($cv) && filter_var($cv['enabled'] ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($cv['strategy_library_enabled'] ?? true, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, mixed> $strategyPlan
     */
    public static function recordApprovedPlan(Config $config, array $messages, array $strategyPlan): void
    {
        if (!self::isEnabled($config)) {
            return;
        }
        $hint = self::lastUserSnippet($messages);
        if ($hint === '') {
            return;
        }
        $steps = $strategyPlan['steps'] ?? [];
        if (!is_array($steps) || $steps === []) {
            return;
        }

        $path = BASE_PATH . '/' . self::libraryPath($config);
        self::ensureParent($path);

        $lib = self::readLibrary($path);
        $entry = [
            'ts' => gmdate('c'),
            'user_snippet' => mb_substr($hint, 0, 400),
            'steps_count' => count($steps),
            'plan_summary' => mb_substr(json_encode($strategyPlan, JSON_UNESCAPED_UNICODE), 0, 1200),
        ];
        array_unshift($lib['entries'], $entry);
        $max = self::maxEntries($config);
        $lib['entries'] = array_slice($lib['entries'], 0, $max);
        $lib['updated_at'] = gmdate('c');
        @file_put_contents(
            $path,
            json_encode($lib, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function promptFlashback(Config $config, array $messages): string
    {
        if (!self::isEnabled($config)) {
            return '';
        }
        $path = BASE_PATH . '/' . self::libraryPath($config);
        if (!is_file($path)) {
            return '';
        }
        $lib = self::readLibrary($path);
        $entries = $lib['entries'] ?? [];
        if (!is_array($entries) || $entries === []) {
            return '';
        }
        $q = mb_strtolower(self::lastUserSnippet($messages));
        if ($q === '') {
            return '';
        }
        $qtoks = self::tokens($q);
        if ($qtoks === []) {
            return '';
        }
        $scored = [];
        foreach ($entries as $e) {
            if (!is_array($e)) {
                continue;
            }
            $snip = mb_strtolower((string) ($e['user_snippet'] ?? ''));
            $score = 0;
            foreach ($qtoks as $t) {
                if (strlen($t) > 3 && str_contains($snip, $t)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'entry' => $e];
            }
        }
        usort($scored, static fn(array $a, array $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, 3);
        if ($top === []) {
            return '';
        }
        $lines = ["\n\nSTRATEGY_LIBRARY_FLASHBACK (reuse before inventing a brand-new plan):"];
        foreach ($top as $i => $row) {
            $e = $row['entry'];
            $lines[] = '  ' . ($i + 1) . '. score=' . $row['score'] . ' | steps=' . (int) ($e['steps_count'] ?? 0)
                . ' | ' . mb_substr((string) ($e['user_snippet'] ?? ''), 0, 160);
            $lines[] = '     summary: ' . mb_substr((string) ($e['plan_summary'] ?? ''), 0, 220);
        }

        return implode("\n", $lines);
    }

    private static function libraryPath(Config $config): string
    {
        $cv = $config->get('evolution.context_vault', []);
        if (is_array($cv) && isset($cv['strategy_library_path']) && is_string($cv['strategy_library_path']) && $cv['strategy_library_path'] !== '') {
            return ltrim($cv['strategy_library_path'], '/');
        }

        return self::DEFAULT_PATH;
    }

    private static function maxEntries(Config $config): int
    {
        $cv = $config->get('evolution.context_vault', []);
        if (is_array($cv) && isset($cv['strategy_library_max_entries'])) {
            return max(10, min(500, (int) $cv['strategy_library_max_entries']));
        }

        return 80;
    }

    /**
     * @return array{entries: list<array<string, mixed>>, updated_at?: string}
     */
    private static function readLibrary(string $path): array
    {
        if (!is_file($path)) {
            return ['entries' => []];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($j) && isset($j['entries']) && is_array($j['entries'])
            ? $j
            : ['entries' => []];
    }

    private static function ensureParent(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function lastUserSnippet(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return trim((string) ($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $q): array
    {
        $parts = preg_split('/[^\p{L}\p{N}_]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values(array_filter($parts, static fn(string $s) => mb_strlen($s) > 2)) : [];
    }
}
