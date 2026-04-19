<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use Psr\Container\ContainerInterface;

/**
 * AgentToolbox — Orchestreert de analyse-tools voor HIGH CONFIDENCE trade-validatie.
 *
 * Tools:
 *   - WhaleWatcher     : Scant walvis-bewegingen op Base (bearish = VETO)
 *   - SimulationSandbox: Monte Carlo 1000 scenario's (< 80% winstkans = VETO)
 *   - GoogleSearchScout: Gemini (+ optionele Google Search) voor live nieuwscontext
 *
 * Vereist voor Standard Tier (saldo >= €100) bij elke live BUY.
 * Resultaat wordt meegegeven aan Sonnet DeepAnalysis voor context.
 */
final class AgentToolbox
{
    private string  $basePath;
    private ?string $basescanApiKey;
    private ?ContainerInterface $container;

    public function __construct(?string $basePath = null, ?string $basescanApiKey = null, ?ContainerInterface $container = null)
    {
        $this->basePath       = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->basescanApiKey = $basescanApiKey ?? trim((string)(getenv('BASESCAN_API_KEY') ?: ''));
        $this->container      = $container;
    }

    /**
     * Voer volledige toolbox-analyse uit voor een trade-voorstel.
     *
     * @param  array $proposal   Trade-voorstel (bevat rsi, price, signal etc.)
     * @param  array $history    Uurlijkse prijshistorie [{ts, price}]
     * @return array{ok: bool, veto: bool, veto_reason: string,
     *               whale: array, simulation: array, google_scout: array, summary: string}
     */
    public function analyze(array $proposal, array $history = []): array
    {
        $price  = (float)($proposal['price_eur'] ?? $proposal['price'] ?? 0.0);
        $side   = (string)($proposal['side']     ?? 'BUY');

        // ── WhaleWatcher ──────────────────────────────────────────────────
        $whale = (new WhaleWatcher($this->basePath, $this->basescanApiKey))->analyze($price);

        // ── SimulationSandbox ─────────────────────────────────────────────
        $sim = (new SimulationSandbox())->simulate(
            $price,
            $history,
            $side,
            1000,
            240
        );

        // ── GoogleSearchScout (Gemini + optionele Google Search) ──────────
        $scout = ['ok' => true, 'summary' => '', 'verdict' => 'SKIP', 'skipped' => true, 'cost_eur' => 0.0];
        if ($this->container !== null) {
            try {
                $q = sprintf(
                    'Recent news and market sentiment for Ethereum ETH and Base L2, trade side %s, spot ~€%.2f. Any major risk headlines?',
                    $side,
                    $price
                );
                $scout = (new GoogleSearchScout($this->container))->verifyLiveNews($q);
            } catch (\Throwable) {
            }
        }

        // ── Combineer verdict ─────────────────────────────────────────────
        $veto       = false;
        $vetoReason = '';

        if (!$whale['ok']) {
            $veto       = true;
            $vetoReason = $whale['reason'];
        } elseif (!$sim['ok']) {
            $veto       = true;
            $vetoReason = $sim['reason'];
        } elseif (isset($scout['verdict']) && $scout['verdict'] === 'RISK' && empty($scout['skipped'])) {
            $veto       = true;
            $vetoReason = 'GoogleSearchScout: elevated risk in live news context';
        }

        $summary = sprintf(
            'Toolbox: Whale=%s | MC=%.0f%% winstkans | Scout=%s | %s',
            $whale['verdict'],
            ($sim['win_rate'] ?? 0) * 100,
            (string)($scout['verdict'] ?? '—'),
            $veto ? '⛔ VETO' : '✅ OK'
        );

        return [
            'ok'           => !$veto,
            'veto'         => $veto,
            'veto_reason'  => $vetoReason,
            'whale'        => $whale,
            'simulation'   => $sim,
            'google_scout' => $scout,
            'summary'      => $summary,
        ];
    }
}
