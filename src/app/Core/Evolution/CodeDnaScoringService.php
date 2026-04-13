<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Code DNA Scoring: assigns a maintainability score (1-10) to each class.
 *
 * Metrics: method count, average method length, max method length, nesting depth,
 * class line count, number of dependencies (use statements).
 *
 * Used by the AI system prompt to advise refactoring before patching low-score classes.
 */
final class CodeDnaScoringService
{
    private const SCORE_CACHE_FILE = 'storage/evolution/code_dna_scores.json';
    private const MAX_CACHE_AGE = 86400;

    /**
     * Score a single class file.
     *
     * @return array{score: int, metrics: array<string, mixed>, advice: string}
     */
    public function scoreClass(string $fqcn): array
    {
        $relative = str_replace('\\', '/', substr($fqcn, 4));
        $file = BASE_PATH . '/src/app/' . $relative . '.php';

        $patchFile = BASE_PATH . '/storage/patches/' . $relative . '.php';
        if (is_file($patchFile)) {
            $file = $patchFile;
        }

        if (!is_file($file)) {
            return ['score' => 0, 'metrics' => ['error' => 'File not found'], 'advice' => 'Class file not found.'];
        }

        $source = @file_get_contents($file);
        if (!is_string($source)) {
            return ['score' => 0, 'metrics' => ['error' => 'Cannot read file'], 'advice' => 'Cannot read class file.'];
        }

        $metrics = $this->analyzeSource($source);

        return $this->computeScore($fqcn, $metrics);
    }

    /**
     * DNA score for arbitrary PHP source (e.g. proposed patch) without writing to disk.
     *
     * @return array{score: int, metrics: array<string, mixed>, advice: string}
     */
    public function scorePhpSource(string $fqcn, string $source): array
    {
        $metrics = $this->analyzeSource($source);

        return $this->computeScore($fqcn, $metrics);
    }

