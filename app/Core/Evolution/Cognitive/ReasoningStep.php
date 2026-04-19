<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive;

/**
 * Immutable record of a single step in a reasoning chain.
 *
 * Each step captures: which policy produced it, what it observed, the
 * weight it assigned, the direction it leans toward, the confidence it
 * has in that lean (0..1), and a short human explanation.
 *
 * Steps are stackable; the ReasoningEngine combines them into a final
 * ReasoningTrace with an aggregate decision.
 */
final class ReasoningStep
{
    /**
     * @param array<string, mixed> $observations
     */
    public function __construct(
        public readonly string $policy,
        public readonly string $direction,       // 'UP' | 'DOWN' | 'NEUTRAL'
        public readonly float $weight,           // 0..1 — how much this policy should count
        public readonly float $confidence,       // 0..1 — how certain this policy is
        public readonly string $rationale,       // one-line human explanation
        public readonly array $observations = [] // raw inputs this step saw
    ) {
    }

    /**
     * Signed contribution in [-1, +1]: positive = UP lean, negative = DOWN lean.
     * NEUTRAL policies contribute 0.
     */
    public function signedContribution(): float
    {
        $signed = $this->weight * $this->confidence;
        return match (strtoupper($this->direction)) {
            'UP'   => $signed,
            'DOWN' => -$signed,
            default => 0.0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'policy'        => $this->policy,
            'direction'     => $this->direction,
            'weight'        => round($this->weight, 4),
            'confidence'    => round($this->confidence, 4),
            'rationale'     => $this->rationale,
            'contribution'  => round($this->signedContribution(), 4),
            'observations'  => $this->observations,
        ];
    }
}
