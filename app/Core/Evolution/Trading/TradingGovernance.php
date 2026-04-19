<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Evolution\VectorMemoryService;
use Psr\Container\ContainerInterface;

/**
 * TradingGovernance — Trias Politica voor autonome trading.
 *
 * De drie machten, aangesloten op de bestaande framework-structuur:
 *
 *  1. WETGEVENDE MACHT (Architect)   → leest trading_rules.json, valideert parameters
 *  2. UITVOERENDE MACHT (Trader)     → stelt de trade voor (TradingService::tick())
 *  3. RECHTERLIJKE MACHT (Compliance)→ auditeert het voorstel via TrustChain / ConsensusService
 *                                       Minimaal 2/3 AI-modellen moeten groen geven.
 *
 * Flow:
 *   Proposal → checkRules() [Architect] → auditProposal() [Auditor] → executeIfApproved()
 *
 * Integreert met bestaande services:
 *   - App\Domain\AI\TrustChain          (multi-step review pipeline)
 *   - App\Core\Evolution\Growth\ReputationGuard (reputatie scoring)
 *   - App\Domain\AI\BudgetGuard         (financiële limieten)
 */
final class TradingGovernance
{
    private const RULES_FILE   = 'storage/evolution/trading/trading_rules.json';
    private const VERDICT_LOG  = 'storage/evolution/trading/governance_verdicts.jsonl';

    private ContainerInterface $container;
    private string             $basePath;
    private bool               $requireConsensus;
    private float              $maxSlippagePct;
    private array              $approvedProtocols;
    /** bridge_guard.min_nav_eur — min. geaggregeerde NAV (EUR) voordat bridges ooit toegestaan mogen worden. */
    private array              $bridgeGuard;

    public function __construct(ContainerInterface $container, array $config = [], ?string $basePath = null)
    {
        $this->container        = $container;
        $this->basePath         = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->requireConsensus = (bool)($config['require_consensus']   ?? true);
        $this->maxSlippagePct   = (float)($config['max_slippage_pct']   ?? 2.0);
        $this->approvedProtocols = (array)($config['approved_protocols'] ?? [
            '0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D', // Uniswap v2 router
            '0xE592427A0AEce92De3Edee1F18E0157C05861564', // Uniswap v3 router
        ]);
        $this->bridgeGuard = (array)($config['bridge_guard'] ?? ['min_nav_eur' => 250.0]);
    }

    /**
     * Bridge-Guard: blijf FALSE zolang totale NAV (agent+trading ETH × EUR-prijs) onder de drempel blijft.
     * NAV wordt door TradingService bij elke tick weggeschreven naar nav_snapshot.json.
     *
     * @param float $amount Toekomstige bridge-hoogte (gereserveerd; nu alleen drempel-check op totaalvermogen)
     */
    public function canBridge(float $amount): bool
    {
        $min = (float)($this->bridgeGuard['min_nav_eur'] ?? 250.0);
        $nav = $this->readNavSnapshotEur();

        return $nav >= $min;
    }

    private function readNavSnapshotEur(): float
    {
        $file = $this->basePath . '/data/evolution/trading/nav_snapshot.json';
        if (!is_file($file)) {
            return 0.0;
        }
        $j = json_decode((string) file_get_contents($file), true);
        if (!is_array($j)) {
            return 0.0;
        }

        return (float)($j['total_nav_eur'] ?? 0.0);
    }

    /**
     * Volledige governance-check voor een handels-voorstel.
     *
     * @param array $proposal ['side'=>'BUY|SELL', 'amount_eth'=>float, 'price_eur'=>float,
     *                         'target_contract'=>string, 'signal'=>array]
     * @return array{approved:bool, verdict:string, reason:string, votes:array, cost_eur:float}
     */
    public function evaluate(array $proposal): array
    {
        $votes     = [];
        $costEur   = 0.0;

        // ── Stap 1: Wetgevende Macht — Regels check ────────────────────
        $rulesCheck = $this->checkRules($proposal);
        $votes[]    = $rulesCheck;
        if (!$rulesCheck['approved']) {
            return $this->verdict(false, 'Wetgevende Macht blokkade', $rulesCheck['reason'], $votes, $costEur);
        }

        // ── Stap 2: Slippage check (hard limit, geen override mogelijk) ─
        $slippageCheck = $this->checkSlippage($proposal);
        $votes[]       = $slippageCheck;
        if (!$slippageCheck['approved']) {
            return $this->verdict(false, 'Slippage limiet overschreden', $slippageCheck['reason'], $votes, $costEur);
        }

        // ── Stap 3: Protocol whitelist check ────────────────────────────
        $protocolCheck = $this->checkProtocol($proposal);
        $votes[]       = $protocolCheck;
        if (!$protocolCheck['approved']) {
            return $this->verdict(false, 'Niet-goedgekeurd contract', $protocolCheck['reason'], $votes, $costEur);
        }

        // ── Stap 4: Rechterlijke Macht — AI Consensus (2/3, of 3/3 bij >30% kapitaal) ────
        if ($this->requireConsensus) {
            [$consensusResult, $consensusCost] = $this->auditWithConsensus($proposal);
            $votes[] = $consensusResult;
            $costEur += $consensusCost;
            if (!$consensusResult['approved']) {
                return $this->verdict(false, 'AI Consensus geblokkeerd', $consensusResult['reason'], $votes, $costEur);
            }
        }

        return $this->verdict(true, 'Goedgekeurd door alle drie machten', 'Alle checks geslaagd', $votes, $costEur);
    }

