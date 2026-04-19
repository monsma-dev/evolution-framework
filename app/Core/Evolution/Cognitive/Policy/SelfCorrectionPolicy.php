<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive\Policy;

use App\Core\Evolution\Cognitive\ReasoningStep;

/**
 * SelfCorrectionPolicy — reads the last N prediction outcomes and nudges
 * the current direction based on systematic bias.
 *
 * If we recently over-predicted UP and reality went DOWN three times in a
 * row, we emit a small counter-lean DOWN. If the predictor has been
 * honest-but-wobbly, we emit a NEUTRAL note and stay out of the way.
 *
 * The engine reads these nudges like any other step: they contribute to
 * aggregate_score via weight * confidence * sign.
 *
 * Context keys consulted:
 *   $context['tracking']['recent_outcomes']  list<array{predicted:string, actual:string, delta_pct:float}>
 */
final class SelfCorrectionPolicy implements ReasoningPolicyInterface
{
    private const LOOKBACK = 10;
    private const BIAS_THRESHOLD = 0.6;  // 60% of lookback must disagree to trigger

    public function name(): string
    {
        return 'self_correction';
    }

    public function evaluate(array $context): array
    {
        $tracking = (array) ($context['tracking'] ?? []);
        $recent   = (array) ($tracking['recent_outcomes'] ?? []);

        // Take newest N, chronological doesn't matter for bias count.
        $window = array_slice(array_values($recent), 0, self::LOOKBACK);
        $total  = count($window);

        if ($total < 3) {
            return ['step' => new ReasoningStep(
                policy:       $this->name(),
                direction:    'NEUTRAL',
                weight:       0.0,
                confidence:   1.0,
                rationale:    sprintf('Not enough outcomes yet (%d < 3) to assess bias.', $total),
                observations: ['window_size' => $total],
            )];
        }

        $upMiss   = 0;  // predicted UP, actual DOWN
        $downMiss = 0;  // predicted DOWN, actual UP
        foreach ($window as $row) {
            $pred = strtoupper((string) ($row['predicted'] ?? ''));
            $act  = strtoupper((string) ($row['actual'] ?? ''));
            if ($pred === 'UP' && $act === 'DOWN') {
                $upMiss++;
            }
            if ($pred === 'DOWN' && $act === 'UP') {
                $downMiss++;
            }
        }

        $obs = [
            'window_size'      => $total,
            'up_misses'        => $upMiss,
            'down_misses'      => $downMiss,
            'bias_threshold'   => self::BIAS_THRESHOLD,
        ];

        // UP bias: we keep saying UP but it's actually DOWN.
        $upBias   = $upMiss   / max(1, $total);
        $downBias = $downMiss / max(1, $total);

        if ($upBias >= self::BIAS_THRESHOLD) {
            return ['step' => new ReasoningStep(
                $this->name(),
                'DOWN',
                weight:       0.15,
                confidence:   $upBias,
                rationale:    sprintf(
                    'Systematic UP over-prediction: %d/%d recent UP calls landed DOWN. Counter-leaning DOWN.',
                    $upMiss, $total
                ),
                observations: $obs,
            )];
        }
        if ($downBias >= self::BIAS_THRESHOLD) {
            return ['step' => new ReasoningStep(
                $this->name(),
                'UP',
                weight:       0.15,
                confidence:   $downBias,
                rationale:    sprintf(
                    'Systematic DOWN over-prediction: %d/%d recent DOWN calls landed UP. Counter-leaning UP.',
                    $downMiss, $total
                ),
                observations: $obs,
            )];
        }

        return ['step' => new ReasoningStep(
            $this->name(),
            'NEUTRAL',
            weight:       0.05,
            confidence:   1.0 - max($upBias, $downBias),
            rationale:    sprintf(
                'No systematic bias detected (up-miss %.0f%%, down-miss %.0f%%).',
                $upBias * 100,
                $downBias * 100
            ),
            observations: $obs,
        )];
    }
}
