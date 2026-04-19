<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

use App\Core\Evolution\EvolutionProviderKeys;
use App\Core\Evolution\Intelligence\Models\TradingPredictor;
use App\Core\Evolution\Trading\ReasoningLogger;
use App\Core\Evolution\VectorMemoryService;
use App\Domain\AI\LlmClient;
use Psr\Container\ContainerInterface;

/**
 * Strategist + Director-sync gate: Vector Memory trading_nn + TradingPredictor + Director principles.
 * Blocks trades when trend_prediction <= 0 or Director alignment says HOLD.
 */
final class StrategistTradingGate
{
    private const VM_NS = 'trading_nn';

    public function __construct(
        private readonly string $basePath,
        private readonly ?ContainerInterface $container = null
    ) {
    }

    /**
     * @param array<string, mixed> $proposal Validator proposal (side, signal, price_eur, …)
     *
     * @return array{pass: bool, reason: string, trend_prediction: float, modernity_score: float, director_hold: bool, vm_snippets: string}
     */
    public function evaluate(array $proposal): array
    {
        $sync   = new DirectorSyncContext($this->basePath);
        $skill  = $sync->loadSkillJson() ?? [];

        $vmPath = rtrim($this->basePath, '/\\') . '/storage/evolution/vector_memory';
        $vm     = new VectorMemoryService(self::VM_NS, $vmPath);
        $recent = $vm->recentEntries(8);
        $snip   = $this->formatVmSnippets($recent);

        $features = $this->featureVectorFromProposal($proposal);
        $predict  = new TradingPredictor($this->basePath);
        $scores   = $predict->predictScores($features);
        $trend    = (float) ($scores['trend_prediction'] ?? 0.0);
        $mod      = (float) ($scores['modernity_score'] ?? 0.0);

        $directorHold = $sync->heuristicDirectorHold($proposal, $scores, $skill);

        if ($trend <= 0.0) {
            $this->logVeto($trend, $mod, $snip, 'trend_not_positive');
            $sync->appendFeedbackQueue('strategist_veto_trend', [
                'trend_prediction' => $trend,
                'proposal_side'    => $proposal['side'] ?? '',
            ]);

            return [
                'pass'         => false,
                'reason'       => 'Strategist: trend_prediction niet positief (neural). Trade geblokkeerd.',
                'trend_prediction' => $trend,
                'modernity_score'  => $mod,
                'director_hold'    => false,
                'vm_snippets'      => $snip,
            ];
        }

        if ($directorHold) {
            $this->logVeto($trend, $mod, $snip, 'director_heuristic');
            $sync->appendFeedbackQueue('director_hold_heuristic', [
                'trend_prediction' => $trend,
                'side'             => $proposal['side'] ?? '',
            ]);

            return [
                'pass'         => false,
                'reason'       => 'Director-sync: principes (heuristiek) → HOLD.',
                'trend_prediction' => $trend,
                'modernity_score'  => $mod,
                'director_hold'    => true,
                'vm_snippets'      => $snip,
            ];
        }

        $mode = (string) ($proposal['mode'] ?? 'paper');
        $llmHold = $mode === 'live'
            ? $this->directorStrategistLlm($sync, $proposal, $scores, $snip, $skill)
            : false;
        if ($llmHold) {
            $this->logVeto($trend, $mod, $snip, 'director_strategist_llm');
            $sync->appendFeedbackQueue('director_hold_llm', [
                'trend_prediction' => $trend,
                'side'             => $proposal['side'] ?? '',
            ]);

            return [
                'pass'         => false,
                'reason'       => 'Strategist + Director: HOLD (LLM alignment).',
                'trend_prediction' => $trend,
                'modernity_score'  => $mod,
                'director_hold'    => true,
                'vm_snippets'      => $snip,
            ];
        }

        $logger = new ReasoningLogger($this->basePath);
        $logger->writeStep([
            'step'    => 'strategist_gate',
            'agent'   => 'Strategist',
            'icon'    => '📈',
            'summary' => sprintf('Strategist OK: trend=%.4f modernity=%.4f (model %s)', $trend, $mod, (string) ($scores['model'] ?? '')),
            'status'  => 'ok',
            'persona' => 'strategist',
            'data'    => ['trend_prediction' => $trend, 'modernity_score' => $mod, 'vm_chars' => strlen($snip)],
        ]);

        return [
            'pass'         => true,
            'reason'       => 'Strategist: trend positief; Director alignment geen HOLD.',
            'trend_prediction' => $trend,
            'modernity_score'  => $mod,
            'director_hold'    => false,
            'vm_snippets'      => $snip,
        ];
    }

    /**
     * @param list<array{text: string, stored_at: string, meta: array<string, mixed>}> $recent
     */
    private function formatVmSnippets(array $recent): string
    {
        $lines = [];
        foreach ($recent as $row) {
            $t = trim((string) ($row['text'] ?? ''));
            if ($t === '') {
                continue;
            }
            $lines[] = substr($t, 0, 400);
        }

        return implode("\n---\n", $lines);
    }