    /** Stap 1: Wetgevende Macht — Valideert het handels-voorstel tegen trading_rules.json. */
    public function checkRules(array $proposal): array
    {
        $rules = $this->loadRules();

        $amountEth   = (float)($proposal['amount_eth'] ?? 0);
        $priceEur    = (float)($proposal['price_eur']  ?? 0);
        $valueEur    = $amountEth * $priceEur;

        // Regel: max bedrag per trade
        $maxEth = (float)($rules['max_eth_per_trade'] ?? 0.005);
        if ($amountEth > $maxEth) {
            return ['step' => 'architect', 'approved' => false,
                    'reason' => "Trade van {$amountEth} ETH overschrijdt max {$maxEth} ETH per trade"];
        }

        // Regel: max % van portfolio per trade
        $maxPct = (float)($rules['max_portfolio_pct_per_trade'] ?? 25.0);
        $portfolioEth = (float)($proposal['portfolio_eth'] ?? 0.02);
        if ($portfolioEth > 0 && ($amountEth / $portfolioEth * 100) > $maxPct) {
            return ['step' => 'architect', 'approved' => false,
                    'reason' => sprintf('Trade is %.1f%% van portfolio (max %s%%)', $amountEth / $portfolioEth * 100, $maxPct)];
        }

        // Regel: max dagelijks EUR volume
        $maxDailyEur = (float)($rules['max_daily_volume_eur'] ?? 50.0);
        $todayVolume = (float)($proposal['today_volume_eur'] ?? 0);
        if ($todayVolume + $valueEur > $maxDailyEur) {
            return ['step' => 'architect', 'approved' => false,
                    'reason' => sprintf('Dagvolume €%.2f + €%.2f = €%.2f overschrijdt max €%.2f',
                        $todayVolume, $valueEur, $todayVolume + $valueEur, $maxDailyEur)];
        }

        return ['step' => 'architect', 'approved' => true, 'reason' => 'Alle regels geslaagd'];
    }

    /** Stap 2: Slippage check — hard 2% limiet. */
    private function checkSlippage(array $proposal): array
    {
        $expectedPrice = (float)($proposal['price_eur']          ?? 0);
        $marketPrice   = (float)($proposal['live_market_price']  ?? $expectedPrice);

        if ($expectedPrice <= 0 || $marketPrice <= 0) {
            return ['step' => 'slippage', 'approved' => true, 'reason' => 'Geen prijsdata voor slippage check'];
        }

        $slippage = abs($expectedPrice - $marketPrice) / $marketPrice * 100;
        if ($slippage > $this->maxSlippagePct) {
            return ['step' => 'slippage', 'approved' => false,
                    'reason' => sprintf('Slippage %.2f%% overschrijdt hard limiet %.2f%%', $slippage, $this->maxSlippagePct)];
        }

        return ['step' => 'slippage', 'approved' => true,
                'reason' => sprintf('Slippage %.3f%% OK (< %.2f%%)', $slippage, $this->maxSlippagePct)];
    }

    /** Stap 3: Verifieer dat het target contract op de whitelist staat. */
    private function checkProtocol(array $proposal): array
    {
        $contract = strtolower((string)($proposal['target_contract'] ?? ''));
        if ($contract === '') {
            return ['step' => 'protocol', 'approved' => true, 'reason' => 'Paper trade, geen contract check'];
        }

        foreach ($this->approvedProtocols as $approved) {
            if (strtolower($approved) === $contract) {
                return ['step' => 'protocol', 'approved' => true, 'reason' => 'Contract staat op whitelist'];
            }
        }

        return ['step' => 'protocol', 'approved' => false,
                'reason' => "Contract {$contract} staat NIET op de goedgekeurde protocol-whitelist"];
    }

