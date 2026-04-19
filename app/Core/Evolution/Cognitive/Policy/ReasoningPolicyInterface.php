<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive\Policy;

use App\Core\Evolution\Cognitive\ReasoningStep;

/**
 * A ReasoningPolicy inspects a shared context (FutureSight snapshot, market state,
 * prior outcomes) and either:
 *   - emits a ReasoningStep describing its lean, confidence, and weight, or
 *   - emits a veto string (hard-stop) to block the whole cycle.
 *
 * Policies are pure read-only — they do NOT mutate the context. The engine
 * merges their output.
 *
 * Implementations are deterministic; no I/O beyond what the engine already
 * loaded into $context.
 */
interface ReasoningPolicyInterface
{
    /**
     * @param array<string, mixed> $context  Keys:
     *   - 'snapshot':       FutureSight output (direction_nl, scores, signal, price_eur, ...)
     *   - 'tracking':       optional prior accuracy metrics (hit_rate, sample_size, ...)
     *   - 'env':            environmental flags (market_hours, gas_gwei, ...)
     *
     * @return array{step?: ReasoningStep, veto?: string}
     *   At most one of 'step' or 'veto' per invocation.
     */
    public function evaluate(array $context): array;

    /**
     * Short identifier used in ReasoningStep.policy and logs.
     */
    public function name(): string;
}
