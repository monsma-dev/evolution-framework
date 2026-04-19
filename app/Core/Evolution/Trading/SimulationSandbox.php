<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * SimulationSandbox — Monte Carlo prijssimulator voor trade-validatie.
 *
 * Werking (Geometrisch Brownian Motion):
 *   1. Bereken historische volatiliteit σ en drift μ uit recente prijshistorie.
 *   2. Simuleer $scenarios willekeurige prijspaden over $horizonMinutes (standaard 240 = 4u).
 *   3. Trade wordt alleen goedgekeurd als ≥80% van scenario's winstgevend is.
 *
 * Drempel: WIN_THRESHOLD = 0.80 (80%)
 * Win-definitie: eindkoers > aankoopkoers × (1 + MIN_WIN_PCT)
 */
final class SimulationSandbox
{
    private const WIN_THRESHOLD    = 0.80;  // 80% kans op winst vereist
    private const MIN_WIN_PCT      = 0.003; // Minimale winst na fees (+0.3%)
    private const DEFAULT_SCENARIOS= 1000;
    private const DEFAULT_HORIZON_M= 240;   // 4 uur in minuten
    private const MIN_HISTORY      = 5;     // Minimale datapunten voor berekening

    /**
     * Simuleer $scenarios prijspaden en beoordeel of de trade veilig genoeg is.
     *
     * @param  float  $currentPrice     Huidige ETH/EUR koers
     * @param  array  $priceHistory     [{ts: int, price: float}, ...] — uurdata
     * @param  string $direction        'BUY' (wacht op stijging) | 'SELL' (wacht op daling)
     * @param  int    $scenarios        Aantal Monte Carlo paden (standaard 1000)
     * @param  int    $horizonMinutes   Tijdshorizon in minuten (standaard 240 = 4u)
     * @return array{ok: bool, win_rate: float, avg_return_pct: float, p5_return_pct: float,
     *               scenarios: int, reason: string}
     */
    public function simulate(
        float  $currentPrice,
        array  $priceHistory,
        string $direction = 'BUY',
        int    $scenarios = self::DEFAULT_SCENARIOS,
        int    $horizonMinutes = self::DEFAULT_HORIZON_M
    ): array {
        $returns = $this->calcLogReturns($priceHistory);

        if (count($returns) < self::MIN_HISTORY) {
            return [
                'ok'             => true,
                'win_rate'       => 0.5,
                'avg_return_pct' => 0.0,
                'p5_return_pct'  => 0.0,
                'scenarios'      => 0,
                'reason'         => 'Simulatie overgeslagen: onvoldoende prijshistorie',
            ];
        }

        $mu    = array_sum($returns) / count($returns); // Gemiddeld rendement per stap
        $sigma = $this->stddev($returns, $mu);          // Volatiliteit per stap
        $dt    = 1.0;                                   // 1 stap = 1 minuut
        $steps = $horizonMinutes;

        $endPrices = [];

        for ($i = 0; $i < $scenarios; $i++) {
            $price = $currentPrice;
            for ($t = 0; $t < $steps; $t++) {
                $z      = $this->normalRandom();
                $price *= exp(($mu - 0.5 * $sigma * $sigma) * $dt + $sigma * sqrt($dt) * $z);
            }
            $endPrices[] = $price;
        }

        sort($endPrices);

        $wins     = 0;
        $sumRet   = 0.0;
        $minWin   = $currentPrice * (1 + self::MIN_WIN_PCT);

        foreach ($endPrices as $p) {
            $ret    = ($p - $currentPrice) / $currentPrice * 100;
            $sumRet += $ret;
            if ($direction === 'BUY' && $p > $minWin) {
                $wins++;
            } elseif ($direction === 'SELL' && $p < $currentPrice * (1 - self::MIN_WIN_PCT)) {
                $wins++;
            }
        }

        $winRate   = $wins / $scenarios;
        $avgReturn = $sumRet / $scenarios;
        $p5Index   = (int)floor($scenarios * 0.05);
        $p5Return  = ($endPrices[$p5Index] - $currentPrice) / $currentPrice * 100;
        $passed    = $winRate >= self::WIN_THRESHOLD;

        return [
            'ok'             => $passed,
            'win_rate'       => round($winRate, 3),
            'avg_return_pct' => round($avgReturn, 3),
            'p5_return_pct'  => round($p5Return, 3),
            'scenarios'      => $scenarios,
            'reason'         => $passed
                ? sprintf(
                    'Monte Carlo ✅ %.0f%% winstkans in %d scenario\'s (gem. %+.2f%%, P5: %+.2f%%)',
                    $winRate * 100, $scenarios, $avgReturn, $p5Return
                )
                : sprintf(
                    'Monte Carlo ⛔ slechts %.0f%% winstkans (min %.0f%%) — trade te riskant',
                    $winRate * 100, self::WIN_THRESHOLD * 100
                ),
        ];
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    /** Bereken log-returns uit prijshistorie. */
    private function calcLogReturns(array $priceHistory): array
    {
        $prices  = array_column($priceHistory, 'price');
        $returns = [];
        for ($i = 1; $i < count($prices); $i++) {
            $prev = (float)$prices[$i - 1];
            $curr = (float)$prices[$i];
            if ($prev > 0 && $curr > 0) {
                $returns[] = log($curr / $prev);
            }
        }
        return $returns;
    }

    private function stddev(array $values, float $mean): float
    {
        if (count($values) < 2) {
            return 0.01; // Default bij te weinig data
        }
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }
        return sqrt($sum / (count($values) - 1));
    }

    /** Box-Muller transform voor standaard-normaal willekeurig getal. */
    private function normalRandom(): float
    {
        static $spare = null;
        if ($spare !== null) {
            $r = $spare;
            $spare = null;
            return $r;
        }
        do {
            $u = mt_rand() / mt_getrandmax();
        } while ($u <= 0.0);
        $v = mt_rand() / mt_getrandmax();
        $mag = sqrt(-2.0 * log($u));
        $spare = $mag * sin(2.0 * M_PI * $v);
        return $mag * cos(2.0 * M_PI * $v);
    }
}