    /**
     * @param array<string, mixed> $proposal
     *
     * @return list<float>
     */
    public function featureVectorFromProposal(array $proposal): array
    {
        $sig   = (array) ($proposal['signal'] ?? []);
        $rsi   = (float) ($proposal['rsi'] ?? $sig['rsi'] ?? 50.0);
        $rsi15 = (float) ($sig['rsi_15m'] ?? $rsi);
        $str   = (int) ($sig['strength'] ?? 0);

        return [
            (float) ($proposal['price_eur'] ?? 0.0),
            0.0,
            0.0,
            (float) ($proposal['sentiment'] ?? 0.0),
            $rsi / 100.0,
            $rsi15 / 100.0,
            min(1.0, max(0.0, $str / 100.0)),
            0.0,
            0.0,
        ];
    }

    /**
     * @param array<string, mixed> $scores
     * @param array<string, mixed> $skill
     */
    private function directorStrategistLlm(
        DirectorSyncContext $sync,
        array $proposal,
        array $scores,
        string $vmSnippets,
        array $skill
    ): bool {
        if ($this->container === null) {
            return false;
        }

        $cfg = $this->container->get('config');
        if ($cfg === null) {
            return false;
        }

        $apiKey = EvolutionProviderKeys::openAi($cfg, false);
        if ($apiKey === '') {
            return false;
        }

        $principles = $sync->principlesSummary();
        $slice      = [
            'side'       => $proposal['side'] ?? '',
            'price_eur'  => $proposal['price_eur'] ?? 0,
            'sentiment'  => $proposal['sentiment'] ?? 0,
            'rsi'        => $proposal['rsi'] ?? ($proposal['signal']['rsi'] ?? 0),
            'strength'   => $proposal['signal']['strength'] ?? 0,
        ];

        $user = "Director principles (hoogste prioriteit):\n{$principles}\n\n"
            . 'Neural scores: ' . json_encode($scores, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "trading_nn (recent, verkort):\n" . ($vmSnippets !== '' ? $vmSnippets : '(leeg)') . "\n\n"
            . 'Proposal: ' . json_encode($slice, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "Als iets in strijd is met DR-01..DR-04 of known_feedback_history: eerste regel HOLD.\n"
            . "Anders: eerste regel ALLOW.\n"
            . "Tweede regel: alleen Caveman (max 12 woorden).";

        $system = (string) ($cfg->get('agents.agents.strategist.system_prompt') ?? 'Je bent Strategist.');

        try {
            $llm    = new LlmClient($this->container);
            $result = $llm->callRole('strategist', $system, $user);
            $text   = strtoupper(trim((string) ($result['content'] ?? '')));
            $first  = trim((string) (strtok($text, "\n") ?: $text));
            if ($first === '') {
                return true;
            }
            if (str_starts_with($first, 'ALLOW')) {
                return false;
            }

            return str_starts_with($first, 'HOLD');
        } catch (\Throwable) {
            return true;
        }
    }

    private function logVeto(float $trend, float $mod, string $snip, string $kind): void
    {
        $logger = new ReasoningLogger($this->basePath);
        $logger->writeStep([
            'step'    => 'strategist_gate_' . $kind,
            'agent'   => 'Strategist',
            'icon'    => '🛑',
            'summary' => sprintf('Strategist VETO (%s) trend=%.4f modernity=%.4f', $kind, $trend, $mod),
            'status'  => 'veto',
            'persona' => 'strategist',
            'data'    => ['vm_preview' => substr($snip, 0, 200)],
        ]);
    }

    /**
     * Context pack fragment for DeepReasoningService (no container).
     *
     * @param array<string, mixed> $proposal
     *
     * @return array{trend_prediction: float, modernity_score: float, vm_snippets: string, scores: array<string, mixed>}
     */
    public static function snapshotForDeepReasoning(string $basePath, array $proposal): array
    {
        $gate = new self($basePath, null);
        $vm   = new VectorMemoryService(self::VM_NS, rtrim($basePath, '/\\') . '/storage/evolution/vector_memory');
        $snip = '';
        foreach ($vm->recentEntries(8) as $row) {
            $t = trim((string) ($row['text'] ?? ''));
            if ($t !== '') {
                $snip .= substr($t, 0, 320) . "\n---\n";
            }
        }
        $predict = new TradingPredictor($basePath);
        $feat    = $gate->featureVectorFromProposal($proposal);
        $scores  = $predict->predictScores($feat);

        return [
            'trend_prediction' => (float) ($scores['trend_prediction'] ?? 0.0),
            'modernity_score'  => (float) ($scores['modernity_score'] ?? 0.0),
            'vm_snippets'      => trim($snip),
            'scores'           => $scores,
        ];
    }
}
