<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

use App\Core\Evolution\Trading\ReasoningLogger;
use App\Core\Evolution\Trading\StudySessionService;
use Psr\Container\ContainerInterface;

/**
 * TradeRetrospective — Zelf-correctie na elke gesloten trade.
 *
 * Werking:
 *   1. Wordt aangeroepen direct na een SELL-trade.
 *   2. Laadt de Reasoning Feed voor die trade (alle redeneer-stappen).
 *   3. Vergelijkt: "Wat dachten we vs. wat is er daadwerkelijk gebeurd?"
 *   4. Laat Haiku (snel, goedkoop) een les formuleren: "Mijn aanname over X was fout
 *      in deze marktconditie omdat Y. Advies voor volgende keer: Z."
 *   5. Schrijft de les direct naar study_insights.json (meteen bruikbaar,
 *      ook zonder nachtelijke studie-sessie).
 *   6. Logt de retrospectie naar de Live Reasoning Feed.
 *
 * Kosten: ~€0.001 per trade (Haiku). Geen vertraging voor de trade loop.
 *
 * Opslag:
 *   storage/evolution/trading/study_insights.json         (directe heuristieken)
 *   storage/evolution/intelligence/retrospectives.jsonl   (volledige geschiedenis)
 */
final class TradeRetrospective
{
    private const RETROSPECTIVES_FILE  = 'storage/evolution/intelligence/retrospectives.jsonl';
    private const INSIGHTS_FILE        = 'storage/evolution/trading/study_insights.json';
    private const MAX_INSIGHTS         = 30; // Maximaal in het actieve geheugen

    private ?ContainerInterface $container;
    private string $basePath;

    public function __construct(?ContainerInterface $container, string $basePath)
    {
        $this->container = $container;
        $this->basePath  = $basePath;
    }

    /**
     * Voer een directe retrospectie uit na een gesloten trade.
     *
     * @param string $tradeId    ID van de gesloten trade
     * @param string $side       'BUY' of 'SELL'
     * @param float  $pnlEur     Gerealiseerde P&L in EUR (positief = winst)
     * @param float  $priceEur   Uitvoerprijs
     * @param array  $signal     Het signaal dat de trade triggerde
     * @return array{ok: bool, lesson: string, cost_eur: float}
     */
    public function run(string $tradeId, string $side, float $pnlEur, float $priceEur, array $signal): array
    {
        // Alleen retrospectie na een SELL (positie gesloten)
        if (strtoupper($side) !== 'SELL') {
            return ['ok' => false, 'lesson' => '', 'cost_eur' => 0.0];
        }

        $logger        = new ReasoningLogger($this->basePath);
        $reasoningSteps = $logger->forTrade($tradeId);

        // Bouw context voor de retrospectie
        $outcomeLabel = $pnlEur >= 0.01 ? 'WINST' : ($pnlEur <= -0.01 ? 'VERLIES' : 'FLAT');
        $reasoningText = $this->summarizeReasoning($reasoningSteps);

        $lesson = $this->extractLesson($reasoningText, $pnlEur, $outcomeLabel, $signal);

        if ($lesson['lesson'] !== '') {
            $this->appendInsight($lesson['lesson'], $pnlEur, $tradeId);
            $logger->writeStep([
                'trade_id' => $tradeId,
                'step'     => 'retrospective',
                'agent'    => 'Architect',
                'icon'     => '🔄',
                'summary'  => sprintf('Retrospectie [%s €%+.2f]: %s',
                    $outcomeLabel, $pnlEur, $lesson['lesson']),
                'status'   => $pnlEur >= 0 ? 'ok' : 'warn',
                'persona'  => 'architect',
                'data'     => ['pnl_eur' => $pnlEur, 'outcome' => $outcomeLabel],
            ]);
        }

        return [
            'ok'       => $lesson['lesson'] !== '',
            'lesson'   => $lesson['lesson'],
            'cost_eur' => $lesson['cost_eur'],
        ];
    }

    // ── Interne methoden ──────────────────────────────────────────────────

    /** Vat de reasoning-stappen samen in leesbare tekst. */
    private function summarizeReasoning(array $steps): string
    {
        if (empty($steps)) {
            return 'Geen reasoning-data beschikbaar voor deze trade.';
        }

        $lines = [];
        foreach ($steps as $step) {
            $lines[] = sprintf('[%s] %s %s',
                $step['step']    ?? '?',
                $step['icon']    ?? '',
                $step['summary'] ?? ''
            );
        }

        return implode("\n", array_slice($lines, 0, 20)); // Max 20 stappen
    }

