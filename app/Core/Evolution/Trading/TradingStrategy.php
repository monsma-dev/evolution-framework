<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * TradingStrategy — RSI + SMA crossover + Trailing Stop-Loss signals for ETH/EUR day trading.
 *
 * Signals:
 *   BUY           — RSI < 35 (oversold), short SMA trending up vs long SMA
 *   SELL          — RSI > 65 (overbought), short SMA below long SMA
 *   TRAILING_STOP — Positie staat in winst (>= profit_pct), prijs daalt >= trail_pct van piek
 *   HOLD          — everything in between
 *
 * Configurable thresholds in evolution.json under 'trading.strategy'.
 *
 * Trailing Stop-Loss:
 *   Zodra een positie >= trailing_profit_pct in winst staat (bijv. +2%), trekt de agent
 *   automatisch een grens mee omhoog. Als de prijs daarna >= trailing_drop_pct daalt (bijv. 0.5%),
 *   verkoopt de agent direct — ongeacht wat RSI zegt.
 *   State: storage/evolution/trading/trailing_state.json
 *
 * Trailing Take Profit (multi-level floor stops):
 *   Level 1: bij >= +1.5% winst → floor stop op +0.5% boven aankoopprijs
 *   Level 2: bij >= +3.0% winst → floor stop omhoog naar +2.0% boven aankoopprijs
 *   Config: trading.strategy.trailing_tp.{level1_profit_pct, level1_floor_pct, level2_profit_pct, level2_floor_pct}
 *
 * 15m Agressief Scalping:
 *   RSI(15m) < rsi_15m_aggressive_threshold (default 40) mits RSI(1h) > 40 (positieve trend)
 *   Configureerbaar via trading.strategy.rsi_15m_aggressive_threshold
 */
final class TradingStrategy
{
    private float  $rsiBuy;
    private float  $rsiSell;
    private int    $rsiPeriods;
    private int    $smaFast;
    private int    $smaSlow;
    private float  $trailingProfitPct;
    private float  $trailingDropPct;
    private float  $tpLevel1ProfitPct;
    private float  $tpLevel1FloorPct;
    private float  $tpLevel2ProfitPct;
    private float  $tpLevel2FloorPct;
    private float  $rsi15mAggressiveThreshold;
    private string $stateFile;

    public function __construct(array $config = [], ?string $basePath = null)
    {
        $this->rsiBuy           = (float)($config['rsi_buy_threshold']   ?? 35.0);
        $this->rsiSell          = (float)($config['rsi_sell_threshold']  ?? 65.0);
        $this->rsiPeriods       = (int)($config['rsi_periods']           ?? 14);
        $this->smaFast          = (int)($config['sma_fast']              ?? 7);
        $this->smaSlow          = (int)($config['sma_slow']              ?? 20);
        $this->trailingProfitPct= (float)($config['trailing_profit_pct'] ?? 2.0);
        $this->trailingDropPct  = (float)($config['trailing_drop_pct']   ?? 0.5);
        $tp = (array)($config['trailing_tp'] ?? []);
        $this->tpLevel1ProfitPct          = (float)($tp['level1_profit_pct'] ?? 1.5);
        $this->tpLevel1FloorPct           = (float)($tp['level1_floor_pct']  ?? 0.5);
        $this->tpLevel2ProfitPct          = (float)($tp['level2_profit_pct'] ?? 3.0);
        $this->tpLevel2FloorPct           = (float)($tp['level2_floor_pct']  ?? 2.0);
        $this->rsi15mAggressiveThreshold  = (float)($config['rsi_15m_aggressive_threshold'] ?? 40.0);
        $base = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->stateFile = $base . '/data/evolution/trading/trailing_state.json';
    }

