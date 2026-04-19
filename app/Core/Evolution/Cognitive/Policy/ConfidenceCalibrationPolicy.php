<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive\Policy;

use App\Core\Evolution\Cognitive\ReasoningStep;

/**
 * ConfidenceCalibrationPolicy — shrinks confidence toward 0.5 when the
 * predictor model is in a weak regime.
 *
 * "A model that usually lies should be heard with caution."
 *
 * Observes the predictor identity + historical hit-rate (if provided via
 * $context['tracking']) and emits a NEUTRAL step whose signed contribution
 * is zero but whose weight dampens the aggregate via the engine's
 * calibration step (engine multiplies aggregate by avg(calibration.factor)).
 *
 * This isn't a separate "lean"; it's a rudder. The engine reads
 * calibration.factor from the step's observations.
 */
final class ConfidenceCalibrationPolicy implements ReasoningPolicyInterface
{
    public function name(): string
    {
        return 'confidence_calibration';
    }

    public function evaluate(array $context): array
    {
        $snapshot = (array) ($context['snapshot'] ?? []);
        $scores   = (array) ($snapshot['scores'] ?? []);
        $tracking = (array) ($context['tracking'] ?? []);

        $model     = (string) ($scores['model'] ?? 'unknown');
        $hitRate   = isset($tracking['hit_rate'])  ? (float) $tracking['hit_rate']  : null;
        $sample    = isset($tracking['sample_size']) ? (int) $tracking['sample_size'] : 0;
        $minSample = 20;

        // Default calibration: full confidence.
        $factor = 1.0;
        $reason = 'No track record yet — running at full confidence.';

        if ($model === 'heuristic_v1') {
            // Heuristic models are inherently less trustworthy than neural-net ones.
            $factor = 0.75;
            $reason = 'Heuristic-only predictor (no trained weights) — shrink confidence 25%.';
        }
        if ($model === 'rindow_pending') {
            $factor = 0.85;
            $reason = 'Neural weights loaded but forward-pass not yet wired — shrink confidence 15%.';
        }

        // If we have enough outcome samples, calibrate against observed hit rate.
        if ($hitRate !== null && $sample >= $minSample) {
            // A classifier at 0.5 hit-rate is a coin flip; worth nothing.
            // Map [0.5, 1.0] → [0.0, 1.0] linearly. Below 0.5 → penalty.
            if ($hitRate >= 0.5) {
                $factor = min(1.0, max(0.2, ($hitRate - 0.5) * 2.0));
            } else {
                // Actually worse than random — invert would be "trust opposite", but
                // that's too clever; treat as 0.1 (very low) and let human review.
                $factor = 0.1;
            }
            $reason = sprintf(
                'Calibrated to %.2f from %.0f%% hit-rate over %d observed outcomes.',
                $factor,
                $hitRate * 100,
                $sample
            );
        }

        $observations = [
            'predictor_model'   => $model,
            'historical_hit_rate' => $hitRate,
            'sample_size'       => $sample,
            'calibration'       => ['factor' => $factor],
        ];

        return [
            'step' => new ReasoningStep(
                policy:       $this->name(),
                direction:    'NEUTRAL',
                weight:       0.0,        // no directional push
                confidence:   1.0,        // certain about the rudder itself
                rationale:    $reason,
                observations: $observations,
            ),
        ];
    }
}
