<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive;

use App\Core\Container;
use App\Domain\Web\Models\MarketFingerprintModel;

/**
 * MarketMemoryService — long-term pattern archive for the reasoning brain.
 *
 * Distinct from App\Core\Evolution\Trading\TradeMemoryService which only
 * captures moments when trade decisions are made (capped at 500 JSONL
 * entries). This service fingerprints EVERY observed market snapshot so
 * similarity search works across months.
 *
 * Fingerprint scheme (24-char string):
 *   r{rsi_bin}t{trend_bin}v{vol_bin}p{price_bin}d{DIR_FIRST_CHAR}
 * e.g. "r03t02v01p214dU"  →  RSI~30, trend slightly up, low volatility, price ~€2140 band, Direction UP
 *
 * Bins (deterministic):
 *   rsi_bin:  RSI // 10              (0..9)
 *   trend_bin: round(trend_pred * 10) clamped [-5, +5] -> 0..10
 *   vol_bin:  intensity(volatility_pct) 0..7
 *   price_bin: round(price / 10)     (e.g. 214 = €2140 band)
 *
 * Use cases:
 *   - record(): store fingerprint from a ReasoningTrace + snapshot
 *   - similarTo(): find historical matches + aggregate win-rate
 *   - resolveOpen(): batch-resolve pending rows when outcomes land
 *   - NeuralForecaster.phrase(): human-readable "I remember this from ..."
 *
 * This is the reflection layer — it LEARNS from what it sees, without
 * relying on what it did.
 */
final class MarketMemoryService
{
    public const MIN_SAMPLES_FOR_CONFIDENCE = 5;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Record a market fingerprint from an already-produced reasoning trace.
     *
     * @param ReasoningTrace       $trace   The engine output
     * @param array<string, mixed> $snapshot Original FutureSightService snapshot
     * @return string               The fingerprint that was recorded
     */
    public function record(ReasoningTrace $trace, array $snapshot): string
    {
        $signal = (array) ($snapshot['signal'] ?? []);
        $scores = (array) ($snapshot['scores'] ?? []);

        $rsi    = (float) ($signal['rsi']               ?? 50.0);
        $trend  = (float) ($scores['trend_prediction']  ?? 0.0);
        $price  = (float) ($snapshot['spot_price_eur']  ?? 0.0);
        $volPct = (float) ($signal['strength']          ?? 50.0) / 100.0;

        $bins = $this->bins($rsi, $trend, $volPct, $price);
        $fp   = $this->encode($bins, $trace->direction);

        $model = new MarketFingerprintModel($this->container);
        $model->insertFingerprint([
            'fingerprint'    => $fp,
            'correlation_id' => $trace->correlationId,
            'chain_id'       => $snapshot['chain_id'] ?? null,
            'rsi_bin'        => $bins['rsi'],
            'trend_bin'      => $bins['trend'],
            'volatility_bin' => $bins['vol'],
            'price_bin'      => $bins['price'],
            'direction'      => $trace->direction,
            'rsi_value'      => round($rsi, 2),
            'trend_value'    => round($trend, 4),
            'spot_price_eur' => round($price, 6),
        ]);

        return $fp;
    }

    /**
     * Find historical resolved matches for a (RSI, trend, vol, price, dir) cell.
     *
     * @return array{
     *   fingerprint: string,
     *   sample: array{total:int,wins:int,losses:int,flats:int,avg_outcome_pct:float|null,win_rate:float|null},
     *   recent: list<array<string,mixed>>,
     *   enough_samples: bool,
     *   narrative: string
     * }
     */
    public function similarTo(array $snapshot, string $direction): array
    {
        $signal = (array) ($snapshot['signal'] ?? []);
        $scores = (array) ($snapshot['scores'] ?? []);

        $rsi    = (float) ($signal['rsi']               ?? 50.0);
        $trend  = (float) ($scores['trend_prediction']  ?? 0.0);
        $price  = (float) ($snapshot['spot_price_eur']  ?? 0.0);
        $volPct = (float) ($signal['strength']          ?? 50.0) / 100.0;

        $bins = $this->bins($rsi, $trend, $volPct, $price);
        $fp   = $this->encode($bins, $direction);

        $model = new MarketFingerprintModel($this->container);
        $agg   = $model->aggregateByFingerprint($fp);
        $recent= $model->similarResolved($fp, 10);

        $enough = $agg['total'] >= self::MIN_SAMPLES_FOR_CONFIDENCE;

        return [
            'fingerprint'   => $fp,
            'sample'        => $agg,
            'recent'        => $recent,
            'enough_samples'=> $enough,
            'narrative'     => $this->narrative($fp, $agg, $enough, $direction),
        ];
    }

