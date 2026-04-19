<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

use App\Core\Evolution\Intelligence\Models\TradingPredictor;
use App\Core\Evolution\Trading\FutureSightService;
use App\Core\Evolution\Trading\TradingService;

/**
 * Bouwt neuron-gewichten + layer-graaf voor de Neural Map (TradingPredictor + Future-Sight).
 */
final class NeuralBrainMapService
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<string, mixed> $tradingConfig evolution.trading
     * @return array{
     *   neurons: list<array{id:string,label_key:string,activation:float,weight_pct:float,color:string}>,
     *   layers: list<array{id:string,label_key:string,description_key:string}>,
     *   edges: list<array{from:string,to:string,signal:float}>,
     *   predictor: array<string, mixed>,
     *   future_sight: array<string, mixed>|null,
     *   ts: string
     * }
     */
    public function buildSnapshot(TradingService $trading, array $tradingConfig): array
    {
        $status    = $trading->status();
        $collector = new TradingNeuralDataCollector($this->basePath);
        $sample    = $collector->buildSample($status);

        $chainId = (int) ($status['chain_id'] ?? 8453);
        if ($chainId === 1) {
            try {
                $evm = (array) ($tradingConfig['evm'] ?? []);
                $rpcOverride = trim((string) ($evm['rpc_url'] ?? ''));
                $rpcUrl      = ($rpcOverride !== '' && str_starts_with($rpcOverride, 'http'))
                    ? $rpcOverride
                    : null;
                $ethRpc = new \App\Core\Evolution\Wallet\MultiChainRpcService($this->basePath, $rpcUrl, 'ethereum');
                $sample = $sample->withGasGwei($ethRpc->gasPrice());
            } catch (\Throwable) {
            }
        }

        $features  = $sample->featureVector();
        $predictor = new TradingPredictor($this->basePath);
        $scores    = $predictor->predictScores($features);

        $sig      = (array) ($status['signal'] ?? []);
        $rsi      = (float) ($sig['rsi'] ?? 50.0);
        $strength = (int) ($sig['strength'] ?? 0);
        $vol      = (float) ($sample->toArray()['volume_24h_eur'] ?? 0.0);
        $gas      = $sample->toArray()['gas_gwei'];
        $gasF     = $gas !== null ? (float) $gas : 0.0;

        $tp = (float) ($scores['trend_prediction'] ?? 0.0);

        // Ruwe gewichten (0–1), daarna genormaliseerd naar %
        $wRsi   = min(1.0, abs($rsi - 50.0) / 50.0) * 0.35 + ($strength / 100.0) * 0.15;
        $wTrend = min(1.0, (abs($tp) + 1.0) / 2.0) * 0.45;
        $wVol   = min(1.0, log10(1.0 + max(0.0, $vol) / 1e6) / 3.0);
        $wGas   = min(1.0, $gasF / max(1.0, $gasF + 20.0));

        $raw = [$wRsi, $wTrend, $wVol, $wGas];
        $sum = array_sum($raw) ?: 1.0;
        $pct = array_map(static fn (float $x): float => round(100.0 * $x / $sum, 1), $raw);

        $neurons = [
            [
                'id'          => 'rsi',
                'label_key'   => 'admin.evolution_brain.neuron_rsi',
                'activation'  => round(min(1.0, abs($rsi - 50.0) / 50.0), 3),
                'weight_pct'  => $pct[0],
                'color'       => 'emerald',
            ],
            [
                'id'          => 'trend',
                'label_key'   => 'admin.evolution_brain.neuron_trend',
                'activation'  => round(min(1.0, (abs($tp) + 1.0) / 2.0), 3),
                'weight_pct'  => $pct[1],
                'color'       => 'indigo',
            ],
            [
                'id'          => 'volume',
                'label_key'   => 'admin.evolution_brain.neuron_volume',
                'activation'  => round($wVol, 3),
                'weight_pct'  => $pct[2],
                'color'       => 'amber',
            ],
            [
                'id'          => 'gas',
                'label_key'   => 'admin.evolution_brain.neuron_gas',
                'activation'  => round($wGas, 3),
                'weight_pct'  => $pct[3],
                'color'       => 'rose',
            ],
        ];

        $layers = [
            ['id' => 'input', 'label_key' => 'admin.evolution_brain.layer_input', 'description_key' => 'admin.evolution_brain.layer_input_desc'],
            ['id' => 'hidden', 'label_key' => 'admin.evolution_brain.layer_hidden', 'description_key' => 'admin.evolution_brain.layer_hidden_desc'],
            ['id' => 'strategist', 'label_key' => 'admin.evolution_brain.layer_strategist', 'description_key' => 'admin.evolution_brain.layer_strategist_desc'],
            ['id' => 'future_sight', 'label_key' => 'admin.evolution_brain.layer_future_sight', 'description_key' => 'admin.evolution_brain.layer_future_sight_desc'],
        ];

        $edges = [
            ['from' => 'input', 'to' => 'hidden', 'signal' => min(1.0, $strength / 100.0)],
            ['from' => 'hidden', 'to' => 'strategist', 'signal' => min(1.0, max(0.0, (float) ($scores['modernity_score'] ?? 0.0)))],
            ['from' => 'strategist', 'to' => 'future_sight', 'signal' => min(1.0, (abs($tp) + 1.0) / 2.0)],
        ];

        $fsOut = null;
        try {
            $fs      = new FutureSightService($this->basePath, $tradingConfig);
            $fsOut   = $fs->run($trading);
        } catch (\Throwable) {
            $fsOut = null;
        }

        $multiAgent = $this->buildMultiAgentVisual(
            $rsi,
            $strength,
            $tp,
            $sig,
            $fsOut,
            (int) ($status['chain_id'] ?? 8453),
            (float) ($status['ethereum_mainnet_balance'] ?? 0),
            (float) ($status['base_balance_probe'] ?? 0)
        );

        return [
            'neurons'     => $neurons,
            'layers'      => $layers,
            'edges'       => $edges,
            'predictor'   => $scores,
            'future_sight'=> $fsOut,
            'status'      => [
                'chain_id'   => $status['chain_id'] ?? null,
                'signal'     => $sig,
                'price_eur'  => $status['price_eur'] ?? null,
            ],
            'agent_clusters' => $multiAgent['agent_clusters'],
            'visual_state'   => $multiAgent['visual_state'],
            'agent_log'      => $multiAgent['agent_log'],
            'bit_stream'     => $multiAgent['bit_stream'],
            'ts'          => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed>|null $fsOut
     * @return array{
     *   agent_clusters: list<array<string, mixed>>,
     *   visual_state: array<string, mixed>,
     *   agent_log: list<array{agent:string, message_key:string}>,
     *   bit_stream: list<int>
     * }
     */
    private function buildMultiAgentVisual(
        float $rsi,
        int $strength,
        float $trendPrediction,
        array $signal,
        ?array $fsOut,
        int $chainId,
        float $ethL1Probe,
        float $baseProbe
    ): array {
        $sigName = strtoupper((string) ($signal['signal'] ?? 'HOLD'));
        $rsiOversold = $rsi < 30.0;
        $rsiCalmBlue   = $rsiOversold || $rsi < 35.0;
        $futureBullish = $trendPrediction > 0.03;
        $dirNl         = (string) ($fsOut['direction_nl'] ?? '');
        if ($dirNl !== '' && (str_contains(strtolower($dirNl), 'omhoog') || str_contains(strtolower($dirNl), 'up'))) {
            $futureBullish = true;
        }
        $frontGlow = min(1.0, max(0.15, (abs($trendPrediction) + 0.5) / 1.8));

        $tradeValidated = in_array($sigName, ['BUY', 'SELL'], true) && $strength >= 28;
        $synapseFvPulse = $tradeValidated || ($strength >= 38 && (($trendPrediction > 0) === true));

        $tick = time();
        $bitStream = [];
        $seed = pack('N', $tick) . pack('N', $chainId) . pack('f', $rsi);
        $h    = hash('sha256', $seed, true);
        for ($i = 0; $i < 48; $i++) {
            $bitStream[] = (ord($h[$i % 32]) & 1) === 1 ? 1 : 0;
        }

        $agentClusters = [
            [
                'id' => 'architect',
                'label_key' => 'admin.evolution_brain.agent_architect',
                'color' => '#d4af37',
                'emissive' => 0.35 + ($strength / 200.0),
                'position' => ['x' => -1.15, 'y' => 0.45, 'z' => 0.2],
            ],
            [
                'id' => 'validator',
                'label_key' => 'admin.evolution_brain.agent_validator',
                'color' => '#22c55e',
                'emissive' => 0.3 + min(0.5, $strength / 120.0),
                'position' => ['x' => 1.15, 'y' => 0.45, 'z' => 0.2],
            ],
            [
                'id' => 'forecaster',
                'label_key' => 'admin.evolution_brain.agent_forecaster',
                'color' => '#3b82f6',
                'emissive' => 0.35 + min(0.45, abs($trendPrediction)),
                'position' => ['x' => 0.0, 'y' => 1.05, 'z' => -0.75],
            ],
            [
                'id' => 'visualizer',
                'label_key' => 'admin.evolution_brain.agent_visualizer',
                'color' => '#a855f7',
                'emissive' => 0.28 + ($frontGlow * 0.35),
                'position' => ['x' => 0.0, 'y' => -0.55, 'z' => 0.65],
            ],
        ];

        $agentLog = [
            ['agent' => 'architect', 'message_key' => 'admin.evolution_brain.log_architect_risk'],
            ['agent' => 'validator', 'message_key' => $chainId === 1
                ? 'admin.evolution_brain.log_validator_gas_eth'
                : 'admin.evolution_brain.log_validator_gas_base'],
            ['agent' => 'forecaster', 'message_key' => 'admin.evolution_brain.log_forecaster_tp'],
            ['agent' => 'visualizer', 'message_key' => 'admin.evolution_brain.log_visualizer_map'],
            ['agent' => 'validator', 'message_key' => 'admin.evolution_brain.log_validator_signal'],
        ];

        return [
            'agent_clusters' => $agentClusters,
            'visual_state' => [
                'rsi' => round($rsi, 2),
                'rsi_oversold' => $rsiOversold,
                'mood' => $rsiCalmBlue ? 'calm_blue' : ($strength > 65 ? 'active' : 'neutral'),
                'calm_blue' => $rsiCalmBlue,
                'future_sight_bullish' => $futureBullish,
                'front_glow' => round($frontGlow, 3),
                'trade_validated_flash' => $tradeValidated,
                'synapse_forecaster_validator' => $synapseFvPulse,
                'chain_id' => $chainId,
                'probe_eth_mainnet' => round($ethL1Probe, 6),
                'probe_base' => round($baseProbe, 6),
            ],
            'agent_log' => $agentLog,
            'bit_stream' => $bitStream,
        ];
    }
}
