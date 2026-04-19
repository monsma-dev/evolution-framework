<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

use App\Core\Evolution\Trading\TradingService;

/**
 * VisualizerAgent — genereert Neural Brain Map snapshots voor het admin-panel.
 */
final class VisualizerAgent
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<string, mixed> $tradingConfig evolution.trading
     * @return array<string, mixed>
     */
    public function generateBrainMap(TradingService $trading, array $tradingConfig): array
    {
        return (new NeuralBrainMapService($this->basePath))->buildSnapshot($trading, $tradingConfig);
    }
}
