<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * TradeMemoryService — Ervarings-geheugen voor de trading agent.
 *
 * Werking:
 *   1. Sla bij elke tick (BUY/SELL/HOLD) de RSI, sentiment, koers en signaal op.
 *   2. ~1 uur later: update de entry met de werkelijke prijsbeweging (outcome).
 *   3. Validator raadpleegt geheugen: "Wat gebeurde de vorige N keer dat RSI ≈ X en
 *      sentiment ≈ Y was?" → als ≥70% verlies → VETO aanbevolen.
 *
 * Opslag:
 *   storage/evolution/trading/trade_memories.jsonl   (max 500 entries)
 *   storage/evolution/trading/pending_outcomes.json  (wachtrij voor 1h update)
 */
final class TradeMemoryService
{
    private const MEMORIES_FILE    = 'storage/evolution/trading/trade_memories.jsonl';
    private const PENDING_FILE     = 'storage/evolution/trading/pending_outcomes.json';
    private const MAX_MEMORIES     = 500;
    private const RSI_WINDOW       = 5.0;   // Vergelijk RSI binnen ±5
    private const SENTIMENT_WINDOW = 0.40;  // Vergelijk sentiment binnen ±0.40
    private const MIN_OUTCOMES     = 3;     // Minimum matches voor patroon-oordeel
    private const VETO_LOSS_RATE   = 0.70;  // ≥70% verlies → VETO aanbevolen
    private const OUTCOME_MIN_AGE  = 3000;  // 50 minuten
    private const OUTCOME_MAX_AGE  = 7200;  // 2 uur

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Sla een trading-moment op voor toekomstig leren.
     * Aanroepen VÓÓR de trade-beslissing.
     *
     * @param array{rsi: float, rsi_15m?: float, sentiment: float, signal: string,
     *               strength: int, price: float, trend: string, action: string} $context
     * @return string  Experience-ID (voor latere outcome-update)
     */
    public function recordMoment(array $context): string
    {
        $id    = uniqid('tm_', true);
        $entry = [
            'id'             => $id,
            'ts'             => time(),
            'date'           => date('c'),
            'rsi'            => round((float)($context['rsi']        ?? 0), 2),
            'rsi_15m'        => round((float)($context['rsi_15m']    ?? 0), 2),
            'sentiment'      => round((float)($context['sentiment']  ?? 0), 3),
            'signal'         => (string)($context['signal']          ?? 'HOLD'),
            'signal_strength'=> (int)($context['strength']           ?? 0),
            'price'          => round((float)($context['price']      ?? 0), 2),
            'trend'          => (string)($context['trend']           ?? 'FLAT'),
            'action'         => (string)($context['action']          ?? 'HOLD'),
            'outcome_pct'    => null,
            'outcome_ts'     => null,
            'price_1h'       => null,
        ];

        $pending       = $this->loadPending();
        $pending[$id]  = ['price_at_record' => $entry['price'], 'ts' => $entry['ts']];
        $this->savePending($pending);

        $dir = dirname($this->memoriesPath());
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($this->memoriesPath(), json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

        return $id;
    }

    /**
     * Verwerk uitkomsten voor entries die ~1 uur geleden zijn opgeslagen.
     * Aanroepen aan het begin van elke tick.
     */
    public function resolvePendingOutcomes(float $currentPrice): int
    {
        $pending  = $this->loadPending();
        if (empty($pending)) {
            return 0;
        }

        $now      = time();
        $resolved = [];

        foreach ($pending as $id => $meta) {
            $age = $now - (int)($meta['ts'] ?? 0);
            if ($age < self::OUTCOME_MIN_AGE || $age > self::OUTCOME_MAX_AGE) {
                continue;
            }
            $priceAt = (float)($meta['price_at_record'] ?? 0);
            if ($priceAt > 0 && $currentPrice > 0) {
                $pct = ($currentPrice - $priceAt) / $priceAt * 100;
                $this->updateMemoryOutcome($id, $currentPrice, $pct);
                $resolved[] = $id;
            }
        }

        foreach ($resolved as $id) {
            unset($pending[$id]);
        }
        $this->savePending($pending);

        return count($resolved);
    }

    /**
     * Raadpleeg geheugen: hoe liepen vergelijkbare situaties af?
     *
     * @param  float  $rsi
     * @param  float  $sentiment
     * @param  string $signal     'BUY'|'SELL'|'HOLD'
     * @return array{count: int, win_rate: float, avg_pct: float, veto_recommended: bool, reason: string}
     */
    public function queryPattern(float $rsi, float $sentiment, string $signal = 'BUY'): array
    {
        $memories = $this->loadMemories();
        $matches  = [];

        foreach ($memories as $m) {
            if ($m['outcome_pct'] === null) {
                continue;
            }
            if (($m['signal'] ?? '') !== $signal) {
                continue;
            }
            if (abs(($m['rsi'] ?? 0) - $rsi) > self::RSI_WINDOW) {
                continue;
            }
            if (abs(($m['sentiment'] ?? 0) - $sentiment) > self::SENTIMENT_WINDOW) {
                continue;
            }
            $matches[] = $m;
        }

        $count = count($matches);
        if ($count < self::MIN_OUTCOMES) {
            return [
                'count'            => $count,
                'win_rate'         => 0.0,
                'avg_pct'          => 0.0,
                'veto_recommended' => false,
                'reason'           => sprintf('Geheugen: %d vergelijkbare situaties — te weinig voor patroon (min %d)', $count, self::MIN_OUTCOMES),
            ];
        }

        $wins   = 0;
        $sumPct = 0.0;
        foreach ($matches as $m) {
            $pct = (float)$m['outcome_pct'];
            $sumPct += $pct;
            if ($pct > 0.0) {
                $wins++;
            }
        }

        $winRate  = $wins / $count;
        $lossRate = 1.0 - $winRate;
        $avgPct   = $sumPct / $count;
        $veto     = $lossRate >= self::VETO_LOSS_RATE;

        $reason = sprintf(
            'Geheugen: %d vergelijkbare situaties (RSI≈%.0f, sent≈%.2f) — %.0f%% winst, gem. %+.2f%% na 1u',
            $count, $rsi, $sentiment, $winRate * 100, $avgPct
        );
        if ($veto) {
            $reason .= sprintf(' ⛔ VETO (%.0f%% verlies-rate)', $lossRate * 100);
        }

        return [
            'count'            => $count,
            'win_rate'         => round($winRate, 3),
            'avg_pct'          => round($avgPct, 3),
            'veto_recommended' => $veto,
            'reason'           => $reason,
        ];
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    private function updateMemoryOutcome(string $id, float $priceNow, float $pricePct): void
    {
        $file = $this->memoriesPath();
        if (!is_file($file)) {
            return;
        }

        $lines   = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $updated = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && ($entry['id'] ?? '') === $id) {
                $entry['outcome_pct'] = round($pricePct, 4);
                $entry['outcome_ts']  = date('c');
                $entry['price_1h']    = round($priceNow, 2);
            }
            $updated[] = json_encode($entry);
        }

        if (count($updated) > self::MAX_MEMORIES) {
            $updated = array_slice($updated, -self::MAX_MEMORIES);
        }

        file_put_contents($file, implode("\n", $updated) . "\n", LOCK_EX);
    }

    private function loadMemories(): array
    {
        $file = $this->memoriesPath();
        if (!is_file($file)) {
            return [];
        }
        $lines  = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $result = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    private function loadPending(): array
    {
        $file = $this->basePath . '/' . self::PENDING_FILE;
        if (!is_file($file)) {
            return [];
        }
        return json_decode((string)file_get_contents($file), true) ?? [];
    }

    private function savePending(array $data): void
    {
        $file = $this->basePath . '/' . self::PENDING_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function memoriesPath(): string
    {
        return $this->basePath . '/' . self::MEMORIES_FILE;
    }
}