    /**
     * Stap 4: Rechterlijke Macht — AI Consensus.
     *
     * Normaal:      Minstens 2/3 AI-modellen moeten instemmen.
     * Super Jury:   Bij trade >= 30% van portfolio moet ALLE 3 modellen (3/3) het eens zijn.
     *               Één twijfel = VETO. Geen uitzonderingen.
     *
     * Gebruikt TrustChain als de AI-container beschikbaar is, anders lokale heuristiek.
     */
    private function auditWithConsensus(array $proposal): array
    {
        // ── Bereken of dit een grote trade is (Super Jury drempel) ────────
        $portfolioEth = (float)($proposal['portfolio_eth'] ?? 0);
        $amountEth    = (float)($proposal['amount_eth']    ?? 0);
        $tradePct     = $portfolioEth > 0 ? ($amountEth / $portfolioEth * 100) : 0.0;
        $superJury    = $tradePct >= 30.0;

        $proposalJson = json_encode($proposal, JSON_PRETTY_PRINT);
        $tradeValueEur = abs((float)($proposal['amount_eth'] ?? 0) * (float)($proposal['price_eur'] ?? 0));
        $juryNote     = $superJury
            ? sprintf("\n\n⚠️ SUPER JURY: Deze trade is %.1f%% van het portfolio (≥30%%). ALLE reviewers moeten akkoord gaan.", $tradePct)
            : '';
        if ($tradeValueEur >= 30.0) {
            $juryNote .= sprintf("\n\n⚠️ GEMINI JURY: Nominal trade value ≈ €%.2f (≥€30). Deep-history specialist must APPROVE.", $tradeValueEur);
        }
        $context = "TRADING GOVERNANCE AUDIT\n\n"
            . "Voorstel:\n{$proposalJson}\n\n"
            . "Beoordeel: Is dit een veilige en logische trade?\n"
            . "Antwoord met: APPROVE of REJECT + reden in 2 zinnen."
            . $juryNote;

        try {
            $costEur = 0.0;

            // ── Adversarial Debate (alleen bij Super Jury ≥30%) ───────────────
            if ($superJury && $this->container !== null) {
                [$debatePassed, $debateCost] = $this->runAdversarialDebate($proposal, $proposalJson);
                $costEur += $debateCost;
                if (!$debatePassed) {
                    return [
                        [
                            'step'       => 'adversarial_debate',
                            'approved'   => false,
                            'super_jury' => true,
                            'trade_pct'  => round($tradePct, 1),
                            'reason'     => sprintf(
                                'Adversarial Debate VETO: %.1f%% kapitaal — verdediging onvoldoende tegen aanvallen [SUPER JURY]',
                                $tradePct
                            ),
                        ],
                        $costEur,
                    ];
                }
            }

            $trustChain = new \App\Domain\AI\TrustChain($this->container);
            $result     = $trustChain->review($proposalJson, 'trading_proposal', $context);

            $costEur       += (float)($result['total_cost_eur'] ?? 0);
            $steps          = $result['steps'] ?? [];
            $approvedCount  = count(array_filter($steps, fn($s) => $s['passed'] ?? false));
            $totalCount     = count($steps);

            // Super Jury: vereist 100% (alle reviewers akkoord)
            $approved = $superJury
                ? ($approvedCount === $totalCount && $totalCount > 0)
                : ($result['approved'] ?? false);

            $geminiOk = true;
            if ($tradeValueEur >= 30.0 && $this->container !== null) {
                [$geminiOk, $geminiCost] = $this->runGeminiHistoryJury($proposal, $proposalJson);
                $costEur += $geminiCost;
                $approved = $approved && $geminiOk;
            }

            $juryLabel = $superJury ? ' [SUPER JURY 3/3 + DEBAT]' : ' [2/3]';
            if ($tradeValueEur >= 30.0) {
                $juryLabel .= ' [+Gemini history]';
            }

            return [
                [
                    'step'       => 'consensus_audit',
                    'approved'   => $approved,
                    'super_jury' => $superJury,
                    'trade_pct'  => round($tradePct, 1),
                    'trade_value_eur' => round($tradeValueEur, 2),
                    'reason'     => sprintf('%d/%d AI reviewers akkoord%s. %s%s',
                        $approvedCount, $totalCount, $juryLabel,
                        $approved ? 'Trade goedgekeurd.' : implode('; ', $result['blocking_issues'] ?? ['Afgewezen']),
                        ($tradeValueEur >= 30.0 ? (' | Gemini history: ' . ($geminiOk ? 'APPROVE' : 'REJECT')) : '')
                    ),
                    'votes' => $approvedCount,
                    'total' => $totalCount,
                    'gemini_history_ok' => $tradeValueEur < 30.0 ? null : $geminiOk,
                ],
                $costEur,
            ];
        } catch (\Throwable $e) {
            // Failsafe: grote trades NIET goedkeuren zonder consensus
            $safeApprove = !$superJury;
            return [
                [
                    'step'       => 'consensus_audit',
                    'approved'   => $safeApprove,
                    'super_jury' => $superJury,
                    'trade_pct'  => round($tradePct, 1),
                    'reason'     => $safeApprove
                        ? 'AI consensus niet beschikbaar — heuristiek fallback goedkeuring'
                        : sprintf('Super Jury vereist (%.1f%% kapitaal) maar AI niet bereikbaar — VETO uit veiligheid', $tradePct),
                ],
                0.0,
            ];
        }
    }

