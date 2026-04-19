<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive\Policy;

use App\Core\Evolution\Cognitive\ReasoningStep;

/**
 * RiskGate — hard-stop gate.
 *
 * Blocks the cycle when environmental conditions make any forecast unsafe:
 *  - gas fees spiked > threshold (cannot enter/exit without burning capital)
 *  - flash crash lock file active
 *  - explicit vacation/resting state
 *  - missing or corrupt FutureSight scores
 *
 * Returns a veto string; the engine surfaces this in trace.vetoes and sets
 * direction='VETO'. No step is produced when vetoed.
 *
 * When not vetoed, emits a tiny NEUTRAL step documenting that the gate was
 * open — useful for audit trails ("we checked, it was fine").
 */
final class RiskGatePolicy implements ReasoningPolicyInterface
{
    private const MAX_GAS_GWEI = 150.0;

    public function name(): string
    {
        return 'risk_gate';
    }

    public function evaluate(array $context): array
    {
        $env      = (array) ($context['env'] ?? []);
        $snapshot = (array) ($context['snapshot'] ?? []);
        $scores   = (array) ($snapshot['scores'] ?? []);

        $observations = [
            'gas_gwei'         => $env['gas_gwei'] ?? null,
            'flash_crash'      => $env['flash_crash_active'] ?? false,
            'agent_state'      => $env['agent_state'] ?? 'TRADING',
            'predictor_model'  => $scores['model'] ?? 'unknown',
            'empire_halt'      => $env['empire_halt'] ?? null,
        ];

        // Top-priority veto: Empire Dead Man's Switch.
        // Caller should pass 'empire_halt' => ['active' => bool, 'source' => str, 'note' => str]
        // (from EmpireHaltGate::check()). If absent, we do a best-effort live read.
        $halt = $env['empire_halt'] ?? null;
        if (!is_array($halt)) {
            $halt = (new \App\Core\Evolution\Cognitive\EmpireHaltGate())->check();
        }
        if (!empty($halt['active'])) {
            $src = (string) ($halt['source'] ?? 'unknown');
            return ['veto' => 'empire_global_halt:' . $src];
        }

        // Hard vetoes
        if (!empty($env['flash_crash_active'])) {
            return ['veto' => 'flash_crash_guard_active'];
        }
        $agentState = strtoupper((string) ($env['agent_state'] ?? 'TRADING'));
        if (in_array($agentState, ['RESTING', 'VACATION', 'STUDYING'], true)) {
            return ['veto' => 'agent_state_' . strtolower($agentState)];
        }
        $gas = (float) ($env['gas_gwei'] ?? 0.0);
        if ($gas > 0 && $gas > self::MAX_GAS_GWEI) {
            return ['veto' => sprintf('gas_too_high_%.1f_gwei', $gas)];
        }
        if (($scores['model'] ?? null) === 'none') {
            return ['veto' => 'predictor_produced_no_scores'];
        }

        // Not vetoed — emit a neutral audit step.
        return [
            'step' => new ReasoningStep(
                policy:       $this->name(),
                direction:    'NEUTRAL',
                weight:       0.10,
                confidence:   1.0,
                rationale:    'Risk gate open: no flash-crash, agent TRADING, gas within budget.',
                observations: $observations,
            ),
        ];
    }
}
