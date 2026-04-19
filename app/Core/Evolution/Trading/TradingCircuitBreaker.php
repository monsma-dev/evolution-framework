<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * TradingCircuitBreaker — Automatische noodstop voor de trading module.
 *
 * Triggers (een van de drie breekt het circuit):
 *   - Verlies > max_loss_pct_1h (standaard 10%) binnen 1 uur
 *   - Verlies > max_loss_pct_24h (standaard 20%) binnen 24 uur
 *   - Meer dan max_failed_trades_streak opeenvolgende verlies-trades
 *
 * Lock: storage/evolution/trading/TRADING_PAUSE.lock
 * Reset: handmatig verwijderen van het lock-bestand door admin
 *
 * Integreert met EVOLUTION_PAUSE.lock: als trading gepauzeerd is,
 * rapporteert de agent alleen nog maar — geen uitvoering.
 */
final class TradingCircuitBreaker
{
    private const TRADING_LOCK    = 'storage/evolution/trading/TRADING_PAUSE.lock';
    private const EVOLUTION_LOCK  = 'storage/evolution/EVOLUTION_PAUSE.lock';

    private string $basePath;
    private float  $maxLoss1hPct;
    private float  $maxLoss24hPct;
    private int    $maxLosingStreak;

    public function __construct(array $config = [], ?string $basePath = null)
    {
        $this->basePath        = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->maxLoss1hPct    = (float)($config['max_loss_pct_1h']      ?? 10.0);
        $this->maxLoss24hPct   = (float)($config['max_loss_pct_24h']     ?? 20.0);
        $this->maxLosingStreak = (int)($config['max_losing_streak']      ?? 4);
    }

    /** Is de trading module gepauzeerd? */
    public function isPaused(): bool
    {
        return is_file($this->basePath . '/' . self::TRADING_LOCK);
    }

    /** Lees de reden van de pauze. */
    public function pauseReason(): string
    {
        $lockFile = $this->basePath . '/' . self::TRADING_LOCK;
        if (!is_file($lockFile)) {
            return '';
        }
        $data = json_decode((string)file_get_contents($lockFile), true);
        return (string)($data['reason'] ?? 'Onbekende reden');
    }

    /**
     * Evalueer na elke trade of het circuit moet breken.
     * Gooit geen exception — logt stil en legt lock aan als nodig.
     */
    public function evaluate(TradingLedger $ledger, float $currentPrice): array
    {
        if ($this->isPaused()) {
            return ['tripped' => true, 'reason' => $this->pauseReason()];
        }

        $trades = $ledger->allTrades(50);
        if (empty($trades)) {
            return ['tripped' => false];
        }

        // Check 1h verlies
        $loss1h = $this->calcLossPct($trades, $currentPrice, 3600);
        if ($loss1h >= $this->maxLoss1hPct) {
            return $this->trip(sprintf(
                'Verlies van %.2f%% in het laatste uur (limiet %.2f%%)',
                $loss1h, $this->maxLoss1hPct
            ));
        }

        // Check 24h verlies
        $loss24h = $this->calcLossPct($trades, $currentPrice, 86400);
        if ($loss24h >= $this->maxLoss24hPct) {
            return $this->trip(sprintf(
                'Verlies van %.2f%% in de laatste 24 uur (limiet %.2f%%)',
                $loss24h, $this->maxLoss24hPct
            ));
        }

        // Check opeenvolgende verlies-trades
        $streak = $this->calcLosingStreak($trades, $currentPrice);
        if ($streak >= $this->maxLosingStreak) {
            return $this->trip(sprintf(
                '%d opeenvolgende verlies-trades (limiet %d)',
                $streak, $this->maxLosingStreak
            ));
        }

        return ['tripped' => false, 'loss_1h' => $loss1h, 'loss_24h' => $loss24h, 'streak' => $streak];
    }

    /** Handmatige reset door admin (verwijdert lock-bestand). */
    public function reset(string $adminNote = ''): void
    {
        $lockFile = $this->basePath . '/' . self::TRADING_LOCK;
        if (is_file($lockFile)) {
            unlink($lockFile);
        }
        $this->appendAuditLog('CIRCUIT_RESET', $adminNote ?: 'Handmatige reset door admin');
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function trip(string $reason): array
    {
        $lock = [
            'reason'     => $reason,
            'tripped_at' => date('c'),
            'auto'       => true,
        ];
        file_put_contents(
            $this->basePath . '/' . self::TRADING_LOCK,
            json_encode($lock, JSON_PRETTY_PRINT),
            LOCK_EX
        );
        $this->appendAuditLog('CIRCUIT_BREAKER_TRIP', $reason);
        return ['tripped' => true, 'reason' => $reason];
    }

    private function calcLossPct(array $trades, float $currentPrice, int $windowSec): float
    {
        $cutoff = time() - $windowSec;
        $pnl    = 0.0;
        $invested = 0.0;

        foreach ($trades as $t) {
            if ((int)$t['ts'] < $cutoff) {
                continue;
            }
            if ($t['side'] === 'BUY') {
                $invested += (float)$t['value_eur'];
                $pnl      -= (float)$t['value_eur'];
            } else {
                $pnl      += (float)$t['value_eur'];
            }
        }

        if ($invested <= 0) {
            return 0.0;
        }
        return $pnl < 0 ? (abs($pnl) / $invested) * 100 : 0.0;
    }

    private function calcLosingStreak(array $trades, float $currentPrice): int
    {
        $streak    = 0;
        $openBuys  = [];

        // Walk oldest-first
        $sorted = array_reverse($trades);
        foreach ($sorted as $t) {
            if ($t['side'] === 'BUY') {
                $openBuys[] = ['price' => (float)$t['price_eur'], 'eth' => (float)$t['amount_eth']];
            } elseif ($t['side'] === 'SELL' && !empty($openBuys)) {
                $buy = array_shift($openBuys);
                if ((float)$t['price_eur'] < $buy['price']) {
                    $streak++;
                } else {
                    $streak = 0;
                }
            }
        }
        return $streak;
    }

    private function appendAuditLog(string $event, string $detail): void
    {
        $line = json_encode(['ts' => date('c'), 'event' => $event, 'detail' => $detail]) . "\n";
        $dir  = $this->basePath . '/data/evolution/trading';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($dir . '/circuit_audit.log', $line, FILE_APPEND | LOCK_EX);
    }
}
