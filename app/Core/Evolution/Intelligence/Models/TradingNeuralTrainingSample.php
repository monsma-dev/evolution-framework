<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence\Models;

/**
 * One labelled feature row for Vector Memory + future Rindow training export.
 *
 * @phpstan-type SampleShape array{
 *   ts: string,
 *   price_eur: float,
 *   volume_24h_eur: float,
 *   gas_gwei: float|null,
 *   sentiment: float,
 *   rsi: float,
 *   rsi_15m: float,
 *   signal: string,
 *   signal_strength: int,
 *   trend: string,
 *   chain_id: int,
 *   mode: string,
 *   pnl_total_eur: float,
 *   roi_pct: float
 * }
 */
final class TradingNeuralTrainingSample
{
    /** @param SampleShape $data */
    public function __construct(private array $data)
    {
    }

    /** @return SampleShape */
    public function toArray(): array
    {
        return $this->data;
    }

    /** Vervang gas (bijv. L1 vs Base) voor inferentie zonder volledige rebuild. */
    public function withGasGwei(?float $gasGwei): self
    {
        $d = $this->data;
        $d['gas_gwei'] = $gasGwei;

        return new self($d);
    }

    /**
     * Human + TF-IDF friendly line for VectorMemoryService (semantic search over training history).
     */
    public function toVectorMemoryText(): string
    {
        $d = $this->data;

        return sprintf(
            'trading_nn sample ts=%s price=%.4f EUR vol24h=%.0f EUR gas=%s gwei sentiment=%.4f rsi=%.2f rsi15m=%.2f signal=%s strength=%d trend=%s chain=%s mode=%s pnl=%.2f EUR roi=%.2f%%',
            $d['ts'],
            $d['price_eur'],
            $d['volume_24h_eur'],
            $d['gas_gwei'] === null ? 'null' : sprintf('%.4f', $d['gas_gwei']),
            $d['sentiment'],
            $d['rsi'],
            $d['rsi_15m'],
            $d['signal'],
            $d['signal_strength'],
            $d['trend'],
            (string) $d['chain_id'],
            $d['mode'],
            $d['pnl_total_eur'],
            $d['roi_pct']
        );
    }

    /**
     * Normalised feature vector for future Rindow NDArray import (order stable).
     *
     * @return list<float>
     */
    public function featureVector(): array
    {
        $d = $this->data;

        return [
            $d['price_eur'],
            $d['volume_24h_eur'],
            (float) ($d['gas_gwei'] ?? 0.0),
            $d['sentiment'],
            $d['rsi'] / 100.0,
            $d['rsi_15m'] / 100.0,
            min(1.0, max(0.0, $d['signal_strength'] / 100.0)),
            $d['pnl_total_eur'],
            $d['roi_pct'],
        ];
    }
}
