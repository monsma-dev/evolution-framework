<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive\Policy;

use App\Core\Evolution\Cognitive\ReasoningStep;

/**
 * SentimentWeightingPolicy — translates the FutureSight signal bundle
 * (RSI, trend, signal, trend_prediction) into a directional lean.
 *
 * Weighting strategy (tuned on intuition, not fitted; SelfCorrectionPolicy
 * adjusts later based on outcomes):
 *
 *   explicit BUY/SELL signal  → 0.50 weight, high confidence
 *   trend_prediction > 0.25   → 0.25 weight, scaled confidence
 *   RSI oversold (<30) + flat → +UP lean  (mean reversion)
 *   RSI overbought (>70) + flat → +DOWN lean (mean reversion)
 *
 * Output: one ReasoningStep.
 */
final class SentimentWeightingPolicy implements ReasoningPolicyInterface
{
    public function name(): string
    {
        return 'sentiment_weighting';
    }

    public function evaluate(array $context): array
    {
        $snapshot = (array) ($context['snapshot'] ?? []);
        $scores   = (array) ($snapshot['scores'] ?? []);
        $sig      = (array) ($snapshot['signal'] ?? []);

        $sigName  = strtoupper((string) ($sig['signal'] ?? 'HOLD'));
        $tp       = (float) ($scores['trend_prediction'] ?? 0.0);
        $rsi      = (float) ($sig['rsi'] ?? 50.0);
        $strength = (float) ($sig['strength'] ?? 50.0);

        $observations = [
            'signal'            => $sigName,
            'trend_prediction'  => $tp,
            'rsi'               => $rsi,
            'strength_pct'      => $strength,
        ];

        // Primary: explicit signal from upstream (BUY / SELL / HOLD)
        if ($sigName === 'BUY') {
            return ['step' => new ReasoningStep(
                $this->name(),
                'UP',
                weight:       0.50,
                confidence:   min(1.0, max(0.4, $strength / 100.0)),
                rationale:    sprintf('Explicit BUY signal at strength %.0f%%.', $strength),
                observations: $observations
            )];
        }
        if ($sigName === 'SELL') {
            return ['step' => new ReasoningStep(
                $this->name(),
                'DOWN',
                weight:       0.50,
                confidence:   min(1.0, max(0.4, $strength / 100.0)),
                rationale:    sprintf('Explicit SELL signal at strength %.0f%%.', $strength),
                observations: $observations
            )];
        }

        // Secondary: trend_prediction is decisive
        if (abs($tp) > 0.25) {
            $dir  = $tp > 0 ? 'UP' : 'DOWN';
            $conf = min(1.0, abs($tp));
            return ['step' => new ReasoningStep(
                $this->name(),
                $dir,
                weight:       0.30,
                confidence:   $conf,
                rationale:    sprintf('Trend prediction %+.3f pushes %s.', $tp, $dir),
                observations: $observations
            )];
        }

        // Tertiary: mean-reversion from extreme RSI
        if ($rsi < 30.0) {
            return ['step' => new ReasoningStep(
                $this->name(),
                'UP',
                weight:       0.20,
                confidence:   (30.0 - $rsi) / 30.0,
                rationale:    sprintf('RSI %.1f oversold, mean-reversion lean UP.', $rsi),
                observations: $observations
            )];
        }
        if ($rsi > 70.0) {
            return ['step' => new ReasoningStep(
                $this->name(),
                'DOWN',
                weight:       0.20,
                confidence:   ($rsi - 70.0) / 30.0,
                rationale:    sprintf('RSI %.1f overbought, mean-reversion lean DOWN.', $rsi),
                observations: $observations
            )];
        }

        // No strong signal anywhere — honest NEUTRAL.
        return ['step' => new ReasoningStep(
            $this->name(),
            'NEUTRAL',
            weight:       0.15,
            confidence:   0.3,
            rationale:    'No strong directional signal (RSI mid-range, tp near zero, HOLD).',
            observations: $observations
        )];
    }
}
