<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * PatternOracleService — The Oracle
 *
 * Analyseert succesvolle actie-patronen in logs en de signals-tabel.
 * Antwoordt op vragen als: "Wanneer vinden we de meeste High-Intent leads?"
 *
 * Output: storage/evolution/oracle_report.json
 *   - Piek-uren per weekdag voor high-intent signalen
 *   - Gemiddelde intentie-score per bron
 *   - Top niches van afgelopen 30 dagen
 *   - Architect-activiteit: wanneer werkt het systeem het meest
 *
 * Gebruik:
 *   PatternOracleService::run($db);       // genereert rapport
 *   PatternOracleService::report();       // laad laatste rapport (array)
 *
 * Cron: 0 4 * * * php /var/www/html/tooling/run-oracle.php
 */
final class PatternOracleService
{
    private const REPORT_FILE   = 'storage/evolution/oracle_report.json';
    private const LOG_FILE      = 'storage/logs/evolution.log';
    private const WINDOW_DAYS   = 30;
    private const MIN_SAMPLES   = 3;   // minimum datapunten voor patroon

    // ── Public API ──────────────────────────────────────────────────────────────

    /**
     * Analyse uitvoeren en rapport wegschrijven.
     *
     * @return array<string, mixed>
     */
    public static function run(\PDO $db): array
    {
        $report = [
            'generated_at'   => gmdate('c'),
            'window_days'    => self::WINDOW_DAYS,
            'patterns'       => [],
            'peak_hours'     => [],
            'top_niches'     => [],
            'source_scores'  => [],
            'architect_activity' => [],
            'summary'        => '',
        ];

        // 1. High-intent signal patronen uit DB
        $signalPatterns = self::analyzeSignals($db);
        $report['peak_hours']   = $signalPatterns['peak_hours'] ?? [];
        $report['top_niches']   = $signalPatterns['top_niches'] ?? [];
        $report['source_scores']= $signalPatterns['source_scores'] ?? [];

        // 2. Architect-activiteit uit evolution.log
        $report['architect_activity'] = self::analyzeLogActivity();

        // 3. Genereer menselijke patronen
        $report['patterns'] = self::buildPatternInsights($report);

        // 4. Samenvattende zin
        $report['summary'] = self::buildSummary($report);

        // Opslaan
        $path = self::storagePath();
        @file_put_contents($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        EvolutionLogger::log('oracle', 'report_generated', [
            'patterns' => count($report['patterns']),
            'path'     => $path,
        ]);

        return $report;
    }

    /**
     * Laad het laatste rapport.
     * @return array<string, mixed>
     */
    public static function report(): array
    {
        $path = self::storagePath();
        if (!is_file($path)) {
            return ['error' => 'Nog geen rapport — run PatternOracleService::run($db) eerst.'];
        }
        $raw = @file_get_contents($path);
        return is_string($raw) ? (json_decode($raw, true) ?? []) : [];
    }

    // ── Private analysis ─────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function analyzeSignals(\PDO $db): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-" . self::WINDOW_DAYS . " days"));
        $result = ['peak_hours' => [], 'top_niches' => [], 'source_scores' => []];

        // Check if signals table exists
        try {
            $db->query("SELECT 1 FROM signals LIMIT 1");
        } catch (\Exception $e) {
            return $result;
        }

        // 1. Peak hours: uur + weekdag met meeste high-intent hits
        try {
            $sql = "
                SELECT
                    HOUR(created_at)              AS hour_of_day,
                    DAYOFWEEK(created_at)         AS day_of_week,
                    DAYNAME(created_at)           AS day_name,
                    COUNT(*)                      AS total,
                    AVG(intent_score)             AS avg_score,
                    SUM(intent_score >= 0.7)      AS high_intent_count
                FROM signals
                WHERE created_at >= :since
                  AND intent_score >= 0.5
                GROUP BY hour_of_day, day_of_week, day_name
                HAVING high_intent_count >= :min
                ORDER BY high_intent_count DESC, avg_score DESC
                LIMIT 10
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':since' => $since, ':min' => self::MIN_SAMPLES]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $result['peak_hours'][] = [
                    'hour'             => (int)$row['hour_of_day'],
                    'day'              => $row['day_name'],
                    'high_intent'      => (int)$row['high_intent_count'],
                    'total'            => (int)$row['total'],
                    'avg_score'        => round((float)$row['avg_score'], 2),
                ];
            }
        } catch (\Exception $e) {}

        // 2. Top niches
        try {
            $sql = "
                SELECT
                    niche,
                    COUNT(*)         AS hits,
                    AVG(intent_score) AS avg_score
                FROM signals
                WHERE created_at >= :since
                  AND intent_score >= 0.6
                  AND niche IS NOT NULL
                  AND niche != ''
                GROUP BY niche
                ORDER BY hits DESC, avg_score DESC
                LIMIT 8
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':since' => $since]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $result['top_niches'][] = [
                    'niche'     => $row['niche'],
                    'hits'      => (int)$row['hits'],
                    'avg_score' => round((float)$row['avg_score'], 2),
                ];
            }
        } catch (\Exception $e) {}

        // 3. Gemiddelde score per bron
        try {
            $sql = "
                SELECT
                    source,
                    COUNT(*)         AS total,
                    AVG(intent_score) AS avg_score,
                    SUM(intent_score >= 0.7) AS high_intent
                FROM signals
                WHERE created_at >= :since
                GROUP BY source
                ORDER BY avg_score DESC
                LIMIT 10
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':since' => $since]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $result['source_scores'][] = [
                    'source'     => $row['source'],
                    'total'      => (int)$row['total'],
                    'avg_score'  => round((float)$row['avg_score'], 2),
                    'high_intent'=> (int)$row['high_intent'],
                ];
            }
        } catch (\Exception $e) {}

        return $result;
    }

    /**
     * Analyse architect-activiteit uit evolution.log (JSON lines).
     * @return array<string, mixed>
     */
    private static function analyzeLogActivity(): array
    {
        $logPath = defined('BASE_PATH') ? BASE_PATH . '/' . self::LOG_FILE
                                       : dirname(__DIR__, 3) . '/' . self::LOG_FILE;

        if (!is_file($logPath)) {
            return [];
        }

        $since        = strtotime("-" . self::WINDOW_DAYS . " days");
        $hourBuckets  = array_fill(0, 24, 0);  // activiteit per uur
        $dayBuckets   = array_fill(1, 7, 0);   // ma=1 ... zo=7
        $channelCount = [];

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice($lines, -5000) as $line) { // last 5000 entries
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            $ts = $entry['ts'] ?? $entry['timestamp'] ?? null;
            if (!$ts) {
                continue;
            }
            $t = strtotime($ts);
            if ($t === false || $t < $since) {
                continue;
            }
            $hour = (int)date('G', $t);
            $dow  = (int)date('N', $t); // 1=mon, 7=sun
            $hourBuckets[$hour]++;
            $dayBuckets[$dow]++;

            $ch = $entry['channel'] ?? 'unknown';
            $channelCount[$ch] = ($channelCount[$ch] ?? 0) + 1;
        }

        arsort($channelCount);
        $peakHour = array_search(max($hourBuckets), $hourBuckets);
        $peakDay  = array_search(max($dayBuckets),  $dayBuckets);
        $dayNames = [1=>'Maandag',2=>'Dinsdag',3=>'Woensdag',4=>'Donderdag',5=>'Vrijdag',6=>'Zaterdag',7=>'Zondag'];

        return [
            'peak_hour'     => $peakHour,
            'peak_day'      => $dayNames[$peakDay] ?? 'Onbekend',
            'hour_buckets'  => $hourBuckets,
            'top_channels'  => array_slice($channelCount, 0, 8, true),
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @return list<array{label: string, description: string, confidence: string}>
     */
    private static function buildPatternInsights(array $report): array
    {
        $patterns = [];

        // Piek-moment voor leads
        if (!empty($report['peak_hours'])) {
            $top = $report['peak_hours'][0];
            $pct = $top['total'] > 0 ? round($top['high_intent'] / $top['total'] * 100) : 0;
            $patterns[] = [
                'label'       => 'Beste lead-moment',
                'description' => "Op {$top['day']} om {$top['hour']}:00 uur scoren we {$top['high_intent']} high-intent leads"
                               . " (avg score: {$top['avg_score']}, {$pct}% van alle signalen die dag/uur).",
                'confidence'  => $top['high_intent'] >= 10 ? 'hoog' : 'medium',
            ];

            if (count($report['peak_hours']) >= 2) {
                $second = $report['peak_hours'][1];
                $patterns[] = [
                    'label'       => 'Tweede beste moment',
                    'description' => "{$second['day']} {$second['hour']}:00 uur — {$second['high_intent']} high-intent (avg: {$second['avg_score']}).",
                    'confidence'  => 'medium',
                ];
            }
        }

        // Top niche
        if (!empty($report['top_niches'])) {
            $n = $report['top_niches'][0];
            $patterns[] = [
                'label'       => 'Sterkste niche',
                'description' => "Niche \"{$n['niche']}\" leverde {$n['hits']} high-intent hits met gemiddeld {$n['avg_score']} score.",
                'confidence'  => $n['hits'] >= 5 ? 'hoog' : 'medium',
            ];
        }

        // Architect activiteit
        $act = $report['architect_activity'];
        if (!empty($act['peak_hour'])) {
            $patterns[] = [
                'label'       => 'Architect piek-activiteit',
                'description' => "Het systeem is het actiefst op {$act['peak_day']} om {$act['peak_hour']}:00 uur"
                               . " (meeste log-entries, agent iteraties, shadow patches).",
                'confidence'  => 'hoog',
            ];
        }

        // Best-presterende bron
        if (!empty($report['source_scores'])) {
            $s = $report['source_scores'][0];
            $patterns[] = [
                'label'       => 'Beste databron',
                'description' => "Bron \"{$s['source']}\" geeft de hoogste gemiddelde intent-score ({$s['avg_score']})"
                               . " met {$s['high_intent']} high-intent hits van {$s['total']} totaal.",
                'confidence'  => $s['total'] >= 10 ? 'hoog' : 'low',
            ];
        }

        return $patterns;
    }

    /** @param array<string, mixed> $report */
    private static function buildSummary(array $report): string
    {
        $patternCount = count($report['patterns']);
        if ($patternCount === 0) {
            return 'Nog onvoldoende data voor patronen (min ' . self::MIN_SAMPLES . ' samples per moment nodig).';
        }

        $first = $report['patterns'][0]['description'] ?? '';
        $niche = !empty($report['top_niches']) ? $report['top_niches'][0]['niche'] : 'onbekend';
        return "Oracle heeft $patternCount patronen gevonden. Sterkste: $first Top niche: \"$niche\".";
    }

    private static function storagePath(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $dir  = $base . '/data/evolution';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return $dir . '/oracle_report.json';
    }
}
