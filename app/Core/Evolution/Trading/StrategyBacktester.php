<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use Psr\Container\ContainerInterface;

/**
 * StrategyBacktester — Wekelijkse simulatie van alternatieve RSI-drempels.
 *
 * Werking:
 *   1. Laadt de trade_memories.jsonl van de afgelopen 7 dagen.
 *   2. Simuleert elke RSI-drempel uit het testbereik (35–45) tegen de historische data.
 *   3. Vergelijkt: "Hoeveel extra trades/winst had ik gemaakt met drempel X?"
 *   4. Evalueert de beste kandidaat via Claude Haiku (goedkoop).
 *   5. Stuurt Telegram-advies via de Architect-persona.
 *
 * Aanroep: eenmaal per week vanuit StudySessionService of via /backtest Telegram-commando.
 *
 * Opslag resultaat: storage/evolution/trading/backtest_results.json
 */
final class StrategyBacktester
{
    private const MEMORIES_FILE  = 'storage/evolution/trading/trade_memories.jsonl';
    private const RESULTS_FILE   = 'storage/evolution/trading/backtest_results.json';
    private const RSI_TEST_RANGE = [35, 37, 38, 39, 40, 41, 42, 43, 45];
    private const MIN_MEMORIES   = 5;
    private const MODEL_HAIKU    = 'claude-3-5-haiku-20241022';

