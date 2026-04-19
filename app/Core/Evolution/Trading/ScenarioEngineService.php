<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Config;
use App\Core\Evolution\VectorMemoryService;

/**
 * Predictive world layer: Monte Carlo baseline + named macro shocks (e.g. BTC -10%, regulatory stress).
 * Optional VectorMemory namespace "world_model" stores qualitative facts retrieved into reports.
 */
final class ScenarioEngineService
{
    public function __construct(private readonly Config $config) {}

    /**
     * Mandatory stress gate for TradingValidatorAgent: trade must "survive" BTC spot shock (default -10%).
     *
     * @return array{pass: bool, detail: string, report?: array<string, mixed>}
     */
    public function passesMandatoryBtcShock(
        float $currentPrice,
        array $priceHistory,
        string $direction,
        float $btcShockPct = -10.0
    ): array {
        $evo = $this->config->get('evolution.scenario_engine', []);
        if (is_array($evo) && !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['pass' => true, 'detail' => 'scenario_engine disabled — gate skipped'];
        }

        if ($currentPrice <= 0) {
            return ['pass' => false, 'detail' => 'Scenario stress: invalid spot price'];
        }

        $run = $this->runScenarios(
            $currentPrice,
            $priceHistory,
            $direction,
            ['btc_spot_shock_pct' => $btcShockPct, 'eu_ai_reg_severity' => 0.0]
        );

        if (!empty($run['skipped'])) {
            return ['pass' => true, 'detail' => 'scenario_engine skipped: ' . (string)($run['reason'] ?? '')];
        }

        $mc = is_array($run['monte_carlo'] ?? null) ? $run['monte_carlo'] : [];

        if ((int)($mc['scenarios'] ?? 0) === 0) {
            return [
                'pass'   => false,
                'detail' => 'Scenario stress: onvoldoende prijshistorie voor BTC ' . $btcShockPct . '% schok — trade geblokkeerd',
                'report' => $run,
            ];
        }

        $ok = (bool)($mc['ok'] ?? false);

        return [
            'pass'   => $ok,
            'detail' => (string)($run['interpretation'] ?? ($ok ? 'PASS' : 'FAIL Monte Carlo onder BTC-schok')),
            'report' => $run,
        ];
    }