    /**
     * Analyse price history and return a trading signal.
     * Includes Trailing Stop-Loss check (runs BEFORE RSI/SMA).
     *
     * @param  array $priceHistory  [{ts: int, price: float}, ...]
     * @param  float $buyPrice      Gemiddelde aankoopprijs van open positie (0 = geen positie)
     * @return array{signal: string, strength: int, reason: string, rsi: float, sma_fast: float, sma_slow: float, current: float, trailing: array|null}
     */
    public function analyse(array $priceHistory, float $buyPrice = 0.0): array
    {
        $prices  = array_column($priceHistory, 'price');
        $count   = count($prices);

        if ($count < $this->smaSlow + 1) {
            return $this->neutral($prices, 'Onvoldoende data voor analyse');
        }

        $current  = (float)end($prices);
        $rsi      = $this->rsi($prices, $this->rsiPeriods);
        $smaFast  = $this->sma($prices, $this->smaFast);
        $smaSlow  = $this->sma($prices, $this->smaSlow);
        $trendUp  = $smaFast > $smaSlow;
        $trendDown= $smaFast < $smaSlow;

        // ── Trailing Stop-Loss check (hogere prioriteit dan RSI) ──────────
        $trailing = $this->checkTrailingStop($current, $buyPrice);
        if ($trailing['triggered']) {
            return array_merge([
                'signal'   => 'SELL',
                'strength' => 100,
                'reason'   => $trailing['reason'],
                'rsi'      => round($rsi, 2),
                'sma_fast' => round($smaFast, 2),
                'sma_slow' => round($smaSlow, 2),
                'current'  => round($current, 2),
                'trend'    => $trendUp ? 'UP' : ($trendDown ? 'DOWN' : 'FLAT'),
            ], ['trailing' => $trailing]);
        }

        // Update trailing peak als positie in winst staat
        if ($buyPrice > 0 && $current > $buyPrice * (1 + $this->trailingProfitPct / 100)) {
            $this->updateTrailingPeak($current, $buyPrice);
        }

        $signal   = 'HOLD';
        $strength = 0;
        $reason   = '';

        if ($rsi < $this->rsiBuy && $trendUp) {
            $signal   = 'BUY';
            $strength = (int)min(100, ($this->rsiBuy - $rsi) * 4);
            $reason   = sprintf('RSI oversold (%.1f), opwaartse trend (SMA%d > SMA%d)', $rsi, $this->smaFast, $this->smaSlow);
        } elseif ($rsi < $this->rsiBuy) {
            $signal   = 'BUY';
            $strength = (int)min(70, ($this->rsiBuy - $rsi) * 2);
            $reason   = sprintf('RSI oversold (%.1f)', $rsi);
        } elseif ($rsi > $this->rsiSell && $trendDown) {
            $signal   = 'SELL';
            $strength = (int)min(100, ($rsi - $this->rsiSell) * 4);
            $reason   = sprintf('RSI overbought (%.1f), neerwaartse trend (SMA%d < SMA%d)', $rsi, $this->smaFast, $this->smaSlow);
        } elseif ($rsi > $this->rsiSell) {
            $signal   = 'SELL';
            $strength = (int)min(70, ($rsi - $this->rsiSell) * 2);
            $reason   = sprintf('RSI overbought (%.1f)', $rsi);
        } else {
            $reason = sprintf('RSI neutraal (%.1f), trend %s', $rsi, $trendUp ? 'omhoog' : ($trendDown ? 'omlaag' : 'zijwaarts'));
        }

        // BUY: start trailing state
        if ($signal === 'BUY') {
            $this->resetTrailingState();
        }

        return [
            'signal'   => $signal,
            'strength' => $strength,
            'reason'   => $reason,
            'rsi'      => round($rsi, 2),
            'sma_fast' => round($smaFast, 2),
            'sma_slow' => round($smaSlow, 2),
            'current'  => round($current, 2),
            'trend'    => $trendUp ? 'UP' : ($trendDown ? 'DOWN' : 'FLAT'),
            'trailing' => $trailing,
        ];
    }