    /**
     * Resolve pending rows older than `maxAgeHours` by comparing then-price
     * with the current spot price and labelling WIN/LOSS/FLAT.
     *
     * @return array{resolved:int}
     */
    public function resolveOpen(float $currentSpotEur, int $maxAgeHours = 4, int $batchLimit = 100): array
    {
        $model = new MarketFingerprintModel($this->container);
        $cutoff = gmdate('Y-m-d H:i:s.v', time() - $maxAgeHours * 3600);
        $rows   = $model->pendingOlderThan($cutoff, $batchLimit);
        $resolved = 0;
        foreach ($rows as $row) {
            $id    = (int) ($row['id'] ?? 0);
            $start = (float) ($row['spot_price_eur'] ?? 0.0);
            if ($id === 0 || $start <= 0) {
                continue;
            }
            $pct   = (($currentSpotEur / $start) - 1.0) * 100.0;
            $label = 'FLAT';
            if ($pct >= 0.25)      { $label = 'WIN'; }
            elseif ($pct <= -0.25) { $label = 'LOSS'; }
            if ($model->resolveFingerprint($id, $pct, $label)) {
                $resolved++;
            }
        }
        return ['resolved' => $resolved];
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * @return array{rsi:int,trend:int,vol:int,price:int}
     */
    private function bins(float $rsi, float $trend, float $volFraction, float $priceEur): array
    {
        return [
            'rsi'   => (int) min(9, max(0, (int) ($rsi / 10))),
            'trend' => (int) min(10, max(0, (int) round(($trend + 0.5) * 10))),
            'vol'   => (int) min(7, max(0, (int) round($volFraction * 7))),
            'price' => (int) max(0, (int) round($priceEur / 10)),
        ];
    }

    /**
     * @param array{rsi:int,trend:int,vol:int,price:int} $bins
     */
    private function encode(array $bins, string $direction): string
    {
        $d = strtoupper($direction);
        $ch = match ($d) {
            'UP'    => 'U',
            'DOWN'  => 'D',
            'VETO'  => 'V',
            default => 'N',
        };
        return sprintf(
            'r%02dt%02dv%02dp%04dd%s',
            $bins['rsi'],
            $bins['trend'],
            $bins['vol'],
            min(9999, $bins['price']),
            $ch
        );
    }

    /**
     * @param array{total:int,wins:int,losses:int,flats:int,avg_outcome_pct:float|null,win_rate:float|null} $agg
     */
    private function narrative(string $fp, array $agg, bool $enough, string $direction): string
    {
        if ($agg['total'] === 0) {
            return sprintf('Fingerprint %s — no historical precedent yet.', $fp);
        }
        if (!$enough) {
            return sprintf(
                'Fingerprint %s — %d prior observation(s), not enough for a pattern verdict (need %d).',
                $fp,
                $agg['total'],
                self::MIN_SAMPLES_FOR_CONFIDENCE
            );
        }
        $winPct = ($agg['win_rate'] ?? 0) * 100;
        $avg    = $agg['avg_outcome_pct'];
        return sprintf(
            'I have seen this pattern %d times before (%s direction). Win-rate %.0f%%, average realised %+.2f%%.',
            $agg['total'],
            $direction,
            $winPct,
            $avg ?? 0.0
        );
    }
}