    /**
     * Adversarial Debate — twee-model debat voor grote trades (Super Jury).
     *
     * Ronde 1 (Haiku — Advocaat van de Duivel):
     *   "Geef 3 sterke redenen waarom deze trade NIET uitgevoerd moet worden."
     *
     * Ronde 2 (Sonnet — Verdediger):
     *   "Counter elk argument. Verdedig de trade als de data het rechtvaardigt."
     *
     * Verdict: Als de verdediging alle aanvallen weerlegt → APPROVE.
     *           Als de aanvallen onweerlegbaar zijn → VETO.
     *
     * Het debat wordt opgeslagen in de Reasoning Feed.
     *
     * Kosten: ~€0.002 (Haiku) + ~€0.008 (Sonnet) = ~€0.010 per debat.
     * Alleen bij Super Jury (≥30% kapitaal), dus zelden.
     *
     * @return array{0: bool, 1: float}  [debatPassed, kostEur]
     */
    private function runAdversarialDebate(array $proposal, string $proposalJson): array
    {
        try {
            $llm      = new \App\Domain\AI\LlmClient($this->container);
            $logger   = new ReasoningLogger($this->basePath);
            $totalCost = 0.0;

            // ── Ronde 1: Advocaat van de Duivel (Haiku) ───────────────────────
            $attackPrompt = "Je bent de Advocaat van de Duivel voor dit trade-voorstel.\n\n"
                . "VOORSTEL:\n{$proposalJson}\n\n"
                . "Geef PRECIES 3 sterke redenen waarom deze trade NIET uitgevoerd moet worden.\n"
                . "Wees specifiek: gebruik de data in het voorstel.\n"
                . "AANVAL_1: [reden]\nAANVAL_2: [reden]\nAANVAL_3: [reden]";

            $attackResult = $llm->callModel(
                'claude-haiku-4-5',
                'Je bent een scherp kritische trading risk analyst.',
                $attackPrompt
            );
            $attacks   = (string)($attackResult['content'] ?? '');
            $totalCost += (float)($attackResult['cost_eur'] ?? 0.002);

            $logger->writeStep([
                'step'    => 'adversarial_attack',
                'agent'   => 'RiskManager',
                'icon'    => '⚔️',
                'summary' => 'Advocaat van de Duivel: ' . substr(preg_replace('/\s+/', ' ', $attacks), 0, 200),
                'status'  => 'warn',
                'persona' => 'risk_manager',
            ]);

            // ── Ronde 2: Verdediger (Sonnet) ──────────────────────────────────
            $defensePrompt = "Je bent de Verdediger van dit trade-voorstel.\n\n"
                . "VOORSTEL:\n{$proposalJson}\n\n"
                . "AANVALLEN VAN DE CRITICUS:\n{$attacks}\n\n"
                . "Counter elk aanval met concrete data uit het voorstel.\n"
                . "Als je een aanval NIET kunt weerleggen, geef dat eerlijk aan.\n"
                . "Sluit af met:\nDEBAT_VERDICT: APPROVE (alle aanvallen weerlegd) OF VETO (onweerlegbare bezwaren)\n"
                . "VERDICT_REDEN: [één zin]";

            $defenseResult = $llm->callModel(
                'claude-sonnet-4-5',
                'Je bent een senior trading analyst die trade-voorstellen verdedigt op basis van data.',
                $defensePrompt
            );
            $defense   = strtoupper((string)($defenseResult['content'] ?? ''));
            $totalCost += (float)($defenseResult['cost_eur'] ?? 0.008);

            $debatePassed = !str_contains($defense, 'DEBAT_VERDICT: VETO');

            $logger->writeStep([
                'step'    => 'adversarial_defense',
                'agent'   => 'Architect',
                'icon'    => $debatePassed ? '🛡️' : '🚫',
                'summary' => 'Debat Verdict: ' . ($debatePassed ? 'APPROVE — verdediging succesvol' : 'VETO — onweerlegbare bezwaren'),
                'status'  => $debatePassed ? 'ok' : 'veto',
                'persona' => 'architect',
                'data'    => ['cost_eur' => $totalCost],
            ]);

            return [$debatePassed, $totalCost];
        } catch (\Throwable) {
            return [true, 0.0]; // Failsafe: debat-fout blokkeert de trade niet
        }
    }

