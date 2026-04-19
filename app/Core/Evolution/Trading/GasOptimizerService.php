<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Evolution\Wallet\BaseRpcService;

/**
 * GasOptimizerService — Wacht met een trade tot de gasprijs op zijn laagste punt zit.
 *
 * Werking:
 *   - Slaat elke tick de huidige gasprijs (Gwei) op in een rolling 1-uur buffer.
 *   - Keurt een trade goed als huidige gas <= MAX_GAS_RATIO × uurgemiddelde (default 150%).
 *   - Op Base is gas standaard goedkoop (~0.001 Gwei), maar fluctueert bij drukte.
 *
 * Opslag: storage/evolution/trading/gas_prices.json
 *
 * Tip: laagste gas op Base is typisch vroeg in de ochtend (UTC 02-06h) en
 * kort na een blok-grens wanneer de mempool leeg is.
 */
final class GasOptimizerService
{
    private const GAS_HISTORY_FILE    = 'storage/evolution/trading/gas_prices.json';
    private const HISTORY_WINDOW_SECS = 3600;  // 1 uur
    private const MAX_GAS_RATIO       = 1.50;  // Max 150% van uurgemiddelde
    private const MAX_HISTORY_ENTRIES = 360;   // max ~1 meting per 10s over 1 uur

    private BaseRpcService $rpc;
    private string         $basePath;

    public function __construct(?string $basePath = null, ?BaseRpcService $rpc = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->rpc      = $rpc ?? BaseRpcService::forTradingFromEvolutionJson($this->basePath);
    }

    /**
     * Controleer of de huidige gasprijs acceptabel is voor een live trade.
     * Slaat de meting automatisch op in de geschiedenis.
     *
     * @return array{ok: bool, current_gwei: float, avg_gwei: float, ratio: float, reason: string}
     */
    public function check(): array
    {
        $currentGwei = $this->rpc->gasPrice(); // Gwei (float)
        $this->recordGasPrice($currentGwei);
        $avgGwei = $this->getHourlyAvgGwei();

        if ($avgGwei <= 0.0) {
            return [
                'ok'           => true,
                'current_gwei' => $currentGwei,
                'avg_gwei'     => 0.0,
                'ratio'        => 1.0,
                'reason'       => 'Geen gas-historie — trade direct goedgekeurd',
            ];
        }

        $ratio = $currentGwei / $avgGwei;
        $ok    = $ratio <= self::MAX_GAS_RATIO;

        return [
            'ok'           => $ok,
            'current_gwei' => round($currentGwei, 6),
            'avg_gwei'     => round($avgGwei, 6),
            'ratio'        => round($ratio, 3),
            'reason'       => $ok
                ? sprintf(
                    'Gas OK: %.6f Gwei (%.0f%% van uurgemiddelde %.6f Gwei)',
                    $currentGwei, $ratio * 100, $avgGwei
                )
                : sprintf(
                    'Gas te hoog: %.6f Gwei (%.0f%% van uurgemiddelde) — trade uitgesteld',
                    $currentGwei, $ratio * 100
                ),
        ];
    }

    /**
     * Huidig uurgemiddelde in Gwei (voor gebruik in logging/Telegram).
     */
    public function getHourlyAvgGwei(): float
    {
        $history = $this->loadHistory();
        if (empty($history)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($history as $h) {
            $sum += (float)($h['gwei'] ?? 0);
        }
        return $sum / count($history);
    }

    /**
     * Laagste gasprijs van het afgelopen uur in Gwei.
     */
    public function getHourlyMinGwei(): float
    {
        $history = $this->loadHistory();
        if (empty($history)) {
            return 0.0;
        }
        $values = array_column($history, 'gwei');
        return (float)min($values);
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    private function recordGasPrice(float $gwei): void
    {
        if ($gwei <= 0.0) {
            return;
        }

        $history = $this->loadHistory();
        $now     = time();

        $history[] = ['ts' => $now, 'gwei' => round($gwei, 8)];

        // Bewaar alleen laatste uur
        $history = array_values(array_filter(
            $history,
            fn(array $h) => ($now - (int)($h['ts'] ?? 0)) <= self::HISTORY_WINDOW_SECS
        ));

        // Cap op max entries
        if (count($history) > self::MAX_HISTORY_ENTRIES) {
            $history = array_slice($history, -self::MAX_HISTORY_ENTRIES);
        }

        $file = $this->historyPath();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode($history), LOCK_EX);
    }

    private function loadHistory(): array
    {
        $file = $this->historyPath();
        if (!is_file($file)) {
            return [];
        }
        return json_decode((string)file_get_contents($file), true) ?? [];
    }

    private function historyPath(): string
    {
        return $this->basePath . '/' . self::GAS_HISTORY_FILE;
    }
}