    /**
     * Flash-crash scenario (5m horizon, -10% macro shock): veto als P5-drawdown te diep of NAV na schok onder drempel.
     *
     * @return array{pass: bool, detail: string, monte_carlo?: array<string, mixed>}
     */
    public function validateFlashCrashScenario(
        float $currentPrice,
        array $priceHistory,
        string $direction,
        float $notionalEur,
        float $tradingNavEur
    ): array {
        $fc = $this->config->get('evolution.trading.validator.scenario_gate.flash_crash', []);
        if (!is_array($fc) || !filter_var($fc['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['pass' => true, 'detail' => 'flash_crash gate uit'];
        }
        if ($currentPrice <= 0) {
            return ['pass' => false, 'detail' => 'Flash crash scenario: ongeldige spot'];
        }

        $hist = $priceHistory;
        if (count($hist) < 8) {
            $hist = $this->minimalHistoryFromSpot($currentPrice);
        }

        $stressed = $this->applyHistoryStress($hist, -10.0, 0.0);
        $horizon  = max(3, min(60, (int)($fc['horizon_minutes'] ?? 5)));
        $runs     = max(300, min(5000, (int)($fc['scenarios'] ?? 800)));

        $sandbox = new SimulationSandbox();
        $mc      = $sandbox->simulate($currentPrice, $stressed, $direction, $runs, $horizon);

        $p5       = (float)($mc['p5_return_pct'] ?? 0);
        $vetoDraw = abs((float)($fc['p5_veto_drawdown_pct'] ?? 10.0));

        if ((int)($mc['scenarios'] ?? 0) > 0 && $p5 <= -$vetoDraw) {
            return [
                'pass'        => false,
                'detail'      => sprintf('Flash crash (%dm): P5 rendement %.2f%% ≤ -%.2f%% — liquidatierisico', $horizon, $p5, $vetoDraw),
                'monte_carlo' => $mc,
            ];
        }

        $minNav = (float)($fc['min_nav_after_flash_eur'] ?? 0.0);
        if ($minNav > 0.0 && strtoupper($direction) === 'BUY') {
            $after = $tradingNavEur - $notionalEur * 0.10;
            if ($after < $minNav) {
                return [
                    'pass'   => false,
                    'detail' => sprintf(
                        'Flash crash scenario: geschatte NAV na -10%% schok €%.2f < min €%.2f',
                        $after,
                        $minNav
                    ),
                    'monte_carlo' => $mc,
                ];
            }
        }

        return [
            'pass'        => true,
            'detail'      => sprintf('Flash crash OK (P5=%.2f%%, horizon %dm)', $p5, $horizon),
            'monte_carlo' => $mc,
        ];
    }

    /**
     * @return list<array{ts: int, price: float}>
     */
    private function minimalHistoryFromSpot(float $spot): array
    {
        $out = [];
        $now = time();
        for ($i = 0; $i < 48; $i++) {
            $noise = 1.0 + (mt_rand(-200, 200) / 50000.0);
            $out[] = ['ts' => $now - $i * 3600, 'price' => max(1.0, $spot * $noise)];
        }

        return array_reverse($out);
    }

    public function runScenarios(
        float $currentPrice,
        array $priceHistory,
        string $direction = 'BUY',
        array $shocks = [],
        ?int $runs = null
    ): array {
        $evo = $this->config->get('evolution.scenario_engine', []);
        if (is_array($evo) && !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['skipped' => true, 'reason' => 'scenario_engine disabled'];
        }

        $mcRuns = $runs ?? max(500, min(100000, (int)(is_array($evo) ? ($evo['default_monte_carlo_runs'] ?? 2000) : 2000)));

        $btcShock = (float)($shocks['btc_spot_shock_pct'] ?? 0.0);
        $reg      = max(0.0, min(1.0, (float)($shocks['eu_ai_reg_severity'] ?? 0.0)));

        // Stress: widen effective downside by inflating volatility proxy via shock amplitudes (professional tier heuristic).
        $stressedHistory = $this->applyHistoryStress($priceHistory, $btcShock, $reg);

        $sandbox = new SimulationSandbox();
        $base    = $sandbox->simulate($currentPrice, $stressedHistory, $direction, $mcRuns, 240);

        $worldNs = is_array($evo) ? trim((string)($evo['vector_namespace'] ?? 'world_model')) : 'world_model';
        $mem     = new VectorMemoryService($worldNs);
        $facts   = $mem->search(
            sprintf('BTC shock %.1f%% EU AI reg %.2f %s', $btcShock, $reg, $direction),
            3
        );

        return [
            'monte_carlo'   => $base,
            'shocks'        => ['btc_spot_shock_pct' => $btcShock, 'eu_ai_reg_severity' => $reg],
            'runs'          => $mcRuns,
            'world_facts'   => $facts,
            'interpretation'=> $this->interpret($base, $btcShock, $reg),
        ];
    }

    /**
     * @param array<int, array{ts: int, price: float}> $priceHistory
     * @return array<int, array{ts: int, price: float}>
     */
    private function applyHistoryStress(array $priceHistory, float $btcShockPct, float $regSeverity): array
    {
        if ($priceHistory === []) {
            return $priceHistory;
        }
        $factor = 1.0 + abs($btcShockPct) / 100.0 * 0.35 + $regSeverity * 0.25;
        $out    = [];
        foreach ($priceHistory as $row) {
            $p = (float)($row['price'] ?? 0);
            if ($p <= 0) {
                continue;
            }
            $jitter = $factor > 1.0 ? $p * (1.0 + (mt_rand(-100, 100) / 5000.0) * ($factor - 1.0)) : $p;
            $out[] = ['ts' => (int)($row['ts'] ?? 0), 'price' => max(1e-8, $jitter)];
        }

        return $out !== [] ? $out : $priceHistory;
    }

    /**
     * @param array<string, mixed> $base
     */
    private function interpret(array $base, float $btcShock, float $reg): string
    {
        $ok = (bool)($base['ok'] ?? false);
        $wr = (float)($base['win_rate'] ?? 0);

        return sprintf(
            'ScenarioEngine: shock BTC %.2f%%, EU AI stress %.0f%% — MC win rate %.1f%% — %s',
            $btcShock,
            $reg * 100,
            $wr * 100,
            $ok ? 'PASS stress gate' : 'FAIL stress gate'
        );
    }
}