    private ?ContainerInterface $container;
    private string              $basePath;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null)
    {
        $this->container = $container;
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Voer een volledige backtest-ronde uit.
     *
     * @return array{ok: bool, best_rsi: float, current_rsi: float, improvement_pct: float, cost_eur: float, summary: string, advice: string}
     */
    public function run(): array
    {
        $memories = $this->loadMemories(7);

        if (count($memories) < self::MIN_MEMORIES) {
            return [
                'ok'              => false,
                'best_rsi'        => 0.0,
                'current_rsi'     => 0.0,
                'improvement_pct' => 0.0,
                'cost_eur'        => 0.0,
                'summary'         => 'Te weinig trade-herinneringen voor backtest (min ' . self::MIN_MEMORIES . ').',
                'advice'          => '',
            ];
        }

        $currentRsi = $this->loadCurrentRsiThreshold();
        $results    = [];

        foreach (self::RSI_TEST_RANGE as $testRsi) {
            $results[$testRsi] = $this->simulateRsi((float)$testRsi, $memories);
        }

        // Huidige drempel als baseline
        $baseline = $this->simulateRsi($currentRsi, $memories);
        $best     = $this->findBest($results);
        $bestRsi  = (float)$best['rsi'];
        $impPct   = $baseline['win_rate'] > 0
            ? round(($best['win_rate'] - $baseline['win_rate']) / $baseline['win_rate'] * 100, 1)
            : 0.0;

        // AI advies via Haiku
        $adviceResult  = $this->generateAdvice($currentRsi, $bestRsi, $baseline, $best, $memories);
        $advice        = $adviceResult['message'];
        $cost          = $adviceResult['cost_eur'];

        // Sla resultaten op
        $resultData = [
            'ts'          => date('c'),
            'current_rsi' => $currentRsi,
            'best_rsi'    => $bestRsi,
            'improvement_pct' => $impPct,
            'baseline'    => $baseline,
            'best'        => $best,
            'all_results' => $results,
            'advice'      => $advice,
        ];
        $this->saveResults($resultData);

        // Telegram notificatie via Architect-persona
        if ($advice !== '') {
            $this->notifyTelegram($advice, $currentRsi, $bestRsi, $impPct);
        }

        return [
            'ok'              => true,
            'best_rsi'        => $bestRsi,
            'current_rsi'     => $currentRsi,
            'improvement_pct' => $impPct,
            'cost_eur'        => round($cost, 4),
            'summary'         => "Backtest voltooid: beste RSI-drempel is {$bestRsi} ({$impPct}% beter dan huidig {$currentRsi})",
            'advice'          => $advice,
        ];
    }

    /**
     * Haal het laatste backtest-resultaat op (voor het dashboard).
     */
    public function lastResult(): ?array
    {
        $path = $this->basePath . '/' . self::RESULTS_FILE;
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    // ── Simulatie ────────────────────────────────────────────────────────

    /**
     * Simuleer het resultaat van een specifieke RSI-drempel op historische data.
     *
     * @param list<array<string, mixed>> $memories
     * @return array{rsi: float, total_trades: int, wins: int, losses: int, win_rate: float, simulated_pnl_pct: float}
     */
    private function simulateRsi(float $rsiThreshold, array $memories): array
    {
        $trades = 0;
        $wins   = 0;
        $losses = 0;
        $pnl    = 0.0;

        foreach ($memories as $m) {
            $entryRsi = (float)($m['rsi_at_entry']  ?? 50.0);
            $outcome  = (float)($m['outcome_pct']    ?? 0.0);
            $side     = strtoupper((string)($m['side'] ?? 'BUY'));

            if ($side !== 'BUY') {
                continue;
            }

            // Simuleer: had je deze trade gemaakt met $rsiThreshold?
            $wouldHaveBought = $entryRsi <= $rsiThreshold;

            if ($wouldHaveBought) {
                $trades++;
                if ($outcome > 0) {
                    $wins++;
                } elseif ($outcome < 0) {
                    $losses++;
                }
                $pnl += $outcome;
            }
        }

        $winRate = $trades > 0 ? round($wins / $trades * 100, 1) : 0.0;

        return [
            'rsi'              => $rsiThreshold,
            'total_trades'     => $trades,
            'wins'             => $wins,
            'losses'           => $losses,
            'win_rate'         => $winRate,
            'simulated_pnl_pct'=> round($pnl, 2),
        ];
    }

    /**
     * Vind de RSI-drempel met de hoogste win-rate (bij gelijkspel: minste verlies).
     */
    private function findBest(array $results): array
    {
        $best = null;

        foreach ($results as $rsi => $data) {
            if ($data['total_trades'] < 2) {
                continue; // Te weinig data om te vertrouwen
            }
            if ($best === null
                || $data['win_rate'] > $best['win_rate']
                || ($data['win_rate'] === $best['win_rate'] && $data['simulated_pnl_pct'] > $best['simulated_pnl_pct'])
            ) {
                $best = $data;
            }
        }

        return $best ?? ['rsi' => 40.0, 'total_trades' => 0, 'wins' => 0, 'losses' => 0, 'win_rate' => 0.0, 'simulated_pnl_pct' => 0.0];
    }

    // ── AI Advies ────────────────────────────────────────────────────────

    /**
     * @return array{message: string, cost_eur: float}
     */
    private function generateAdvice(
        float $currentRsi,
        float $bestRsi,
        array $baseline,
        array $best,
        array $memories
    ): array {
        if ($this->container === null) {
            return ['message' => '', 'cost_eur' => 0.0];
        }

        try {
            $llm = new \App\Domain\AI\LlmClient($this->container);

            $prompt = "Je bent een AI-handelsagent die een wekelijkse backtest heeft uitgevoerd. Schrijf een persoonlijk Telegram-advies aan je Architect.\n\n"
                    . "HUIDIGE RSI-DREMPEL: {$currentRsi}\n"
                    . "Resultaat huidig: " . $baseline['total_trades'] . " trades, " . $baseline['win_rate'] . "% win-rate, " . $baseline['simulated_pnl_pct'] . "% gesimuleerd P&L\n\n"
                    . "BESTE KANDIDAAT: RSI-drempel {$bestRsi}\n"
                    . "Resultaat beste: " . $best['total_trades'] . " trades, " . $best['win_rate'] . "% win-rate, " . $best['simulated_pnl_pct'] . "% gesimuleerd P&L\n\n"
                    . "Aantal geanalyseerde herinneringen: " . count($memories) . " trades van de afgelopen 7 dagen.\n\n"
                    . "Schrijf een advies in max 4 zinnen (formaat: begin met 'Architect,'). Wees specifiek met getallen. "
                    . "Als het verschil klein is (<2%), wees dan voorzichtig met aanbevelen. "
                    . "Gebruik emoji's. Schrijf in het Nederlands.";

            $result = $llm->callModel(self::MODEL_HAIKU, 'Je bent een kwantitatieve trading-analist die advies geeft aan de Architect.', $prompt);

            return [
                'message'  => trim((string)($result['content'] ?? '')),
                'cost_eur' => (float)($result['cost_eur'] ?? 0.001),
            ];
        } catch (\Throwable) {
            return ['message' => '', 'cost_eur' => 0.0];
        }
    }

    // ── Telegram ─────────────────────────────────────────────────────────

    private function notifyTelegram(string $advice, float $currentRsi, float $bestRsi, float $impPct): void
    {
        $token  = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
        $chatId = trim((string)(getenv('TELEGRAM_CHAT_ID') ?: ''));
        if ($token === '' || $chatId === '') {
            return;
        }

        $persona = AgentPersonality::architect();
        $arrow   = $bestRsi < $currentRsi ? '⬇️' : ($bestRsi > $currentRsi ? '⬆️' : '↔️');
        $impSign = $impPct >= 0 ? '+' : '';

        $header = "📈 <b>Wekelijkse Strategy Backtest</b>\n\n"
                . "Huidige RSI-drempel: <code>{$currentRsi}</code>\n"
                . "Beste gevonden: <code>{$bestRsi}</code> {$arrow} (<b>{$impSign}{$impPct}%</b> win-rate)\n\n"
                . "<b>Agent advies:</b>\n";

        $body = json_encode([
            'chat_id'                  => $chatId,
            'text'                     => $persona->rawFormat(
                $header . htmlspecialchars($advice, ENT_QUOTES, 'UTF-8', false),
                'Strategy Backtest'
            ),
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if ($body === false) {
            return;
        }

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', rawurlencode($token));
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($body),
            'content'       => $body,
            'timeout'       => 6,
            'ignore_errors' => true,
        ]]);

        @file_get_contents($url, false, $ctx);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function loadCurrentRsiThreshold(): float
    {
        $file = $this->basePath . '/config/evolution.json';
        if (!is_file($file)) {
            return 40.0;
        }
        $cfg = json_decode((string)file_get_contents($file), true);
        return (float)(($cfg['trading']['strategy']['rsi_buy'] ?? null) ?? 40.0);
    }

    /** @return list<array<string, mixed>> */
    private function loadMemories(int $daysBack = 7): array
    {
        $file   = $this->basePath . '/' . self::MEMORIES_FILE;
        if (!is_file($file)) {
            return [];
        }

        $cutoff = time() - $daysBack * 86400;
        $lines  = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $m = json_decode((string)$line, true);
            if (!is_array($m)) {
                continue;
            }
            $ts = is_numeric($m['ts'] ?? null) ? (int)$m['ts'] : strtotime((string)($m['ts'] ?? ''));
            if ($ts < $cutoff) {
                continue;
            }
            if ($m['outcome_pct'] === null) {
                continue; // Trade nog niet afgesloten
            }
            $result[] = $m;
        }

        return $result;
    }

    private function saveResults(array $data): void
    {
        $path = $this->basePath . '/' . self::RESULTS_FILE;
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }
}
