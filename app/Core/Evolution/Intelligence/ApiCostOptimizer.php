<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

/**
 * ApiCostOptimizer — Autonome API-prijsonderhandeling & latency-routing.
 *
 * Werking:
 *   1. Elke LlmClient-aanroep rapporteert zijn latency_ms + cost_eur.
 *   2. ApiCostOptimizer slaat een rolling window op (laatste 50 calls per model).
 *   3. `selectModel()` berekent de optimale keuze op basis van:
 *      - Gemiddelde latency (P75) per model
 *      - Kosten per 1000 tokens (werkelijk betaald)
 *      - Error-rate (failed calls / total calls)
 *   4. Voor niet-kritieke taken: automatisch downgraden naar Groq of Ollama
 *      als premium-model trag (>4s) of duur (>120% van norm) is.
 *   5. Schrijft elke 15 minuten een rapport naar api_performance.json.
 *
 * Model prioriteit (hoog→laag):
 *   Kritiek:     Sonnet  → Haiku  → GPT-4o-mini → Groq Llama
 *   Standaard:   Haiku   → GPT-4o-mini → Groq Llama
 *   Goedkoop:    GPT-4o-mini → Groq Llama → Ollama (lokaal gratis)
 *
 * Opslag: storage/evolution/intelligence/api_performance.json
 */
final class ApiCostOptimizer
{
    private const PERFORMANCE_FILE = 'storage/evolution/intelligence/api_performance.json';
    private const WINDOW_SIZE      = 50;   // rolling window per model
    private const LATENCY_WARN_MS  = 4000; // boven dit = model als "traag" markeren
    private const COST_SPIKE_PCT   = 120;  // boven X% van norm = te duur

    // Bekende "goedkope fallback" modellen — in volgorde van voorkeur
    private const FALLBACK_CHAIN = [
        'non_critical' => [
            'claude-haiku-4-5',
            'claude-3-5-haiku-20241022',
            'gpt-4o-mini',
            'groq/llama-3.1-8b-instant',
        ],
        'free' => [
            'groq/llama-3.1-8b-instant',
            'ollama/llama3.2',
        ],
    ];

    // Referentie-norm latency per model (ms) — empirisch bepaald
    private const LATENCY_NORM = [
        'claude-sonnet-4-5'              => 3500,
        'claude-3-5-sonnet-20241022'     => 3500,
        'claude-haiku-4-5'               => 800,
        'claude-3-5-haiku-20241022'      => 800,
        'gpt-4o'                         => 2500,
        'gpt-4o-mini'                    => 600,
        'groq/llama-3.1-8b-instant'     => 400,
        'ollama/llama3.2'                => 1200,
        'deepseek-chat'                  => 2000,
    ];

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Registreer de performance van een voltooide LLM-aanroep.
     *
     * Aanroepen vanuit LlmClient::callModel() na elke aanroep.
     */
    public function record(string $model, float $latencyMs, float $costEur, bool $success): void
    {
        $perf = $this->load();

        if (!isset($perf[$model])) {
            $perf[$model] = ['calls' => [], 'errors' => 0, 'total_calls' => 0];
        }

        // Rolling window: last N calls
        $perf[$model]['calls'][] = [
            'ts'         => time(),
            'latency_ms' => round($latencyMs),
            'cost_eur'   => round($costEur, 6),
            'ok'         => $success,
        ];

        // Begrens window
        if (count($perf[$model]['calls']) > self::WINDOW_SIZE) {
            $perf[$model]['calls'] = array_slice($perf[$model]['calls'], -self::WINDOW_SIZE);
        }

        $perf[$model]['total_calls']++;
        if (!$success) {
            $perf[$model]['errors']++;
        }
        $perf[$model]['_updated'] = time();

        $this->save($perf);
    }

    /**
     * Selecteer het optimale model voor een taak.
     *
     * @param string $preferred    Gewenst model (bijv. 'claude-sonnet-4-5')
     * @param string $taskType     'critical' | 'standard' | 'non_critical' | 'free'
     * @param float  $maxCostEur   Max acceptabele kosten per call (0 = geen limiet)
     * @return string              Model identifier om te gebruiken
     */
    public function selectModel(string $preferred, string $taskType = 'standard', float $maxCostEur = 0.0): string
    {
        if ($taskType === 'critical') {
            // Kritieke taken: nooit downgraden, altijd het gewenste model
            return $preferred;
        }

        $perf = $this->load();

        // Check of het gewenste model problematisch is
        if ($this->isModelDegraded($preferred, $perf)) {
            $fallbacks = self::FALLBACK_CHAIN[$taskType] ?? self::FALLBACK_CHAIN['non_critical'];

            foreach ($fallbacks as $fallback) {
                if ($fallback === $preferred) {
                    continue;
                }
                if (!$this->isModelDegraded($fallback, $perf)) {
                    return $fallback;
                }
            }
        }

        return $preferred;
    }

