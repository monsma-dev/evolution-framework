<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive;

use App\Core\Container;
use App\Core\Evolution\Cognitive\Policy\ConfidenceCalibrationPolicy;
use App\Core\Evolution\Cognitive\Policy\ReasoningPolicyInterface;
use App\Core\Evolution\Cognitive\Policy\RiskGatePolicy;
use App\Core\Evolution\Cognitive\Policy\SelfCorrectionPolicy;
use App\Core\Evolution\Cognitive\Policy\SentimentWeightingPolicy;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ReasoningEngine — meta-layer that orchestrates the cognitive policies.
 *
 * Given a FutureSight snapshot + environmental context + (optional) prior
 * outcome tracking, runs a fixed pipeline of policies and collapses their
 * outputs into a single ReasoningTrace with:
 *   - ordered list of ReasoningSteps
 *   - vetoes (if any policy hard-stopped the cycle)
 *   - aggregate directional score in [-1, +1]
 *   - confidence in [0, 1]
 *   - final direction: 'UP' | 'DOWN' | 'NEUTRAL' | 'VETO'
 *   - calibration block (how and why we scaled things)
 *
 * Default pipeline: RiskGate -> Sentiment -> Calibration -> SelfCorrection.
 * Override via constructor for testing or custom blends.
 *
 * This is DETERMINISTIC PHP. No LLM calls. It codifies "how we think" so
 * the agent layer (LlmClient, AgentDispatcher) can rely on a stable
 * reasoning contract before making expensive external requests.
 *
 * Persistence is the caller's responsibility — wrap reason() with
 * ReasoningTraceModel::insertTrace($trace) to enable the feedback loop.
 */
final class ReasoningEngine
{
    /** @var list<ReasoningPolicyInterface> */
    private array $policies;

    /**
     * @param list<ReasoningPolicyInterface>|null $policies
     */
    public function __construct(
        private readonly ?Container $container = null,
        ?array $policies = null
    ) {
        $this->policies = $policies ?? self::defaultPipeline();
    }

