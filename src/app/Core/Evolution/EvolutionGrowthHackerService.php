<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Creative feature discovery: merges CRO drop-offs with optional search query logs to propose Evolution virtual pages / tools.
 */
final class EvolutionGrowthHackerService
{
    public const SEARCH_LOG = 'storage/evolution/search_queries.jsonl';

    public function __construct(private readonly Container $container)
    {
    }

    public function promptSection(): string
    {
        $cfg = $this->container->get('config');
        $g = $cfg->get('evolution.growth_hacker', []);
        if (!is_array($g) || !filter_var($g['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $queries = $this->aggregateSearchQueries();
        $cro = (new CroInsightService())->buildReport($cfg);

        $lines = [
            "\n\nGROWTH HACKER (data-driven feature ideas):",
            '  Search log: ' . self::SEARCH_LOG . ' (JSON lines: {"q":"term","n":1} or {"query":"..."}).',
        ];

        if ($queries !== []) {
            arsort($queries);
            $top = array_slice($queries, 0, 8, true);
            $lines[] = '  Top search terms (aggregated):';
            foreach ($top as $term => $n) {
                $lines[] = '    - ' . $term . ' (' . $n . ' hits)';
            }
        } else {
            $lines[] = '  No search aggregates yet — ship top queries from your search endpoint into search_queries.jsonl.';
        }

        $insights = $cro['insights'] ?? [];
        if (is_array($insights) && $insights !== []) {
            $lines[] = '  CRO high drop-off steps (hint new UX / tools):';
            foreach (array_slice($insights, 0, 5) as $in) {
                if (!is_array($in)) {
                    continue;
                }
                $lines[] = '    - step ' . ($in['step'] ?? '?') . ' drop-off ' . ($in['dropoff_rate_pct'] ?? '?') . '%';
            }
        }

        $lines[] = '  Action: if a popular search has no matching route, propose evolution_routing + EvolutionVirtualController + Twig tool page under /tools/ (shadow deploy first).';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, int> term => count
     */
    private function aggregateSearchQueries(): array
    {
        $path = BASE_PATH . '/' . self::SEARCH_LOG;
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $out = [];
        foreach (array_slice($lines, -5000) as $line) {
            $j = json_decode((string) $line, true);
            if (!is_array($j)) {
                continue;
            }
            $q = trim((string) ($j['q'] ?? $j['query'] ?? $j['term'] ?? ''));
            if ($q === '') {
                continue;
            }
            $n = max(1, (int) ($j['n'] ?? $j['count'] ?? 1));
            $key = mb_strtolower($q);
            $out[$key] = ($out[$key] ?? 0) + $n;
        }

        return $out;
    }
}