    /**
     * Score all App\ classes and cache the results.
     *
     * @return array{ok: bool, scores: array<string, array{score: int, metrics: array, advice: string}>, count: int}
     */
    public function scoreAll(Config $config): array
    {
        $cached = $this->readCache();
        if ($cached !== null) {
            return ['ok' => true, 'scores' => $cached, 'count' => count($cached), 'from_cache' => true];
        }

        $srcDir = BASE_PATH . '/src/app';
        if (!is_dir($srcDir)) {
            return ['ok' => false, 'scores' => [], 'count' => 0];
        }

        $scores = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($srcDir) + 1));
            $fqcn = 'App\\' . str_replace('/', '\\', substr($rel, 0, -4));
            $result = $this->scoreClass($fqcn);
            $scores[$fqcn] = $result;
        }

        $this->writeCache($scores);

        return ['ok' => true, 'scores' => $scores, 'count' => count($scores)];
    }

    /**
     * Get classes with scores below threshold (default 4/10).
     *
     * @return list<array{fqcn: string, score: int, advice: string}>
     */
    public function getCriticalClasses(Config $config, int $threshold = 4): array
    {
        $all = $this->scoreAll($config);
        $critical = [];
        foreach ($all['scores'] as $fqcn => $data) {
            if ($data['score'] <= $threshold) {
                $critical[] = [
                    'fqcn' => $fqcn,
                    'score' => $data['score'],
                    'advice' => $data['advice'],
                ];
            }
        }

        usort($critical, static fn(array $a, array $b) => $a['score'] - $b['score']);

        return $critical;
    }

    /**
     * Build a summary string for the AI system prompt.
     */
    public function promptAppend(Config $config): string
    {
        $critical = $this->getCriticalClasses($config);
        if ($critical === []) {
            return '';
        }

        $lines = ["\n\nCODE_DNA_WARNINGS (low maintainability classes — consider refactoring before patching):"];
        foreach (array_slice($critical, 0, 8) as $c) {
            $lines[] = "  - {$c['fqcn']} (score {$c['score']}/10): {$c['advice']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, int|float>
     */
    private function analyzeSource(string $source): array
    {
        $lines = substr_count($source, "\n") + 1;
        $methods = preg_match_all('/\b(public|protected|private)\s+(static\s+)?function\s+\w+/i', $source);
        $useStatements = preg_match_all('/^use\s+[\w\\\\]+;/m', $source);

        $methodLengths = [];
        if (preg_match_all('/\b(?:public|protected|private)\s+(?:static\s+)?function\s+\w+\s*\([^)]*\)(?:\s*:\s*[^{]+)?\s*\{/s', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$match, $offset]) {
                $depth = 0;
                $start = strpos($source, '{', $offset);
                if ($start === false) {
                    continue;
                }
                $len = strlen($source);
                $end = $start;
                for ($i = $start; $i < $len; $i++) {
                    if ($source[$i] === '{') {
                        $depth++;
                    } elseif ($source[$i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $i;
                            break;
                        }
                    }
                }
                $methodSource = substr($source, $start, $end - $start + 1);
                $methodLengths[] = substr_count($methodSource, "\n") + 1;
            }
        }

        $avgMethodLength = $methodLengths !== [] ? array_sum($methodLengths) / count($methodLengths) : 0;
        $maxMethodLength = $methodLengths !== [] ? max($methodLengths) : 0;

        $maxNesting = 0;
        $currentNesting = 0;
        for ($i = 0, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') {
                $currentNesting++;
                $maxNesting = max($maxNesting, $currentNesting);
            } elseif ($source[$i] === '}') {
                $currentNesting = max(0, $currentNesting - 1);
            }
        }

        return [
            'lines' => $lines,
            'methods' => (int)$methods,
            'use_statements' => (int)$useStatements,
            'avg_method_lines' => round($avgMethodLength, 1),
            'max_method_lines' => $maxMethodLength,
            'max_nesting_depth' => $maxNesting,
        ];
    }

    /**
     * @param array<string, int|float> $metrics
     * @return array{score: int, metrics: array<string, mixed>, advice: string}
     */
    private function computeScore(string $fqcn, array $metrics): array
    {
        $score = 10;
        $reasons = [];

        $lines = (int)($metrics['lines'] ?? 0);
        if ($lines > 500) {
            $score -= 2;
            $reasons[] = 'Very large file (' . $lines . ' lines)';
        } elseif ($lines > 300) {
            $score -= 1;
            $reasons[] = 'Large file (' . $lines . ' lines)';
        }

        $methods = (int)($metrics['methods'] ?? 0);
        if ($methods > 20) {
            $score -= 2;
            $reasons[] = 'Too many methods (' . $methods . ')';
        } elseif ($methods > 12) {
            $score -= 1;
            $reasons[] = 'Many methods (' . $methods . ')';
        }

        $maxMethod = (int)($metrics['max_method_lines'] ?? 0);
        if ($maxMethod > 80) {
            $score -= 2;
            $reasons[] = 'Longest method is ' . $maxMethod . ' lines';
        } elseif ($maxMethod > 40) {
            $score -= 1;
            $reasons[] = 'Long method (' . $maxMethod . ' lines)';
        }

        $avgMethod = (float)($metrics['avg_method_lines'] ?? 0);
        if ($avgMethod > 30) {
            $score -= 1;
            $reasons[] = 'High average method length (' . $avgMethod . ' lines)';
        }

        $nesting = (int)($metrics['max_nesting_depth'] ?? 0);
        if ($nesting > 6) {
            $score -= 2;
            $reasons[] = 'Deep nesting (depth ' . $nesting . ')';
        } elseif ($nesting > 4) {
            $score -= 1;
            $reasons[] = 'Moderate nesting (depth ' . $nesting . ')';
        }

        $deps = (int)($metrics['use_statements'] ?? 0);
        if ($deps > 15) {
            $score -= 1;
            $reasons[] = 'Many dependencies (' . $deps . ' use statements)';
        }

        $score = max(1, min(10, $score));

        $advice = $reasons !== []
            ? implode('; ', $reasons) . '.'
            : 'Good maintainability.';

        return ['score' => $score, 'metrics' => $metrics, 'advice' => $advice];
    }

    /**
     * @return array<string, array{score: int, metrics: array, advice: string}>|null
     */
    private function readCache(): ?array
    {
        $path = BASE_PATH . '/' . self::SCORE_CACHE_FILE;
        if (!is_file($path)) {
            return null;
        }
        if (time() - filemtime($path) > self::MAX_CACHE_AGE) {
            return null;
        }
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? @json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, array{score: int, metrics: array, advice: string}> $scores
     */
    private function writeCache(array $scores): void
    {
        $path = BASE_PATH . '/' . self::SCORE_CACHE_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, json_encode($scores, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