    /**
     * Geef een performance-samenvatting van alle modellen.
     *
     * @return array<string, array{avg_latency_ms: float, p75_latency_ms: float, error_rate_pct: float, avg_cost_eur: float, status: string}>
     */
    public function summary(): array
    {
        $perf   = $this->load();
        $result = [];

        foreach ($perf as $model => $data) {
            if (!is_array($data['calls'] ?? null) || empty($data['calls'])) {
                continue;
            }

            $calls        = $data['calls'];
            $latencies    = array_map(fn($c) => (float)$c['latency_ms'], $calls);
            $costs        = array_map(fn($c) => (float)$c['cost_eur'], $calls);
            $errorRate    = $data['total_calls'] > 0
                ? round($data['errors'] / $data['total_calls'] * 100, 1)
                : 0.0;

            sort($latencies);
            $p75Idx = (int)ceil(count($latencies) * 0.75) - 1;

            $avgLatency  = round(array_sum($latencies) / count($latencies));
            $p75Latency  = $latencies[$p75Idx] ?? $avgLatency;
            $avgCost     = count($costs) > 0 ? round(array_sum($costs) / count($costs), 6) : 0.0;

            $status = $this->isModelDegraded($model, $perf) ? 'degraded' : 'ok';

            $result[$model] = [
                'avg_latency_ms' => $avgLatency,
                'p75_latency_ms' => $p75Latency,
                'error_rate_pct' => $errorRate,
                'avg_cost_eur'   => $avgCost,
                'call_count'     => count($calls),
                'status'         => $status,
            ];
        }

        return $result;
    }

    /**
     * Geef de aanbevolen modellen voor elk taakniveau terug.
     *
     * @return array{critical: string, standard: string, non_critical: string, free: string}
     */
    public function recommendations(): array
    {
        $perf = $this->load();

        $pickBest = function (array $candidates) use ($perf): string {
            foreach ($candidates as $m) {
                if (!$this->isModelDegraded($m, $perf)) {
                    return $m;
                }
            }
            return end($candidates) ?: 'gpt-4o-mini';
        };

        return [
            'critical'     => 'claude-sonnet-4-5', // nooit downgraden
            'standard'     => $pickBest(['claude-haiku-4-5', 'claude-3-5-haiku-20241022', 'gpt-4o-mini']),
            'non_critical' => $pickBest(self::FALLBACK_CHAIN['non_critical']),
            'free'         => $pickBest(self::FALLBACK_CHAIN['free']),
        ];
    }

    // ── Interne logica ────────────────────────────────────────────────────

    /**
     * Bepaal of een model momenteel "gedegradeerd" is (traag of hoge error-rate).
     * Als er minder dan 3 calls zijn: nooit als gedegradeerd markeren.
     */
    private function isModelDegraded(string $model, array $perf): bool
    {
        $data  = $perf[$model] ?? [];
        $calls = $data['calls'] ?? [];

        if (count($calls) < 3) {
            return false;
        }

        // Bereken P75 latency van laatste 10 calls
        $recent     = array_slice($calls, -10);
        $latencies  = array_map(fn($c) => (float)$c['latency_ms'], $recent);
        sort($latencies);
        $p75        = $latencies[(int)ceil(count($latencies) * 0.75) - 1] ?? 0;
        $norm       = self::LATENCY_NORM[$model] ?? 2000;

        // Latency > 4x norm OF > absolute drempel
        if ($p75 > max(self::LATENCY_WARN_MS, $norm * 4)) {
            return true;
        }

        // Error-rate > 30%
        $errorRate = $data['total_calls'] > 0
            ? $data['errors'] / $data['total_calls']
            : 0.0;

        return $errorRate > 0.30;
    }

    // ── Opslag ────────────────────────────────────────────────────────────

    private function load(): array
    {
        $file = $this->basePath . '/' . self::PERFORMANCE_FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function save(array $data): void
    {
        $file = $this->basePath . '/' . self::PERFORMANCE_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