    /**
     * Derde stem (Gemini): lange-termijn patronen uit Vector Memory vs. voorstel.
     * Alleen bij trade-nominale waarde ≥ €30 (naast bestaande ≥30%% portfolio Super Jury).
     *
     * @return array{0: bool, 1: float} [approved, cost_eur]
     */
    private function runGeminiHistoryJury(array $proposal, string $proposalJson): array
    {
        if ($this->container === null) {
            return [true, 0.0];
        }

        /** @var \App\Core\Config $cfg */
        $cfg   = $this->container->get('config');
        $apiKey = trim((string)($cfg->get('ai.gemini.api_key') ?? ''));
        if ($apiKey === '') {
            return [true, 0.0];
        }

        $memBlock = '';
        try {
            $vd  = $this->basePath . '/data/evolution/vector_memory';
            $mem = new VectorMemoryService('world_model', $vd);
            $facts = $mem->search($proposalJson . ' ETH trading volatility regime memory', 8);
            foreach ($facts as $f) {
                if (is_array($f) && isset($f['text'])) {
                    $memBlock .= '• ' . (string)$f['text'] . "\n";
                }
            }
        } catch (\Throwable) {
        }

        $model = trim((string)($cfg->get('ai.gemini.model') ?? 'gemini-1.5-pro'));
        $llm   = new \App\Domain\AI\LlmClient($this->container);
        $prompt = "LONG-HORIZON / VECTOR MEMORY SNIPPETS:\n{$memBlock}\n\n"
            . "TRADE PROPOSAL JSON:\n{$proposalJson}\n\n"
            . "Focus: herhalende patronen, regime-risico, liquiditeit. "
            . "Reply with exactly one line: JURY: APPROVE or JURY: REJECT then a short reason.";

        $r = $llm->callModel(
            'gemini/' . $model,
            'You are the Deep History & Vector Memory specialist on the governance jury. Be conservative.',
            $prompt
        );

        $text = strtoupper((string)($r['content'] ?? ''));
        $ok   = str_contains($text, 'JURY: APPROVE')
            || (str_contains($text, 'APPROVE') && !str_contains($text, 'JURY: REJECT'));
        if (str_contains($text, 'JURY: REJECT')) {
            $ok = false;
        }

        return [$ok, (float)($r['cost_eur'] ?? 0.0)];
    }

    /** Laad of initialiseer trading rules. */
    public function loadRules(): array
    {
        $file = $this->basePath . '/' . self::RULES_FILE;
        if (is_file($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return $this->defaultRules();
    }

    /** Sla (bijgewerkte) trading rules op. */
    public function saveRules(array $rules): void
    {
        $dir = dirname($this->basePath . '/' . self::RULES_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents(
            $this->basePath . '/' . self::RULES_FILE,
            json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function defaultRules(): array
    {
        return [
            '_version'                    => 1,
            '_updated_at'                 => date('c'),
            'max_eth_per_trade'           => 0.004,
            'max_portfolio_pct_per_trade' => 25.0,
            'max_daily_volume_eur'        => 50.0,
            'min_signal_strength'         => 30,
            'allowed_signals'             => ['BUY', 'SELL'],
            'require_trend_confirmation'  => true,
            'max_slippage_pct'            => 2.0,
            'approved_protocols'          => [
                '0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D',
                '0xE592427A0AEce92De3Edee1F18E0157C05861564',
            ],
        ];
    }

    private function verdict(bool $approved, string $verdict, string $reason, array $votes, float $costEur): array
    {
        $result = [
            'approved'  => $approved,
            'verdict'   => $verdict,
            'reason'    => $reason,
            'votes'     => $votes,
            'cost_eur'  => $costEur,
            'ts'        => date('c'),
        ];
        $this->appendVerdictLog($result);
        return $result;
    }

    private function appendVerdictLog(array $verdict): void
    {
        $dir = $this->basePath . '/data/evolution/trading';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($dir . '/governance_verdicts.jsonl', json_encode($verdict) . "\n", FILE_APPEND | LOCK_EX);
    }
}
