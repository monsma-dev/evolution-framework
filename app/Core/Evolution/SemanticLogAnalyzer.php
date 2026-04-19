<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Semantic log analysis: groups errors by pattern, detects temporal correlations,
 * and identifies root causes from error clusters.
 */
final class SemanticLogAnalyzer
{
    /**
     * Analyze today's error log for patterns and correlations.
     *
     * @return array{ok: bool, clusters: list<array{pattern: string, count: int, files: list<string>, first_seen: string, last_seen: string}>, correlations: list<array{errors: list<string>, window_seconds: int, occurrences: int}>, root_causes: list<string>, total_errors: int}
     */
    public function analyze(): array
    {
        $file = BASE_PATH . '/data/logs/errors/' . date('Y-m-d') . '.log';
        if (!is_file($file)) {
            return ['ok' => true, 'clusters' => [], 'correlations' => [], 'root_causes' => [], 'total_errors' => 0];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return ['ok' => true, 'clusters' => [], 'correlations' => [], 'root_causes' => [], 'total_errors' => 0];
        }

        $entries = [];
        foreach ($lines as $line) {
            $j = @json_decode($line, true);
            if (is_array($j)) {
                $entries[] = $j;
            }
        }

        $clusters = $this->clusterByPattern($entries);
        $correlations = $this->findTemporalCorrelations($entries);
        $rootCauses = $this->inferRootCauses($clusters, $correlations);

        return [
            'ok' => true,
            'clusters' => $clusters,
            'correlations' => $correlations,
            'root_causes' => $rootCauses,
            'total_errors' => count($entries),
        ];
    }

