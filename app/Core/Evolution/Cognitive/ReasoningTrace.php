<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive;

use DateTimeImmutable;

/**
 * Full trace of a reasoning cycle: input snapshot, ordered policy steps,
 * veto flags, aggregate score, final decision, and calibration notes.
 *
 * Shape chosen so it can be:
 *   - serialised to JSON for persistence (reasoning_traces.payload)
 *   - fed back to the Neural Brain visualizer
 *   - diff-ed against the eventual outcome (see PredictionOutcomeModel)
 */
final class ReasoningTrace
{
    /**
     * @param list<ReasoningStep>  $steps
     * @param list<string>         $vetoes
     * @param array<string, mixed> $calibration
     * @param array<string, mixed> $inputSnapshot
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly DateTimeImmutable $createdAt,
        public readonly array $inputSnapshot,
        public readonly array $steps,
        public readonly array $vetoes,
        public readonly string $direction,      // 'UP' | 'DOWN' | 'NEUTRAL' | 'VETO'
        public readonly float $aggregateScore,  // [-1, +1]
        public readonly float $confidence,      // [0, 1] — overall confidence in the decision
        public readonly array $calibration = [],
        public readonly string $summary = ''
    ) {
    }

    /**
     * True if any policy issued a veto (hard-stop).
     */
    public function isVetoed(): bool
    {
        return $this->direction === 'VETO' || $this->vetoes !== [];
    }

    /**
     * Compact one-line explanation suitable for a log/audit line.
     */
    public function toLogLine(): string
    {
        return sprintf(
            '[%s] %s (score %+.3f, conf %.2f) via %d policies%s',
            $this->correlationId,
            $this->direction,
            $this->aggregateScore,
            $this->confidence,
            count($this->steps),
            $this->vetoes !== [] ? ' | VETOES: ' . implode(', ', $this->vetoes) : ''
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'correlation_id'   => $this->correlationId,
            'created_at'       => $this->createdAt->format(DATE_ATOM),
            'input_snapshot'   => $this->inputSnapshot,
            'steps'            => array_map(fn(ReasoningStep $s) => $s->toArray(), $this->steps),
            'vetoes'           => $this->vetoes,
            'direction'        => $this->direction,
            'aggregate_score'  => round($this->aggregateScore, 4),
            'confidence'       => round($this->confidence, 4),
            'calibration'      => $this->calibration,
            'summary'          => $this->summary,
        ];
    }
}
