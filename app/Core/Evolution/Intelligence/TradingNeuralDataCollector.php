<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

use App\Core\Evolution\Intelligence\Models\TradingNeuralTrainingSample;
use App\Core\Evolution\VectorMemoryService;
use App\Core\Evolution\Trading\PriceFeedService;

/**
 * Verzamelt trainingsdata (prijs, volume, gas, signalen) bij `evolve:trade status --record`
 * en schrijft naar Vector Memory namespace `trading_nn`.
 */
final class TradingNeuralDataCollector
{
    private const NS = 'trading_nn';

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<string, mixed> $status Resultaat van {@see \App\Core\Evolution\Trading\TradingService::status()}
     */
    public function buildSample(array $status): TradingNeuralTrainingSample
    {
        $priceFeed = new PriceFeedService($this->basePath, []);
        $stats     = $priceFeed->getMarketStats('ethereum', 'eur');
        $vol       = (float) ($stats['volume_24h'] ?? 0.0);

        $sig     = (array) ($status['signal'] ?? []);
        $pnl     = (array) ($status['pnl'] ?? []);
        $gasGwei = $this->fetchBaseGasGwei();

        return new TradingNeuralTrainingSample([
            'ts'                 => (string) ($status['ts'] ?? gmdate('c')),
            'price_eur'          => (float) ($status['price_eur'] ?? 0.0),
            'volume_24h_eur'     => $vol,
            'gas_gwei'           => $gasGwei,
            'sentiment'          => (float) ($status['sentiment_score'] ?? 0.0),
            'rsi'                => (float) ($sig['rsi'] ?? 50.0),
            'rsi_15m'            => (float) ($sig['rsi_15m'] ?? ($sig['rsi'] ?? 50.0)),
            'signal'             => (string) ($sig['signal'] ?? 'HOLD'),
            'signal_strength'    => (int) ($sig['strength'] ?? 0),
            'trend'              => (string) ($sig['trend'] ?? 'FLAT'),
            'chain_id'           => (int) ($status['chain_id'] ?? 8453),
            'mode'               => strtolower((string) ($status['mode'] ?? 'paper')),
            'pnl_total_eur'      => (float) ($pnl['total_pnl_eur'] ?? 0.0),
            'roi_pct'            => (float) ($pnl['roi_pct'] ?? 0.0),
        ]);
    }

    /**
     * @param array<string, mixed> $status Resultaat van {@see \App\Core\Evolution\Trading\TradingService::status()}
     */
    public function recordSample(array $status): bool
    {
        $sample = $this->buildSample($status);

        $mem = new VectorMemoryService(
            self::NS,
            rtrim($this->basePath, '/\\') . '/storage/evolution/vector_memory'
        );

        $meta = [
            'type'   => 'trading_nn_sample',
            'source' => 'evolve:trade_status',
        ];

        return $mem->store($sample->toVectorMemoryText(), $meta);
    }

    private function fetchBaseGasGwei(): ?float
    {
        $cfgPath = $this->basePath . '/config/evolution.json';
        $rpc     = 'https://mainnet.base.org';
        if (is_readable($cfgPath)) {
            $j = json_decode((string) file_get_contents($cfgPath), true);
            if (is_array($j)) {
                $rh = (array) ($j['resource_harvester'] ?? []);
                $r  = trim((string) ($rh['rpc_base'] ?? ''));
                if ($r !== '') {
                    $rpc = $r;
                }
            }
        }

        $payload = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_gasPrice', 'params' => []], JSON_THROW_ON_ERROR);
        $ch      = curl_init($rpc);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            return null;
        }
        $resp = json_decode($raw, true);
        $hex  = is_array($resp) ? (string) ($resp['result'] ?? '') : '';
        if ($hex === '' || !str_starts_with(strtolower($hex), '0x')) {
            return null;
        }
        $wei = hexdec(substr($hex, 2));
        if ($wei <= 0) {
            return null;
        }
        // gwei = wei / 1e9
        return round($wei / 1e9, 6);
    }
}
