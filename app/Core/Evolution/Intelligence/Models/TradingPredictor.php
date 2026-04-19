<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence\Models;

/**
 * Strategist / neural trading — haalbaarheid: Rindow Neural Networks (PHP 8.1–8.4, Keras-achtige API).
 *
 * Deze klasse is bewust **optioneel**: zonder `composer require rindow/rindow-neuralnetworks`
 * blijft het framework bruikbaar; inferentie valt terug op heuristieken + Vector Memory patronen.
 *
 * @see docs/evolution-neural-trading-feasibility.md
 */
final class TradingPredictor
{
    private const WEIGHTS_DIR = 'storage/evolution/intelligence/nn_weights';

    public function __construct(private readonly string $basePath)
    {
    }

    public static function rindowAvailable(): bool
    {
        return class_exists(\Rindow\NeuralNetworks\Builder\NeuralNetworks::class);
    }

    /**
     * Placeholder scores until a trained Rindow model + weights ship.
     *
     * @param list<float> $features Same order as {@see TradingNeuralTrainingSample::featureVector()}
     * @return array{modernity_score: float, trend_prediction: float, model: string, note: string}
     */
    public function predictScores(array $features): array
    {
        if (count($features) < 7) {
            return [
                'modernity_score'   => 0.0,
                'trend_prediction'    => 0.0,
                'model'               => 'none',
                'note'                => 'insufficient_features',
            ];
        }

        if (self::rindowAvailable() && is_readable($this->weightsFile())) {
            // Future: load NDArray weights en run forward pass.
            return [
                'modernity_score'   => 0.5,
                'trend_prediction'  => 0.0,
                'model'             => 'rindow_pending',
                'note'              => 'weights_present_but_forward_pass_not_implemented',
            ];
        }

        // Heuristiek: trend_prediction ∈ [-1,1] op basis van genormaliseerde RSI-diff en strength
        $rsiNorm  = $features[4];
        $rsi15    = $features[5];
        $strength = $features[6];
        $trend    = tanh(($rsi15 - $rsiNorm) * 4.0) * 0.7 + ($strength - 0.5) * 0.3;
        $modernity = min(1.0, max(0.0, 0.45 + 0.2 * sin($strength * 3.14159) + 0.1 * $features[3]));

        return [
            'modernity_score'  => round($modernity, 4),
            'trend_prediction' => round(max(-1.0, min(1.0, $trend)), 4),
            'model'            => 'heuristic_v1',
            'note'             => self::rindowAvailable() ? 'rindow_loaded_no_weights' : 'rindow_not_installed',
        ];
    }

    public function weightsFile(): string
    {
        return rtrim($this->basePath, '/\\') . '/' . self::WEIGHTS_DIR . '/trading_predictor.npz.json';
    }
}
