<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Ten micro-agent perspectives: same inference stack, orthogonal system biases.
 * Outputs feed Super Jury arbiter (intelligence-style multi-view fusion).
 */
final class SwarmPerspectiveService
{
    /** @var list<array{id: string, bias: string, system: string}> */
    private const PERSPECTIVES = [
        ['id' => 'M1', 'bias' => 'extreme_pessimist', 'system' => 'You are the PESSIMIST agent. Assume worst-case execution, hidden fees, black swans. Output JSON: {"risk_score":0-10,"concerns":[],"verdict":"BLOCK|CAUTION|OK"}'],
        ['id' => 'M2', 'bias' => 'on_chain_only', 'system' => 'You are the ON-CHAIN agent. Ignore news; reason only from mempool, liquidity, contract risk. Output JSON: {"risk_score":0-10,"on_chain_notes":[],"verdict":"BLOCK|CAUTION|OK"}'],
        ['id' => 'M3', 'bias' => 'regulatory', 'system' => 'You are the POLICY agent. EU/US/UK AI and crypto rules; compliance friction. Output JSON: {"risk_score":0-10,"regulatory_hooks":[],"verdict":"BLOCK|CAUTION|OK"}'],
        ['id' => 'M4', 'bias' => 'liquidity', 'system' => 'You are the LIQUIDITY agent. Slippage, depth, exit path. Output JSON: {"risk_score":0-10,"liquidity_notes":[],"verdict":"BLOCK|CAUTION|OK"}'],
        ['id' => 'M5', 'bias' => 'adversarial', 'system' => 'You are the RED-TEAM agent. Find exploit paths and social engineering. Output JSON: {"risk_score":0-10,"attacks":[],"verdict":"BLOCK|CAUTION|OK"}'],
        ['id' => 'M6', 'bias' => 'macro', 'system' => 'You are the MACRO agent. Rates, FX, risk-off flows. Output JSON: {"risk_score":0-10,"macro_notes":[],"verdict":"BLOCK|CAUTION|OK"}'],
        ['id' => 'M7', 'bias' => 'operator', 'system' => 'You are the OPS agent. Runbooks, incident response, rollback. Output JSON: {"risk_score":0-10,"ops_notes":[],"verdict":"BLOCK|CAUTION|OK"}'],
        ['id' => 'M8', 'bias' => 'game_theory', 'system' => 'You are the GAME-THEORY agent. MEV, front-running, incentive misalignment. Output JSON: {"risk_score":0-10,"game_notes":[],"verdict":"BLOCK|CAUTION|OK"}'],
        ['id' => 'M9', 'bias' => 'user_trust', 'system' => 'You are the TRUST/UX agent. User harm, dark patterns, support load. Output JSON: {"risk_score":0-10,"trust_notes":[],"verdict":"BLOCK|CAUTION|OK"}'],
        ['id' => 'M10', 'bias' => 'synthesis_light', 'system' => 'You are the TIE-BREAKER. If others conflict, pick the conservative path. Output JSON: {"risk_score":0-10,"tie_break":[],"verdict":"BLOCK|CAUTION|OK"}'],
    ];

    public function __construct(private readonly Config $config) {}

    /**
     * @return list<array{id: string, bias: string, ok: bool, text: string}>
     */
    public function runMicroAgents(string $task, string $context, EvolutionMentorService $mentor, ?int $limitAgents = null): array
    {
        $evo = $this->config->get('evolution.swarm_perspectives', []);
        if (is_array($evo) && !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $n = max(1, min(10, (int)(is_array($evo) ? ($evo['micro_agent_count'] ?? 10) : 10)));
        if ($limitAgents !== null) {
            $n = max(1, min(10, $limitAgents));
        }
        $out = [];
        foreach (array_slice(self::PERSPECTIVES, 0, $n) as $p) {
            $user = "TASK:\n{$task}\n\nCONTEXT:\n" . mb_substr($context, 0, 1200) . "\n\nReply with JSON only.";
            $resp = $mentor->juniorDelegate(
                $this->config,
                'swarm:' . $p['bias'],
                $p['system'] . "\n\n" . $user
            );
            $out[] = [
                'id'   => $p['id'],
                'bias' => $p['bias'],
                'ok'   => (bool)($resp['ok'] ?? false),
                'text' => (string)($resp['text'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{id: string, bias: string, ok: bool, text: string}> $micro
     */
    public function formatForArbiter(array $micro): string
    {
        $lines = [];
        foreach ($micro as $m) {
            $lines[] = '[' . $m['id'] . ' / ' . $m['bias'] . '] ' . mb_substr($m['text'], 0, 400);
        }

        return implode("\n\n", $lines);
    }
}
