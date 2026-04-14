<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Genetic A/B: scores variants from DesignAb clicks + optional cro_events.jsonl (experiment_id + variant),
 * breeds next generation (crossover of top-2 into population_size variants).
 */
final class GeneticAbEvolutionService
{
    /**
     * @return array{ok: bool, experiment_id: string, generation: int, variants: list<array{name: string, css_snippet: string}>, summary: string, error?: string}
     */
    public function proposeNextGeneration(Config $config, string $experimentId, bool $force = false): array
    {
        $experimentId = trim($experimentId);
        $ga = $config->get('evolution.genetic_ab', []);
        if (!is_array($ga) || (!filter_var($ga['enabled'] ?? false, FILTER_VALIDATE_BOOL) && !$force)) {
            return ['ok' => false, 'experiment_id' => $experimentId, 'generation' => 0, 'variants' => [], 'summary' => '', 'error' => 'genetic_ab disabled (use --force)'];
        }

        $pop = max(3, min(12, (int) ($ga['population_size'] ?? 5)));
        $ab = new DesignAbService();
        $list = $ab->listExperiments()['experiments'] ?? [];
        $ex = null;
        foreach ($list as $e) {
            if (is_array($e) && ($e['id'] ?? '') === $experimentId) {
                $ex = $e;
                break;
            }
        }
        if (!is_array($ex)) {
            return ['ok' => false, 'experiment_id' => $experimentId, 'generation' => 0, 'variants' => [], 'summary' => '', 'error' => 'experiment not found'];
        }

        $variants = $ex['variants'] ?? [];
        if (!is_array($variants) || count($variants) < 2) {
            return ['ok' => false, 'experiment_id' => $experimentId, 'generation' => 0, 'variants' => [], 'summary' => '', 'error' => 'need at least 2 variants'];
        }

        $scored = [];
        foreach ($variants as $v) {
            if (!is_array($v)) {
                continue;
            }
            $name = trim((string) ($v['name'] ?? ''));
            $css = (string) ($v['css_snippet'] ?? '');
            $clicks = (int) ($v['clicks'] ?? 0);
            $cro = $this->croBonus($experimentId, $name);
            $scored[] = ['name' => $name, 'css_snippet' => $css, 'score' => $clicks + $cro];
        }
        usort($scored, static fn(array $a, array $b) => $b['score'] <=> $a['score']);
        $topA = $scored[0];
        $topB = $scored[1];

        $gen = $this->nextGenerationNumber($experimentId);
        $next = [];
        $next[] = [
            'name' => 'g' . $gen . '_elite_a',
            'css_snippet' => $topA['css_snippet'] . "\n/* genetic: elite A */\n",
        ];
        $next[] = [
            'name' => 'g' . $gen . '_elite_b',
            'css_snippet' => $topB['css_snippet'] . "\n/* genetic: elite B */\n",
        ];

        $linesA = preg_split('/\R/', $topA['css_snippet']) ?: [];
        $linesB = preg_split('/\R/', $topB['css_snippet']) ?: [];
        for ($i = count($next); $i < $pop; $i++) {
            $merged = self::crossoverCss($linesA, $linesB, $i);
            $next[] = [
                'name' => 'g' . $gen . '_x' . $i,
                'css_snippet' => $merged,
            ];
        }

        $summary = sprintf(
            'Genetic gen %d for %s: crossover of %s (score %d) + %s (score %d) → %d variants.',
            $gen,
            $experimentId,
            $topA['name'],
            $topA['score'],
            $topB['name'],
            $topB['score'],
            count($next)
        );

        EvolutionLogger::log('genetic_ab', 'proposed', ['experiment_id' => $experimentId, 'generation' => $gen]);

        return [
            'ok' => true,
            'experiment_id' => $experimentId,
            'generation' => $gen,
            'variants' => $next,
            'summary' => $summary,
        ];
    }

    /**
     * Optional extra weight from cro_events.jsonl lines with matching experiment_id + variant.
     */
    private function croBonus(string $experimentId, string $variantName): int
    {
        $path = BASE_PATH . '/storage/evolution/cro_events.jsonl';
        if (!is_file($path)) {
            return 0;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return 0;
        }
        $n = 0;
        foreach (array_slice($lines, -3000) as $line) {
            $j = @json_decode((string) $line, true);
            if (!is_array($j)) {
                continue;
            }
            if (($j['experiment_id'] ?? '') !== $experimentId) {
                continue;
            }
            if (($j['variant'] ?? $j['variant_name'] ?? '') !== $variantName) {
                continue;
            }
            $n++;
        }

        return min(50, $n);
    }

    private function nextGenerationNumber(string $experimentId): int
    {
        $dir = BASE_PATH . '/storage/evolution/genetic_ab';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $experimentId) . '.json';
        $n = 0;
        if (is_file($path)) {
            $j = @json_decode((string) @file_get_contents($path), true);
            if (is_array($j)) {
                $n = (int) ($j['generation'] ?? 0);
            }
        }
        $n++;
        @file_put_contents($path, json_encode(['generation' => $n, 'updated' => gmdate('c')], JSON_PRETTY_PRINT) . "\n");

        return $n;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function crossoverCss(array $a, array $b, int $salt): string
    {
        $out = [];
        $max = max(count($a), count($b));
        for ($i = 0; $i < $max; $i++) {
            if (($i + $salt) % 2 === 0 && isset($a[$i])) {
                $out[] = $a[$i];
            } elseif (isset($b[$i])) {
                $out[] = $b[$i];
            }
        }

        return trim(implode("\n", $out)) . "\n/* genetic crossover */\n";
    }
}
