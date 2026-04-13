<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Model Router — dynamic model selection based on live benchmark scores.
 *
 * Instead of hardcoding "always use Claude for security", the router reads
 * the latest benchmark scores and returns whichever model is performing best
 * for a given domain today.
 *
 * Usage:
 *   $model = EvolutionModelRouter::bestFor('sql');      // → e.g. 'claude-3-5-sonnet'
 *   $model = EvolutionModelRouter::bestFor('twig');     // → e.g. 'gpt-4o-mini'
 *   $info  = EvolutionModelRouter::scores();            // full score table
 *
 * Scores file: storage/evolution/model_scores.json
 * Updated by:  php ai_bridge.php evolve:benchmark run
 */
final class EvolutionModelRouter
{
    private const SCORES_FILE = '/var/www/html/storage/evolution/model_scores.json';

    // Fallback routing when no benchmark data exists
    private const FALLBACK = [
        'sql'          => 'claude',
        'security'     => 'claude',
        'twig'         => 'junior',
        'php'          => 'junior',
        'architecture' => 'claude',
        'performance'  => 'junior',
        'logging'      => 'junior',
        'cache'        => 'junior',
        'di'           => 'claude',
        'default'      => 'claude',
    ];

    /**
     * Return the alias of the best-performing model for a domain.
     * Aliases: 'claude', 'junior', 'deepseek' — resolved to real models by LlmClient.
     *
     * @param  string $domain  e.g. 'sql', 'security', 'twig', 'php'
     */
    public static function bestFor(string $domain): string
    {
        $scores = self::scores();
        if (empty($scores)) {
            return self::FALLBACK[$domain] ?? self::FALLBACK['default'];
        }

        $best      = '';
        $bestScore = -1;

        foreach ($scores as $modelAlias => $domainScores) {
            if (!is_array($domainScores)) { continue; }
            $score = (float)($domainScores[$domain] ?? $domainScores['overall'] ?? -1);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = (string)$modelAlias;
            }
        }

        return $best !== '' ? $best : (self::FALLBACK[$domain] ?? self::FALLBACK['default']);
    }

    /**
     * Return the full score table.
     *
     * @return array<string, array<string, float>>  modelAlias → domain → score (0–100)
     */
    public static function scores(): array
    {
        $path = self::resolvePath();
        if (!is_readable($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? (array)($data['scores'] ?? []) : [];
    }

    /**
     * Return a human-readable leaderboard string for prompt injection.
     */
    public static function leaderboard(): string
    {
        $scores = self::scores();
        if (empty($scores)) {
            return '';
        }
        $lines = ["\n--- MODEL LEADERBOARD (current benchmark scores) ---"];
        foreach ($scores as $alias => $domains) {
            if (!is_array($domains)) { continue; }
            $overall = round((float)($domains['overall'] ?? array_sum($domains) / max(1, count($domains))), 1);
            $top = array_filter($domains, static fn(string $k) => $k !== 'overall', ARRAY_FILTER_USE_KEY);
            arsort($top);
            $topDomain = array_key_first($top) ?? '?';
            $lines[] = "  [{$alias}] overall={$overall}% best_domain={$topDomain}";
        }
        $lines[] = "--- END LEADERBOARD ---\n";
        return implode("\n", $lines);
    }

    /**
     * Write updated scores (called by EvolutionBenchmarkCommand).
     *
     * @param array<string, array<string, float>> $scores
     */
    public static function writeScores(array $scores, array $meta = []): void
    {
        $path = self::resolvePath();
        $dir  = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        file_put_contents($path, json_encode([
            'updated_at' => gmdate('c'),
            'meta'       => $meta,
            'scores'     => $scores,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function resolvePath(): string
    {
        if (str_starts_with(self::SCORES_FILE, '/var/www/html') && is_dir('/var/www/html')) {
            return self::SCORES_FILE;
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 5);
        return rtrim($base, '/') . '/storage/evolution/model_scores.json';
    }
}
