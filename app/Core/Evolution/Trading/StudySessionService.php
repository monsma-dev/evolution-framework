<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use Psr\Container\ContainerInterface;

/**
 * StudySessionService — AI-powered analyse van handelservaringen.
 *
 * Werking (STUDYING state):
 *   1. Laad de laatste 7 dagen trades uit TradeMemoryService.
 *   2. Groepeer op winstgevend / verliesgevend + context (RSI, sentiment).
 *   3. Stuur batch naar Claude Sonnet voor patroon-analyse.
 *   4. Sla inzichten op in study_insights.json.
 *   5. TradingValidatorAgent injecteert deze inzichten als context.
 *
 * Opslag: storage/evolution/trading/study_insights.json
 */
final class StudySessionService
{
    private const INSIGHTS_FILE = 'storage/evolution/trading/study_insights.json';
    private const MAX_MEMORIES  = 50; // Max memories per sessie (kostenbeheer)

    private ?ContainerInterface $container;
    private string              $basePath;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null)
    {
        $this->container = $container;
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Voer een studie-sessie uit als de state STUDYING is.
     * Markeert de sessie daarna als compleet via AgentStateManager.
     */
    public function runIfNeeded(AgentStateManager $stateManager): array
    {
        if ($stateManager->currentState() !== AgentStateManager::STATE_STUDYING) {
            return ['skipped' => true, 'reason' => 'Niet in STUDYING state'];
        }

        $result = $this->run();
        $stateManager->markStudyComplete();
        return $result;
    }

    /**
     * Voer de studie-sessie altijd uit (ook bij handmatig aanroepen).
     *
     * @return array{ok: bool, insights_count: int, cost_eur: float, summary: string}
     */
    public function run(): array
    {
        $memory    = new TradeMemoryService($this->basePath);
        $memories  = $this->loadRecentMemories($memory);

        if (count($memories) < 3) {
            return [
                'ok'             => false,
                'insights_count' => 0,
                'cost_eur'       => 0.0,
                'summary'        => 'Te weinig herinneringen voor analyse (min 3)',
            ];
        }

        $profitable = array_filter($memories, fn($m) => ($m['outcome_pct'] ?? 0) > 0);
        $losing     = array_filter($memories, fn($m) => ($m['outcome_pct'] ?? 0) < 0);

        $context = $this->buildContext($memories, array_values($profitable), array_values($losing));

        if ($this->container === null) {
            return [
                'ok'             => false,
                'insights_count' => 0,
                'cost_eur'       => 0.0,
                'summary'        => 'Geen AI container — lokale studie-samenvatting opgeslagen',
            ];
        }

        try {
            $llm   = new \App\Domain\AI\LlmClient($this->container);
            $model = ModelSelectorService::MODEL_SONNET;

            $prompt = "Je bent een kwantitatieve trading-analist. Analyseer deze handelsdata van de afgelopen week:\n\n"
                    . $context . "\n\n"
                    . "Geef PRECIES deze output:\n"
                    . "PATROON_1: [beschrijf het meest winstgevende RSI/sentiment patroon]\n"
                    . "PATROON_2: [beschrijf het meest verliesgevende patroon om te vermijden]\n"
                    . "HEURISTIEK_1: [concrete regel om te implementeren, bijv. 'Niet kopen als RSI<35 EN sentiment<-0.2']\n"
                    . "HEURISTIEK_2: [tweede concrete handelingsregel]\n"
                    . "SCORE_VERBETERING: [verwachte win-rate verbetering als % als we de heuristieken volgen]\n"
                    . "AANBEVELING: [één alinea met de belangrijkste les van deze week]";

            $result  = $llm->callModel($model, 'Je bent een kwantitatieve trading-analist.', $prompt);
            $content = (string)($result['content'] ?? '');
            $cost    = (float)($result['cost_eur'] ?? 0.01);

            (new ModelSelectorService($this->basePath))->recordCost($model, $cost);

            $insights = $this->parseInsights($content);
            $this->saveInsights($insights);

            // ── Architect Feedback Loop ──────────────────────────────────
            $observerCost    = 0.0;
            $observerSummary = '';
            try {
                $observer       = new \App\Core\Evolution\Intelligence\ArchitectObserver($this->container, $this->basePath);
                $observerResult = $observer->run();
                $observerCost   = (float)($observerResult['cost_eur'] ?? 0.0);
                if (($observerResult['new_classes'] ?? 0) + ($observerResult['new_methods'] ?? 0) > 0) {
                    $observerSummary = ' | Observer: ' . $observerResult['summary'];
                }
            } catch (\Throwable) {
            }

            // ── Self-Optimization (Auto-Tune Engine) ──────────────────────
            $optimizerCost    = 0.0;
            $optimizerSummary = '';
            try {
                $optimizer       = new \App\Core\Evolution\Intelligence\StrategyOptimizer($this->container, $this->basePath);
                $optimizerResult = $optimizer->run();
                $optimizerCost   = (float)($optimizerResult['cost_eur'] ?? 0.0);
                if ($optimizerResult['ok'] ?? false) {
                    $optimizerSummary = ' | Optimizer: ' . $optimizerResult['summary'];
                }
            } catch (\Throwable) {
            }

            return [
                'ok'             => true,
                'insights_count' => count($insights),
                'cost_eur'       => round($cost + $observerCost + $optimizerCost, 4),
                'summary'        => 'Studie voltooid: ' . count($insights) . ' inzichten opgeslagen' . $observerSummary . $optimizerSummary,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'             => false,
                'insights_count' => 0,
                'cost_eur'       => 0.0,
                'summary'        => 'AI-fout tijdens studie: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Laad de meest recente study inzichten (voor gebruik in validator prompt).
     *
     * @return array<string>  Lijst van heuristiek-regels
     */
    public function loadInsights(): array
    {
        $file = $this->basePath . '/' . self::INSIGHTS_FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return (array)($data['heuristics'] ?? []);
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    private function loadRecentMemories(TradeMemoryService $memory): array
    {
        $file = $this->basePath . '/data/evolution/trading/trade_memories.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $lines   = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoff  = time() - 7 * 86400;
        $result  = [];

        foreach (array_slice($lines, -self::MAX_MEMORIES) as $line) {
            $m = json_decode($line, true);
            if (!is_array($m) || ($m['ts'] ?? 0) < $cutoff) {
                continue;
            }
            if ($m['outcome_pct'] === null) {
                continue;
            }
            $result[] = $m;
        }
        return $result;
    }

    private function buildContext(array $all, array $profitable, array $losing): string
    {
        $lines = [];
        $lines[] = sprintf(
            'Totaal: %d afgeronde trades | %d winstgevend (%.0f%%) | %d verliesgevend (%.0f%%)',
            count($all),
            count($profitable), count($all) > 0 ? count($profitable) / count($all) * 100 : 0,
            count($losing), count($all) > 0 ? count($losing) / count($all) * 100 : 0
        );

        if (!empty($profitable)) {
            $lines[] = "\nTOP WINSTGEVENDE PATRONEN:";
            foreach (array_slice($profitable, 0, 5) as $m) {
                $lines[] = sprintf(
                    '  RSI=%.1f sentiment=%.2f trend=%s → +%.2f%% na 1u',
                    $m['rsi'] ?? 0, $m['sentiment'] ?? 0, $m['trend'] ?? '?', $m['outcome_pct']
                );
            }
        }

        if (!empty($losing)) {
            $lines[] = "\nTOP VERLIESGEVENDE PATRONEN:";
            foreach (array_slice($losing, 0, 5) as $m) {
                $lines[] = sprintf(
                    '  RSI=%.1f sentiment=%.2f trend=%s → %.2f%% na 1u',
                    $m['rsi'] ?? 0, $m['sentiment'] ?? 0, $m['trend'] ?? '?', $m['outcome_pct']
                );
            }
        }

        return implode("\n", $lines);
    }

    private function parseInsights(string $content): array
    {
        $insights = [];
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^(HEURISTIEK_\d+|PATROON_\d+|AANBEVELING):\s*(.+)/i', trim($line), $m)) {
                $insights[] = trim($m[2]);
            }
        }
        return array_filter($insights);
    }

    private function saveInsights(array $insights): void
    {
        $file = $this->basePath . '/' . self::INSIGHTS_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode([
            'heuristics'   => array_values($insights),
            'generated_at' => date('c'),
        ], JSON_PRETTY_PRINT), LOCK_EX);
    }
}