    /**
     * Multi-Timeframe Analyse: "Micro-Macro" filter (15m + 1h).
     *
     * Logica:
     *   Trigger:      RSI(15m) < 30  → micro-paniek gedetecteerd
     *   Bevestiging:  RSI(1h) > 40   → algemene trend nog positief
     *   Stop-Loss:    Als open positie > 3% onder aankoopprijs → DIRECT SELL
     *
     * @param  array $history15m  15-minuten candles [{ts, price}, ...]
     * @param  array $history1h   1-uurs candles [{ts, price}, ...]
     * @param  float $buyPrice    Gemiddelde aankoopprijs (0 = geen positie)
     * @return array{signal: string, strength: int, reason: string, rsi_15m: float, rsi_1h: float, timeframe: string}
     */
    public function analyseMultiTimeframe(array $history15m, array $history1h, float $buyPrice = 0.0): array
    {
        $prices15m = array_column($history15m, 'price');
        $prices1h  = array_column($history1h, 'price');

        if (count($prices15m) < $this->rsiPeriods + 1 || count($prices1h) < $this->rsiPeriods + 1) {
            return [
                'signal'   => 'HOLD',
                'strength' => 0,
                'reason'   => 'Onvoldoende data voor multi-timeframe analyse',
                'rsi_15m'  => 50.0,
                'rsi_1h'   => 50.0,
                'timeframe'=> 'multi',
            ];
        }

        $rsi15m  = $this->rsi($prices15m, $this->rsiPeriods);
        $rsi1h   = $this->rsi($prices1h, $this->rsiPeriods);
        $current = (float)end($prices1h);

        // ── Harde Stop-Loss: 3% onder aankoopprijs → DIRECT SELL ──────────
        if ($buyPrice > 0 && $current < $buyPrice * 0.97) {
            $lossPct = round(($buyPrice - $current) / $buyPrice * 100, 2);
            return [
                'signal'    => 'SELL',
                'strength'  => 100,
                'reason'    => sprintf(
                    'Stop-Loss: prijs €%.2f is %.2f%% onder aankoopprijs €%.2f (limiet: -3%%)',
                    $current, $lossPct, $buyPrice
                ),
                'rsi_15m'   => round($rsi15m, 2),
                'rsi_1h'    => round($rsi1h, 2),
                'timeframe' => 'multi',
            ];
        }

        // ── Agressief 15m Scalping: RSI(15m) < threshold, 1h trend positief ──
        // Threshold configureerbaar: rsi_15m_aggressive_threshold (default 40)
        if ($rsi15m < $this->rsi15mAggressiveThreshold) {
            if ($rsi1h > 40.0) {
                $strength = (int)min(100, ($this->rsi15mAggressiveThreshold - $rsi15m) * 4);
                return [
                    'signal'    => 'BUY',
                    'strength'  => $strength,
                    'reason'    => sprintf(
                        'Agressief Scalp BUY: RSI(15m) %.1f < %.0f, RSI(1h) %.1f > 40 (1u trend positief)',
                        $rsi15m, $this->rsi15mAggressiveThreshold, $rsi1h
                    ),
                    'rsi_15m'   => round($rsi15m, 2),
                    'rsi_1h'    => round($rsi1h, 2),
                    'timeframe' => 'multi',
                ];
            }
            return [
                'signal'    => 'HOLD',
                'strength'  => 0,
                'reason'    => sprintf(
                    '15m signaal genegeerd: RSI(15m) %.1f < %.0f, maar RSI(1h) %.1f < 40 (1u trend negatief — vallend mes)',
                    $rsi15m, $this->rsi15mAggressiveThreshold, $rsi1h
                ),
                'rsi_15m'   => round($rsi15m, 2),
                'rsi_1h'    => round($rsi1h, 2),
                'timeframe' => 'multi',
            ];
        }

        // ── Overbought SELL op 1u ─────────────────────────────────────────
        if ($rsi1h > $this->rsiSell) {
            return [
                'signal'    => 'SELL',
                'strength'  => (int)min(100, ($rsi1h - $this->rsiSell) * 4),
                'reason'    => sprintf('RSI(1h) overbought: %.1f > %.1f', $rsi1h, $this->rsiSell),
                'rsi_15m'   => round($rsi15m, 2),
                'rsi_1h'    => round($rsi1h, 2),
                'timeframe' => 'multi',
            ];
        }

        return [
            'signal'    => 'HOLD',
            'strength'  => 0,
            'reason'    => sprintf('Multi-TF neutraal: RSI(15m) %.1f | RSI(1h) %.1f', $rsi15m, $rsi1h),
            'rsi_15m'   => round($rsi15m, 2),
            'rsi_1h'    => round($rsi1h, 2),
            'timeframe' => 'multi',
        ];
    }