    /**
     * @return list<ReasoningPolicyInterface>
     */
    public static function defaultPipeline(): array
    {
        return [
            new RiskGatePolicy(),
            new SentimentWeightingPolicy(),
            new ConfidenceCalibrationPolicy(),
            new SelfCorrectionPolicy(),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot  FutureSight output (see FutureSightService::run()).
     * @param array<string, mixed> $env       Environmental flags: gas_gwei, flash_crash_active, agent_state.
     * @param array<string, mixed> $tracking  Optional: hit_rate, sample_size, recent_outcomes[].
     */
    public function reason(array $snapshot, array $env = [], array $tracking = []): ReasoningTrace
    {
        $context = [
            'snapshot' => $snapshot,
            'env'      => $env,
            'tracking' => $tracking,
        ];

        $steps  = [];
        $vetoes = [];

        foreach ($this->policies as $policy) {
            $out = $policy->evaluate($context);
            if (isset($out['veto']) && is_string($out['veto']) && $out['veto'] !== '') {
                $vetoes[] = $policy->name() . ':' . $out['veto'];
                continue;
            }
            if (isset($out['step']) && $out['step'] instanceof ReasoningStep) {
                $steps[] = $out['step'];
            }
        }

        [$direction, $aggregate, $confidence, $calibration] = $this->collapse($steps, $vetoes);

        $summary = $this->buildSummary($direction, $aggregate, $confidence, $steps, $vetoes);

        return new ReasoningTrace(
            correlationId:  $this->correlationId(),
            createdAt:      new DateTimeImmutable('now', new DateTimeZone('UTC')),
            inputSnapshot:  $this->slimSnapshot($snapshot),
            steps:          $steps,
            vetoes:         $vetoes,
            direction:      $direction,
            aggregateScore: $aggregate,
            confidence:     $confidence,
            calibration:    $calibration,
            summary:        $summary,
        );
    }

    /**
     * @param list<ReasoningStep> $steps
     * @param list<string>        $vetoes
     * @return array{0: string, 1: float, 2: float, 3: array<string, mixed>}
     */
    private function collapse(array $steps, array $vetoes): array
    {
        if ($vetoes !== []) {
            return ['VETO', 0.0, 0.0, ['factor' => 0.0, 'reason' => 'vetoed']];
        }

        // Sum weighted signed contributions; normalise by total weight of
        // directional steps (NEUTRAL has weight 0 in that sum).
        $signedSum    = 0.0;
        $dirWeightSum = 0.0;
        $confSum      = 0.0;
        $confWeights  = 0.0;

        // Pull calibration factor from any policy that emits one.
        $calibrationFactor = 1.0;
        $calibrationReason = 'no calibration rudder applied';

        foreach ($steps as $step) {
            $sig = $step->signedContribution();
            if ($step->direction !== 'NEUTRAL' && $step->weight > 0) {
                $signedSum    += $sig;
                $dirWeightSum += $step->weight;
            }
            $confSum     += $step->weight * $step->confidence;
            $confWeights += $step->weight;

            $cal = $step->observations['calibration'] ?? null;
            if (is_array($cal) && isset($cal['factor']) && is_numeric($cal['factor'])) {
                $calibrationFactor = (float) $cal['factor'];
                $calibrationReason = $step->rationale;
            }
        }

        $raw      = $dirWeightSum > 0 ? $signedSum / $dirWeightSum : 0.0;
        $aggregate = max(-1.0, min(1.0, $raw * $calibrationFactor));

        $avgConf  = $confWeights > 0 ? ($confSum / $confWeights) : 0.0;
        $confidence = max(0.0, min(1.0, $avgConf * $calibrationFactor));

        $direction = 'NEUTRAL';
        if ($aggregate >= 0.15) {
            $direction = 'UP';
        } elseif ($aggregate <= -0.15) {
            $direction = 'DOWN';
        }

        return [$direction, $aggregate, $confidence, [
            'factor'        => $calibrationFactor,
            'reason'        => $calibrationReason,
            'dir_weight_sum'=> round($dirWeightSum, 4),
            'signed_sum'    => round($signedSum, 4),
        ]];
    }

    /**
     * @param list<ReasoningStep> $steps
     * @param list<string>        $vetoes
     */
    private function buildSummary(
        string $direction,
        float $aggregate,
        float $confidence,
        array $steps,
        array $vetoes
    ): string {
        if ($vetoes !== []) {
            return sprintf('VETO (%d): %s', count($vetoes), implode('; ', $vetoes));
        }
        $topByWeight = null;
        $topWeight = -1.0;
        foreach ($steps as $s) {
            $w = $s->weight * $s->confidence;
            if ($s->direction !== 'NEUTRAL' && $w > $topWeight) {
                $topWeight = $w;
                $topByWeight = $s;
            }
        }
        $anchor = $topByWeight !== null
            ? sprintf(' Lead: %s says %s (%s).', $topByWeight->policy, $topByWeight->direction, $topByWeight->rationale)
            : '';
        return sprintf(
            '%s | score %+.3f, confidence %.2f, %d steps.%s',
            $direction, $aggregate, $confidence, count($steps), $anchor
        );
    }

    /**
     * Strip the snapshot down to a small, stable, JSON-persistable block.
     *
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private function slimSnapshot(array $s): array
    {
        return [
            'direction_nl'            => $s['direction_nl']            ?? null,
            'horizon_hours'           => $s['horizon_hours']           ?? null,
            'spot_price_eur'          => $s['spot_price_eur']          ?? null,
            'first_profit_target_pct' => $s['first_profit_target_pct'] ?? null,
            'chain_id'                => $s['chain_id']                ?? null,
            'network_label'           => $s['network_label']           ?? null,
            'scores'                  => $s['scores']                  ?? [],
            'signal'                  => $s['signal']                  ?? [],
        ];
    }

    private function correlationId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return dechex((int) (microtime(true) * 1000)) . dechex(mt_rand(0, 0xFFFFFF));
        }
    }
}
