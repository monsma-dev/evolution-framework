<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Hierarchical DNA data for treemap / heatmap dashboards (folder → class, score → color).
 */
final class DnaTreemapBuilderService
{
    /**
     * Nested tree for treemap visualization. Leaves: fqcn, score (1–10), value (LOC).
     *
     * @return array<string, mixed>
     */
    public function buildTree(Config $config): array
    {
        $dna = new CodeDnaScoringService();
        $all = $dna->scoreAll($config);
        if (!($all['ok'] ?? false)) {
            return ['name' => 'App', 'score' => null, 'children' => []];
        }

        $scores = $all['scores'] ?? [];
        if (!is_array($scores)) {
            return ['name' => 'App', 'score' => null, 'children' => []];
        }

        $tree = ['name' => 'App', 'children' => []];

        foreach ($scores as $fqcn => $row) {
            if (!is_string($fqcn) || !str_starts_with($fqcn, 'App\\')) {
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $parts = array_values(array_filter(explode('\\', substr($fqcn, 4)), static fn ($p) => $p !== ''));
            if ($parts === []) {
                continue;
            }

            $metrics = $row['metrics'] ?? [];
            $loc = 100;
            if (is_array($metrics) && isset($metrics['lines'])) {
                $loc = max(1, (int)$metrics['lines']);
            }

            $node = &$tree;
            foreach ($parts as $i => $part) {
                if (!isset($node['children']) || !is_array($node['children'])) {
                    $node['children'] = [];
                }
                $found = null;
                foreach ($node['children'] as $j => $child) {
                    if (is_array($child) && ($child['name'] ?? '') === $part) {
                        $found = $j;
                        break;
                    }
                }
                if ($found === null) {
                    $node['children'][] = ['name' => $part, 'children' => []];
                    $found = count($node['children']) - 1;
                }
                $node = &$node['children'][$found];
            }

            $node['fqcn'] = $fqcn;
            $node['score'] = (int)($row['score'] ?? 0);
            $node['value'] = $loc;
            unset($node['children']);
        }

        self::rollupScores($tree);

        return $tree;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function rollupScores(array &$node): ?int
    {
        if (!isset($node['children']) || !is_array($node['children']) || $node['children'] === []) {
            return isset($node['score']) ? (int)$node['score'] : null;
        }
        $sum = 0;
        $n = 0;
        foreach ($node['children'] as &$ch) {
            if (!is_array($ch)) {
                continue;
            }
            $s = self::rollupScores($ch);
            if ($s !== null) {
                $sum += $s;
                $n++;
            }
        }
        unset($ch);
        if ($n > 0) {
            $node['score'] = (int)round($sum / $n);
        }

        return isset($node['score']) ? (int)$node['score'] : null;
    }

    /**
     * CSS-friendly color from DNA score (1=red, 10=green).
     */
    public static function scoreToHsl(int $score): string
    {
        $score = max(1, min(10, $score));
        $h = (int)round(120 * (($score - 1) / 9));

        return "hsl({$h}, 65%, 42%)";
    }
}