    /**
     * Build a prompt section for the AI with semantic analysis results.
     */
    public function promptSection(): string
    {
        $result = $this->analyze();
        if ($result['total_errors'] === 0) {
            return '';
        }

        $lines = ["\n\nSEMANTIC ERROR ANALYSIS ({$result['total_errors']} errors today):"];

        if ($result['clusters'] !== []) {
            $lines[] = 'Error clusters (grouped by pattern):';
            foreach (array_slice($result['clusters'], 0, 8) as $c) {
                $files = implode(', ', array_slice($c['files'], 0, 3));
                $lines[] = "  - ({$c['count']}x) {$c['pattern']} [{$files}]";
            }
        }

        if ($result['correlations'] !== []) {
            $lines[] = 'Temporal correlations (errors that co-occur within seconds):';
            foreach (array_slice($result['correlations'], 0, 5) as $corr) {
                $errors = implode(' + ', $corr['errors']);
                $lines[] = "  - {$errors} (within {$corr['window_seconds']}s, {$corr['occurrences']}x)";
            }
        }

        if ($result['root_causes'] !== []) {
            $lines[] = 'Inferred root causes:';
            foreach ($result['root_causes'] as $rc) {
                $lines[] = "  * {$rc}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Hot-path + latency hints for micro-caching (APCu short TTL) in Architect / Ghost prompts.
     */
    public function microCachePromptSection(?Config $config = null): string
    {
        $result = $this->analyze();
        $lines = ["\n\nMICRO_CACHE_AND_HOT_DATA (verlaag TTFB: APCu 5–30s alleen voor read-mostly, stampede-safe; geen secrets in APCu):"];

        $added = 0;
        foreach (array_slice($result['clusters'] ?? [], 0, 5) as $c) {
            if (($c['count'] ?? 0) < 5) {
                continue;
            }
            foreach (array_slice($c['files'] ?? [], 0, 1) as $f) {
                $lines[] = "  - Veel errors ({$c['count']}x) nabij bestand {$f} — overweeg korte cache of query memoization vóór externe/DB-work.";
                $added++;
                break;
            }
        }

        try {
            $apiWatch = (new ApiContractWatchdog())->analyze($config);
            foreach ($apiWatch['apis'] ?? [] as $api) {
                if (($api['avg_latency_ms'] ?? 0) > 350 && ($api['total_calls'] ?? 0) > 3) {
                    $lines[] = "  - Externe API [{$api['name']}] ~{$api['avg_latency_ms']}ms — cache responsen kort (APCu) met sleutel per tenant + jitter.";
                    $added++;
                }
            }
        } catch (\Throwable) {
        }

        if ($added === 0 && $result['total_errors'] === 0) {
            return '';
        }
        if ($added === 0) {
            $lines[] = '  - Geen harde hotspots gedetecteerd; bij nieuwe read-heavy code: prefer APCu voor request-lokale memoization vóór Redis roundtrip.';

            return implode("\n", $lines);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array{pattern: string, count: int, files: list<string>, first_seen: string, last_seen: string}>
     */
    private function clusterByPattern(array $entries): array
    {
        $groups = [];
        foreach ($entries as $e) {
            $msg = (string)($e['message'] ?? '');
            $pattern = $this->normalizeMessage($msg);
            if ($pattern === '') {
                continue;
            }
            if (!isset($groups[$pattern])) {
                $groups[$pattern] = ['count' => 0, 'files' => [], 'timestamps' => []];
            }
            $groups[$pattern]['count']++;
            $file = (string)($e['file'] ?? '');
            if ($file !== '' && !in_array($file, $groups[$pattern]['files'], true)) {
                $groups[$pattern]['files'][] = $file;
            }
            $ts = (string)($e['time'] ?? $e['ts'] ?? '');
            if ($ts !== '') {
                $groups[$pattern]['timestamps'][] = $ts;
            }
        }

        arsort($groups);
        $clusters = [];
        foreach (array_slice($groups, 0, 15, true) as $pattern => $data) {
            $ts = $data['timestamps'];
            $clusters[] = [
                'pattern' => (string)$pattern,
                'count' => $data['count'],
                'files' => array_slice($data['files'], 0, 5),
                'first_seen' => $ts !== [] ? min($ts) : '',
                'last_seen' => $ts !== [] ? max($ts) : '',
            ];
        }

        return $clusters;
    }

    /**
     * Finds pairs of different error patterns that occur within N seconds of each other.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array{errors: list<string>, window_seconds: int, occurrences: int}>
     */
    private function findTemporalCorrelations(array $entries, int $windowSeconds = 3): array
    {
        $timed = [];
        foreach ($entries as $e) {
            $ts = strtotime((string)($e['time'] ?? $e['ts'] ?? ''));
            if ($ts === false || $ts === 0) {
                continue;
            }
            $pattern = $this->normalizeMessage((string)($e['message'] ?? ''));
            if ($pattern === '') {
                continue;
            }
            $timed[] = ['ts' => $ts, 'pattern' => $pattern];
        }

        usort($timed, static fn(array $a, array $b) => $a['ts'] - $b['ts']);

        $pairCounts = [];
        $count = count($timed);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $diff = $timed[$j]['ts'] - $timed[$i]['ts'];
                if ($diff > $windowSeconds) {
                    break;
                }
                if ($timed[$i]['pattern'] === $timed[$j]['pattern']) {
                    continue;
                }
                $pair = [$timed[$i]['pattern'], $timed[$j]['pattern']];
                sort($pair);
                $key = implode('||', $pair);
                $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
            }
        }

        arsort($pairCounts);
        $correlations = [];
        foreach (array_slice($pairCounts, 0, 5, true) as $key => $occurrences) {
            if ($occurrences < 2) {
                continue;
            }
            $correlations[] = [
                'errors' => explode('||', $key),
                'window_seconds' => $windowSeconds,
                'occurrences' => $occurrences,
            ];
        }

        return $correlations;
    }

    /**
     * Infer root causes from clusters and correlations.
     *
     * @return list<string>
     */
    private function inferRootCauses(array $clusters, array $correlations): array
    {
        $causes = [];

        foreach ($correlations as $corr) {
            if ($corr['occurrences'] >= 3) {
                $causes[] = "Likely cascade: " . implode(' triggers ', $corr['errors'])
                    . " ({$corr['occurrences']}x within {$corr['window_seconds']}s — investigate shared dependency)";
            }
        }

        foreach ($clusters as $c) {
            if ($c['count'] >= 10 && count($c['files']) === 1) {
                $causes[] = "Hot spot: {$c['pattern']} ({$c['count']}x, only in {$c['files'][0]})";
            }
        }

        return array_slice($causes, 0, 5);
    }

    private function normalizeMessage(string $msg): string
    {
        $normalized = preg_replace('/\d+/', 'N', $msg) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return substr(trim($normalized), 0, 100);
    }
}
