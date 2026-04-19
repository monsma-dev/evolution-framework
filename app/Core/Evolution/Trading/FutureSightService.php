<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Evolution\Intelligence\Models\TradingPredictor;
use App\Core\Evolution\Intelligence\TradingNeuralDataCollector;

/**
 * Future-Sight — diepe marktscan + 4u richting (heuristiek + TradingPredictor) op de actieve trading-keten.
 */
final class FutureSightService
{
    private const DEFAULT_HORIZON_HOURS = 4;

    /** @param array<string, mixed> $tradingConfig evolution.trading */
    public function __construct(
        private readonly string $basePath,
        private readonly array $tradingConfig = []
    ) {
    }

    /**
     * @return array{
     *   direction_nl: 'STIJGING'|'DALING',
     *   horizon_hours: int,
     *   spot_price_eur: float,
     *   spot_price_usd_approx: float,
     *   reference_eth_usd: float|null,
     *   first_profit_target_pct: float,
     *   chain_id: int,
     *   network_label: string,
     *   scores: array<string, mixed>,
     *   signal: array<string, mixed>,
     *   analysis_lines: list<string>
     * }
     */
    public function run(TradingService $trading): array
    {
        $fs = (array) ($this->tradingConfig['future_sight'] ?? []);

        $horizon = (int) ($fs['horizon_hours'] ?? self::DEFAULT_HORIZON_HOURS);
        if ($horizon < 1) {
            $horizon = self::DEFAULT_HORIZON_HOURS;
        }

        $refUsd = isset($fs['reference_eth_usd']) && is_numeric($fs['reference_eth_usd'])
            ? (float) $fs['reference_eth_usd']
            : null;

        $eurUsd = (float) (getenv('EUR_USD_RATE') ?: '1.085');
        if ($eurUsd <= 0) {
            $eurUsd = 1.085;
        }

        $status = $trading->status();
        $chainId = (int) ($status['chain_id'] ?? 8453);
        $netLab = (string) ($status['network_label'] ?? ($chainId === 1 ? 'Ethereum' : 'Base'));

        $collector = new TradingNeuralDataCollector($this->basePath);
        $sample    = $collector->buildSample($status);

        if ($chainId === 1) {
            try {
                $evm = (array) ($this->tradingConfig['evm'] ?? []);
                $rpcOverride = trim((string) ($evm['rpc_url'] ?? ''));
                $rpcUrl      = ($rpcOverride !== '' && str_starts_with($rpcOverride, 'http'))
                    ? $rpcOverride
                    : null;
                $ethRpc = new \App\Core\Evolution\Wallet\MultiChainRpcService($this->basePath, $rpcUrl, 'ethereum');
                $sample = $sample->withGasGwei($ethRpc->gasPrice());
            } catch (\Throwable) {
            }
        }

        $predictor = new TradingPredictor($this->basePath);
        $scores    = $predictor->predictScores($sample->featureVector());

        $sig       = (array) ($status['signal'] ?? []);
        $sigName   = strtoupper((string) ($sig['signal'] ?? 'HOLD'));
        $trend     = strtoupper((string) ($sig['trend'] ?? 'FLAT'));
        $tpPred    = (float) ($scores['trend_prediction'] ?? 0.0);
        $priceEur  = (float) ($status['price_eur'] ?? 0.0);
        $priceUsd  = $priceEur * $eurUsd;
        $rsi       = (float) ($sig['rsi'] ?? 50.0);

        $direction = $this->resolveDirection($sigName, $trend, $tpPred, $rsi);

        $strat   = (array) ($this->tradingConfig['strategy'] ?? []);
        $tp1     = (float) ($strat['tp_level_1_pct'] ?? 1.5);
        $tt      = (array) ($strat['trailing_tp'] ?? []);
        $firstTp = $tp1 > 0 ? $tp1 : (float) ($tt['level1_profit_pct'] ?? 1.5);

        $lines   = [];
        $lines[] = sprintf('Future-Sight — %s (chain %d)', $netLab, $chainId);
        $lines[] = sprintf('Spot: €%.2f EUR (~$%.2f USD) | Referentie spot (optioneel): %s', $priceEur, $priceUsd, $refUsd !== null ? '$' . number_format($refUsd, 2) : '—');
        $lines[] = sprintf('Horizon: %d uur | RSI: %.1f | Signaal: %s (%s%%) | Trend: %s', $horizon, $rsi, $sigName, (string) ($sig['strength'] ?? '?'), $trend);
        $lines[] = sprintf('Neural/heuristiek: trend_prediction=%.4f | model=%s', $tpPred, (string) ($scores['model'] ?? '?'));
        $lines[] = sprintf('4u marktvoorspelling (richting): %s', $direction);
        $lines[] = sprintf('Eerste winstdoel (strategie): +%.2f%% (tp_level_1 / trailing L1)', $firstTp);
        if ($refUsd !== null && $refUsd > 0) {
            $diffPct = (($priceUsd / $refUsd) - 1.0) * 100.0;
            $lines[] = sprintf('Verschil t.o.v. referentie-ETH ($%.2f): %+.2f%%', $refUsd, $diffPct);
        }

        return [
            'direction_nl'            => $direction,
            'horizon_hours'           => $horizon,
            'spot_price_eur'          => $priceEur,
            'spot_price_usd_approx'   => $priceUsd,
            'reference_eth_usd'       => $refUsd,
            'first_profit_target_pct' => $firstTp,
            'chain_id'                => $chainId,
            'network_label'           => $netLab,
            'scores'                  => $scores,
            'signal'                  => $sig,
            'analysis_lines'          => $lines,
        ];
    }

    /**
     * @return 'STIJGING'|'DALING'
     */
    private function resolveDirection(string $sigName, string $trend, float $tpPred, float $rsi): string
    {
        if ($sigName === 'BUY') {
            return 'STIJGING';
        }
        if ($sigName === 'SELL') {
            return 'DALING';
        }
        if ($tpPred > 0.08) {
            return 'STIJGING';
        }
        if ($tpPred < -0.08) {
            return 'DALING';
        }
        if ($trend === 'UP' || $trend === 'BULL') {
            return 'STIJGING';
        }
        if ($trend === 'DOWN' || $trend === 'BEAR') {
            return 'DALING';
        }

        return $rsi >= 50.0 ? 'STIJGING' : 'DALING';
    }
}