    /**
     * Controleer of de trailing stop-loss of trailing take-profit getriggerd is.
     *
     * Multi-level Trailing Take Profit (floor-based):
     *   Level 1: winst >= +1.5% → floor stop op aankoopprijs + 0.5%
     *   Level 2: winst >= +3.0% → floor stop omhoog naar aankoopprijs + 2.0%
     *
     * @return array{triggered: bool, reason: string, peak: float, drop_pct: float, tp_level: int}
     */
    public function checkTrailingStop(float $currentPrice, float $buyPrice): array
    {
        $empty = ['triggered' => false, 'reason' => '', 'peak' => 0.0, 'drop_pct' => 0.0, 'tp_level' => 0];
        if ($buyPrice <= 0 || $currentPrice <= 0) {
            return $empty;
        }

        $state      = $this->loadTrailingState();
        $peak       = (float)($state['peak']             ?? 0);
        $tpLevel    = (int)($state['tp_level']           ?? 0);
        $floorPrice = (float)($state['floor_stop_price'] ?? 0.0);
        $profitPct  = ($currentPrice - $buyPrice) / $buyPrice * 100;

        // ── Trailing Take Profit: upgrade floor level als winst stijgt ──────
        if ($profitPct >= $this->tpLevel2ProfitPct && $tpLevel < 2) {
            $newFloor = $buyPrice * (1 + $this->tpLevel2FloorPct / 100);
            $this->saveTrailingState(array_merge($state, [
                'tp_level'         => 2,
                'floor_stop_price' => $newFloor,
                'updated_at'       => date('c'),
            ]));
            $tpLevel    = 2;
            $floorPrice = $newFloor;
        } elseif ($profitPct >= $this->tpLevel1ProfitPct && $tpLevel < 1) {
            $newFloor = $buyPrice * (1 + $this->tpLevel1FloorPct / 100);
            $this->saveTrailingState(array_merge($state, [
                'tp_level'         => 1,
                'floor_stop_price' => $newFloor,
                'updated_at'       => date('c'),
            ]));
            $tpLevel    = 1;
            $floorPrice = $newFloor;
        }

        // ── Floor stop: prijs zakt tot of onder de gegarandeerde vloer ──────
        if ($tpLevel > 0 && $floorPrice > 0 && $currentPrice <= $floorPrice) {
            $lockedPct = $tpLevel === 2 ? $this->tpLevel2FloorPct : $this->tpLevel1FloorPct;
            $this->resetTrailingState();
            return [
                'triggered' => true,
                'reason'    => sprintf(
                    'Trailing TP Level %d: prijs €%.2f raakte floor €%.2f (winst +%.1f%% beschermd)',
                    $tpLevel, $currentPrice, $floorPrice, $lockedPct
                ),
                'peak'      => $peak,
                'drop_pct'  => round(($floorPrice > 0 ? ($floorPrice - $currentPrice) / $floorPrice * 100 : 0), 3),
                'tp_level'  => $tpLevel,
            ];
        }

        // ── Oorspronkelijke piek-gebaseerde trailing stop ────────────────────
        if ($profitPct < $this->trailingProfitPct || $peak <= 0) {
            return array_merge($empty, ['tp_level' => $tpLevel]);
        }

        $dropFromPeak = ($peak - $currentPrice) / $peak * 100;
        if ($dropFromPeak >= $this->trailingDropPct) {
            $this->resetTrailingState();
            return [
                'triggered' => true,
                'reason'    => sprintf(
                    'Trailing Stop: prijs daalde %.2f%% van piek €%.2f → €%.2f (limiet %.1f%%)',
                    $dropFromPeak, $peak, $currentPrice, $this->trailingDropPct
                ),
                'peak'      => $peak,
                'drop_pct'  => round($dropFromPeak, 3),
                'tp_level'  => $tpLevel,
            ];
        }

        return array_merge($empty, ['peak' => $peak, 'drop_pct' => round($dropFromPeak, 3), 'tp_level' => $tpLevel]);
    }

    private function updateTrailingPeak(float $currentPrice, float $buyPrice): void
    {
        $state = $this->loadTrailingState();
        if ($currentPrice > (float)($state['peak'] ?? 0)) {
            $this->saveTrailingState(['peak' => $currentPrice, 'buy_price' => $buyPrice, 'updated_at' => date('c')]);
        }
    }

    private function resetTrailingState(): void
    {
        $this->saveTrailingState([
            'peak'             => 0.0,
            'buy_price'        => 0.0,
            'tp_level'         => 0,
            'floor_stop_price' => 0.0,
            'reset_at'         => date('c'),
        ]);
    }

    private function loadTrailingState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        return json_decode((string)file_get_contents($this->stateFile), true) ?? [];
    }

    private function saveTrailingState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
    }

    /** RSI calculation (Wilder smoothing). */
    public function rsi(array $prices, int $periods = 14): float
    {
        $count = count($prices);
        if ($count < $periods + 1) {
            return 50.0;
        }

        $gains  = 0.0;
        $losses = 0.0;
        $start  = $count - $periods - 1;

        for ($i = $start + 1; $i <= $start + $periods; $i++) {
            $diff = $prices[$i] - $prices[$i - 1];
            if ($diff > 0) {
                $gains += $diff;
            } else {
                $losses += abs($diff);
            }
        }

        $avgGain = $gains  / $periods;
        $avgLoss = $losses / $periods;

        // Continue with Wilder smoothing for remaining bars
        for ($i = $start + $periods + 1; $i < $count; $i++) {
            $diff    = $prices[$i] - $prices[$i - 1];
            $gain    = max(0.0, $diff);
            $loss    = max(0.0, -$diff);
            $avgGain = ($avgGain * ($periods - 1) + $gain) / $periods;
            $avgLoss = ($avgLoss * ($periods - 1) + $loss) / $periods;
        }

        if ($avgLoss < 0.0001) {
            return 100.0;
        }
        $rs = $avgGain / $avgLoss;
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    /** Simple Moving Average of last N prices. */
    public function sma(array $prices, int $periods = 20): float
    {
        $slice = array_slice($prices, -$periods);
        if (empty($slice)) {
            return 0.0;
        }
        return array_sum($slice) / count($slice);
    }

    private function neutral(array $prices, string $reason): array
    {
        return [
            'signal'   => 'HOLD',
            'strength' => 0,
            'reason'   => $reason,
            'rsi'      => 50.0,
            'sma_fast' => 0.0,
            'sma_slow' => 0.0,
            'current'  => !empty($prices) ? round(end($prices), 2) : 0.0,
            'trend'    => 'FLAT',
        ];
    }
}
