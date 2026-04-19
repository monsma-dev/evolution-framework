<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * TradingLedger — Persistent trade log and P&L tracker.
 *
 * Each trade stored as JSONL in storage/evolution/trading/ledger.jsonl
 * Portfolio state stored in storage/evolution/trading/portfolio.json
 */
final class TradingLedger
{
    private const LEDGER_FILE    = 'ledger.jsonl';
    private const PORTFOLIO_FILE = 'portfolio.json';

    private string $dir;

    public function __construct(?string $basePath = null, private readonly ?int $clientId = null)
    {
        $base = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $sub  = $this->clientId !== null ? '/client_' . $this->clientId : '';
        $this->dir = $base . '/data/evolution/trading' . $sub;
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }
    }

    /**
     * Record a trade (paper or real).
     */
    public function record(
        string $side,         // BUY | SELL
        float  $amountEth,
        float  $priceEur,
        string $mode = 'paper',
        string $txHash = '',
        string $reason = ''
    ): array {
        $trade = [
            'id'          => uniqid('trade_', true),
            'ts'          => time(),
            'iso'         => date('c'),
            'side'        => $side,
            'amount_eth'  => round($amountEth, 8),
            'price_eur'   => round($priceEur, 2),
            'value_eur'   => round($amountEth * $priceEur, 4),
            'mode'        => $mode,
            'tx_hash'     => $txHash,
            'reason'      => $reason,
        ];
        if ($this->clientId !== null) {
            $trade['client_id'] = $this->clientId;
        }

        file_put_contents(
            $this->dir . '/' . self::LEDGER_FILE,
            json_encode($trade) . "\n",
            FILE_APPEND | LOCK_EX
        );

        $this->updatePortfolio($trade);
        return $trade;
    }

    /** Load all trades (newest first). */
    public function allTrades(int $limit = 100): array
    {
        $file = $this->dir . '/' . self::LEDGER_FILE;
        if (!is_file($file)) {
            return [];
        }
        $lines  = array_filter(explode("\n", (string)file_get_contents($file)));
        $trades = [];
        foreach ($lines as $line) {
            $t = json_decode($line, true);
            if (is_array($t)) {
                $trades[] = $t;
            }
        }
        return array_slice(array_reverse($trades), 0, $limit);
    }

    /** Current portfolio state. */
    public function portfolio(): array
    {
        $file = $this->dir . '/' . self::PORTFOLIO_FILE;
        if (!is_file($file)) {
            return $this->emptyPortfolio();
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : $this->emptyPortfolio();
    }

    /**
     * Netto EUR-stroom vandaag uit de ledger (SELL +, BUY −), op basis van lokale datum.
     */
    public function todayNetFlowEur(): float
    {
        $today = date('Y-m-d');
        $trades = $this->allTrades(5000);
        $net = 0.0;
        foreach ($trades as $t) {
            $iso = (string)($t['iso'] ?? '');
            if ($iso === '' || substr($iso, 0, 10) !== $today) {
                continue;
            }
            $v = (float)($t['value_eur'] ?? 0);
            if (($t['side'] ?? '') === 'BUY') {
                $net -= $v;
            } else {
                $net += $v;
            }
        }

        return round($net, 2);
    }

    /** P&L summary. */
    public function pnlSummary(float $currentPrice): array
    {
        $trades = $this->allTrades(1000);
        $port   = $this->portfolio();

        $totalInvested = 0.0;
        $totalReceived = 0.0;
        foreach ($trades as $t) {
            if ($t['side'] === 'BUY') {
                $totalInvested += $t['value_eur'];
            } else {
                $totalReceived += $t['value_eur'];
            }
        }

        $currentValueEur = ($port['eth_balance'] ?? 0) * $currentPrice;
        $realised        = $totalReceived - $totalInvested;
        $unrealised      = $currentValueEur - (($port['eth_balance'] ?? 0) * ($port['avg_buy_price'] ?? $currentPrice));
        $totalPnl        = $realised + $unrealised;

        return [
            'trades_count'      => count($trades),
            'eth_balance'       => round($port['eth_balance'] ?? 0, 8),
            'eur_balance'       => round($port['eur_balance'] ?? 0, 4),
            'current_value_eur' => round($currentValueEur, 2),
            'total_invested_eur'=> round($totalInvested, 2),
            'total_received_eur'=> round($totalReceived, 2),
            'realised_pnl_eur'  => round($realised, 4),
            'unrealised_pnl_eur'=> round($unrealised, 4),
            'total_pnl_eur'     => round($totalPnl, 4),
            'roi_pct'           => $totalInvested > 0
                ? round(($totalPnl / $totalInvested) * 100, 2)
                : 0.0,
            'last_updated'      => date('c'),
        ];
    }

    /** Reset portfolio to a starting state (e.g., seed with ETH). */
    public function seed(float $ethAmount, float $currentPrice): void
    {
        $port = [
            'eth_balance'    => $ethAmount,
            'eur_balance'    => 0.0,
            'avg_buy_price'  => $currentPrice,
            'seeded_eth'     => $ethAmount,
            'seeded_at'      => date('c'),
            'updated_at'     => date('c'),
        ];
        file_put_contents($this->dir . '/' . self::PORTFOLIO_FILE, json_encode($port, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function updatePortfolio(array $trade): void
    {
        $port = $this->portfolio();

        if ($trade['side'] === 'BUY') {
            $prevEth   = $port['eth_balance'];
            $newEth    = $prevEth + $trade['amount_eth'];
            $prevAvg   = $port['avg_buy_price'] ?? $trade['price_eur'];
            $port['avg_buy_price'] = $newEth > 0
                ? (($prevEth * $prevAvg) + ($trade['amount_eth'] * $trade['price_eur'])) / $newEth
                : $trade['price_eur'];
            $port['eth_balance']   = $newEth;
            $port['eur_balance']   = max(0, ($port['eur_balance'] ?? 0) - $trade['value_eur']);
        } else {
            $port['eth_balance']   = max(0, ($port['eth_balance'] ?? 0) - $trade['amount_eth']);
            $port['eur_balance']   = ($port['eur_balance'] ?? 0) + $trade['value_eur'];
        }

        $port['last_trade']  = $trade['iso'];
        $port['updated_at']  = date('c');

        file_put_contents($this->dir . '/' . self::PORTFOLIO_FILE, json_encode($port, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function emptyPortfolio(): array
    {
        return [
            'eth_balance'    => 0.0,
            'eur_balance'    => 0.0,
            'avg_buy_price'  => 0.0,
            'seeded_eth'     => 0.0,
            'seeded_at'      => null,
            'updated_at'     => null,
        ];
    }
}