    /**
     * Laat Haiku een les formuleren op basis van de reasoning vs. de uitkomst.
     *
     * @return array{lesson: string, cost_eur: float}
     */
    private function extractLesson(string $reasoning, float $pnlEur, string $outcome, array $signal): array
    {
        if ($this->container === null) {
            return ['lesson' => $this->fallbackLesson($pnlEur, $signal), 'cost_eur' => 0.0];
        }

        try {
            $rsi       = $signal['rsi']       ?? $signal['signal']['rsi'] ?? 'N/A';
            $sentiment = $signal['sentiment'] ?? 'N/A';
            $strength  = $signal['strength']  ?? $signal['signal']['strength'] ?? 'N/A';

            $prompt = <<<PROMPT
Je analyseert een afgesloten crypto-trade voor zelfstudie.

TRADE UITKOMST: {$outcome} — P&L: €{$pnlEur}
RSI bij trade: {$rsi} | Sentiment: {$sentiment} | Signaalsterkte: {$strength}%

REASONING CHAIN (wat de agent dacht):
{$reasoning}

Schrijf PRECIES ÉÉN les in dit formaat:
"[Conclusie over fout of succes in max 20 woorden. Advies voor volgende keer in max 15 woorden.]"

Voorbeeld verlies: "RSI 42 was te hoog bij bearish markt. Wacht op RSI <38 bij negatief sentiment."
Voorbeeld winst: "RSI 36 + bullish whale = sterk signaal. Bevestig altijd whale data voor BUY."

Schrijf alleen de les, geen extra tekst.
PROMPT;

            $llm    = new \App\Domain\AI\LlmClient($this->container);
            $result = $llm->callModel(
                'claude-haiku-4-5',
                'Je bent een senior trading analyst die lessen trekt uit afgesloten trades.',
                $prompt
            );
            $text   = trim((string)($result['content'] ?? ''));
            $cost   = (float)($result['cost_eur'] ?? 0.001);

            // Strip aanhalingstekens als model die toevoegt
            $text = trim($text, '"\'');

            return ['lesson' => $text ?: $this->fallbackLesson($pnlEur, $signal), 'cost_eur' => $cost];
        } catch (\Throwable) {
            return ['lesson' => $this->fallbackLesson($pnlEur, $signal), 'cost_eur' => 0.0];
        }
    }

    /** Heuristieke les zonder AI (fallback bij storing). */
    private function fallbackLesson(float $pnlEur, array $signal): string
    {
        $rsi    = (float)($signal['rsi'] ?? $signal['signal']['rsi'] ?? 50);
        $trend  = (string)($signal['trend'] ?? 'FLAT');
        $result = $pnlEur >= 0 ? 'winstgevend' : 'verliesgevend';

        return sprintf(
            'Trade %s: RSI=%.0f, trend=%s. P&L=€%+.2f. Evalueer RSI-trend coherentie.',
            $result, $rsi, $trend, $pnlEur
        );
    }

    /** Voeg les toe aan study_insights.json (meteen beschikbaar voor DeepReasoningService). */
    private function appendInsight(string $lesson, float $pnlEur, string $tradeId): void
    {
        $file     = $this->basePath . '/' . self::INSIGHTS_FILE;
        $insights = [];

        if (is_file($file)) {
            $existing = json_decode((string)file_get_contents($file), true);
            if (is_array($existing)) {
                $insights = $existing;
            }
        }

        // Voeg toe aan het begin (meest recent eerst)
        array_unshift($insights, sprintf(
            '[Retrospectie %s P&L=€%+.2f] %s',
            date('Y-m-d H:i'),
            $pnlEur,
            $lesson
        ));

        // Begrens
        $insights = array_slice($insights, 0, self::MAX_INSIGHTS);

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($file, json_encode($insights, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        // Ook opslaan in de volledige retrospectie-historie
        $histFile = $this->basePath . '/' . self::RETROSPECTIVES_FILE;
        $histDir  = dirname($histFile);
        if (!is_dir($histDir)) {
            @mkdir($histDir, 0750, true);
        }
        @file_put_contents(
            $histFile,
            json_encode([
                'ts'       => date('c'),
                'trade_id' => $tradeId,
                'pnl_eur'  => $pnlEur,
                'lesson'   => $lesson,
            ], JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
