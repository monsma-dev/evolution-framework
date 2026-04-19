<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Config;
use App\Core\Evolution\Intelligence\StrategistTradingGate;
use Psr\Container\ContainerInterface;

/**
 * TradingValidatorAgent — Lichtgewicht Veto-instantie (Haiku/lokaal model).
 *
 * Positie in het rechtssysteem:
 *   WETGEVENDE MACHT (TradingGovernance) bepaalt de regels.
 *   UITVOERENDE MACHT (TradingService)   stelt de trade voor.
 *   RECHTERLIJKE MACHT — stap 1:         Validator Agent (dit bestand) — snelle heuristiek + AI check.
 *   RECHTERLIJKE MACHT — stap 2:         TrustChain multi-agent consensus (2/3 AI-modellen).
 *
 * De Validator kan een VETO uitspreken. Bij veto:
 *   - Trade wordt geblokkeerd.
 *   - Reden wordt opgeslagen in de Court of Records.
 *   - Trader Agent wordt gelogd met "vetoed_trade_count" (telt mee in reputatie).
 *
 * Scenario gate — validateScenarioStress():
 *   - Bij notional ≥ trigger_eur (standaard €25): BTC-schok Monte Carlo + flash-crash (5m, -10%)
 *     via ScenarioEngineService — zie evolution.trading.validator.scenario_gate.
 *
 * Heuristiek (geen AI-kosten):
 *   - Abnormale signaalsterkte (>95 = mogelijk data-error)
 *   - RSI buiten bereik [0-100]
 *   - Prijs 0 of negatief
 *   - Divergentie alert actief
 *   - Circuit breaker actief
 *
 * AI check (Haiku, goedkoop):
 *   - Alleen bij live trades (paper = heuristiek volstaat)
 *   - Vraagt model: "Is deze trade logisch gegeven de context?"
 */
final class TradingValidatorAgent
{
    private const SENTIMENT_VETO_THRESHOLD = -0.3;

    private ?ContainerInterface $container;
    private bool                $useAiCheck;
    private float               $maxAiCostEur;
    private bool                $deepAnalysisEnabled;
    private float               $sonnetAtEur;
    private string              $basePath;
    /** @var array<string, mixed> */
    private array $scenarioGateCfg;

    public function __construct(?ContainerInterface $container, array $config = [], ?string $basePath = null)
    {
        $this->container           = $container;
        $this->useAiCheck          = (bool)($config['use_ai_check']           ?? false);
        $this->maxAiCostEur        = (float)($config['max_ai_cost_eur']        ?? 0.002);
        $this->deepAnalysisEnabled = (bool)($config['deep_analysis_enabled']   ?? true);
        $this->sonnetAtEur         = (float)($config['sonnet_at_balance_eur']  ?? 100.0);
        $this->basePath            = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->scenarioGateCfg     = (array)($config['scenario_gate'] ?? []);
    }

    /**
     * Valideer een handels-voorstel. Geeft APPROVE of VETO terug.
     *
     * @param array $proposal
     * @param array $oracleResult  Resultaat van OraclePriceGuard::check()
     * @return array{verdict: 'APPROVE'|'VETO', reason: string, heuristics: array, ai_used: bool, cost_eur: float}
     */
    public function validate(array $proposal, array $oracleResult = []): array
    {
        $heuristics = $this->runHeuristics($proposal, $oracleResult);
        $vetoed     = array_filter($heuristics, fn($h) => $h['result'] === 'FAIL');

        if (!empty($vetoed)) {
            $reasons = array_map(fn($h) => $h['check'] . ': ' . $h['reason'], array_values($vetoed));
            $verdict = $this->buildVerdict('VETO', implode('; ', $reasons), $heuristics, false, 0.0);
            $this->logVeto($proposal, $verdict);
            return $verdict;
        }

        // ── Scenario stress: BTC shock + flash-crash (5m) bij notional ≥ drempel ──
        $scenarioOutcome = $this->validateScenarioStress($proposal, $heuristics);
        if (!$scenarioOutcome['ok']) {
            $verdict = $scenarioOutcome['verdict'];
            $this->logVeto($proposal, $verdict);
            return $verdict;
        }
        $heuristics = $scenarioOutcome['heuristics'];

        // ── Strategist + Director-sync (Vector Memory trading_nn + Neural trend) ──
        $strategistGate = (new StrategistTradingGate($this->basePath, $this->container))->evaluate($proposal);
        if (!$strategistGate['pass']) {
            $verdict = $this->buildVerdict('VETO', $strategistGate['reason'], $heuristics, false, 0.0);
            $this->logVeto($proposal, $verdict);
            return $verdict;
        }

        // ── Memory Loop: raadpleeg historische patronen ───────────────────
        if (($proposal['side'] ?? '') === 'BUY') {
            $memory  = new TradeMemoryService($this->basePath);
            $pattern = $memory->queryPattern(
                (float)($proposal['rsi']       ?? 50.0),
                (float)($proposal['sentiment'] ?? 0.0),
                'BUY'
            );
            if ($pattern['veto_recommended']) {
                $verdict = $this->buildVerdict('VETO', $pattern['reason'], $heuristics, false, 0.0);
                $this->logVeto($proposal, $verdict);
                return $verdict;
            }
        }

        // AI check alleen bij live trades als configured
        $mode       = (string)($proposal['mode'] ?? 'paper');
        $tradingEur = (float)($proposal['trading_eur'] ?? 0.0);
        $costEur    = 0.0;
        $aiUsed     = false;

        if ($mode === 'live' && $this->container !== null) {
            // DeepAnalysis via Sonnet bij saldo >= €100 (Standard Tier)
            if ($this->deepAnalysisEnabled && $tradingEur >= $this->sonnetAtEur) {
                [$aiVerdict, $costEur, $confidence] = $this->runDeepAnalysis($proposal, $tradingEur);
                $aiUsed = true;
                if ($aiVerdict === 'VETO') {
                    $reason  = 'Deep Analysis VETO (confidence: ' . $confidence . '): alleen High Confidence trades bij €' . (int)$this->sonnetAtEur . '+';
                    $verdict = $this->buildVerdict('VETO', $reason, $heuristics, true, $costEur);
                    $this->logVeto($proposal, $verdict);
                    return $verdict;
                }
            } elseif ($this->useAiCheck) {
                // Goedkope Haiku check onder Standard Tier
                [$aiVerdict, $costEur] = $this->runAiCheck($proposal);
                $aiUsed = true;
                if ($aiVerdict === 'VETO') {
                    $verdict = $this->buildVerdict('VETO', 'AI Validator veto: zie AI reasoning', $heuristics, true, $costEur);
                    $this->logVeto($proposal, $verdict);
                    return $verdict;
                }
            }
        }

        return $this->buildVerdict('APPROVE', 'Alle validatie checks geslaagd', $heuristics, $aiUsed, $costEur);
    }

    /**
     * @param array<int, array<string, mixed>> $heuristics
     * @return array{ok: bool, heuristics: array, verdict?: array}
     */
    private function validateScenarioStress(array $proposal, array $heuristics): array
    {
        if (!$this->shouldRunScenarioGate($proposal)) {
            return ['ok' => true, 'heuristics' => $heuristics];
        }
        $appConfig = $this->resolveAppConfig();
        if (!$appConfig instanceof Config) {
            return ['ok' => true, 'heuristics' => $heuristics];
        }

        $engine  = new ScenarioEngineService($appConfig);
        $price   = (float)($proposal['price_eur'] ?? 0);
        $history = (array)($proposal['price_history'] ?? []);
        $side    = strtoupper((string)($proposal['side'] ?? 'BUY'));
        $dirSim  = $side === 'SELL' ? 'SELL' : 'BUY';
        $notional = abs((float)($proposal['amount_eth'] ?? 0) * $price);
        $nav     = (float)($proposal['trading_eur'] ?? 0.0);

        $btcShock = (float)($this->scenarioGateCfg['btc_shock_pct'] ?? -10.0);
        $stress   = $engine->passesMandatoryBtcShock($price, $history, $dirSim, $btcShock);
        if (!$stress['pass']) {
            $heuristics[] = [
                'check'  => 'scenario_btc_shock',
                'result' => 'FAIL',
                'reason' => $stress['detail'],
            ];

            return [
                'ok'      => false,
                'heuristics' => $heuristics,
                'verdict' => $this->buildVerdict('VETO', 'Scenario stress VETO: ' . $stress['detail'], $heuristics, false, 0.0),
            ];
        }
        $heuristics[] = [
            'check'  => 'scenario_btc_shock',
            'result' => 'PASS',
            'reason' => $stress['detail'],
        ];

        $flash = $engine->validateFlashCrashScenario($price, $history, $dirSim, $notional, $nav);
        if (!$flash['pass']) {
            $heuristics[] = [
                'check'  => 'scenario_flash_crash',
                'result' => 'FAIL',
                'reason' => $flash['detail'],
            ];

            return [
                'ok'      => false,
                'heuristics' => $heuristics,
                'verdict' => $this->buildVerdict('VETO', 'Flash crash scenario VETO: ' . $flash['detail'], $heuristics, false, 0.0),
            ];
        }
        $heuristics[] = [
            'check'  => 'scenario_flash_crash',
            'result' => 'PASS',
            'reason' => $flash['detail'],
        ];

        return ['ok' => true, 'heuristics' => $heuristics];
    }

    private function shouldRunScenarioGate(array $proposal): bool
    {
        if (!filter_var($this->scenarioGateCfg['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }
        $mode = (string)($proposal['mode'] ?? 'paper');
        if ($mode === 'paper' && !filter_var($this->scenarioGateCfg['apply_to_paper'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }
        $trigger  = max(0.0, (float)($this->scenarioGateCfg['trigger_eur'] ?? 25.0));
        $notional = abs((float)($proposal['amount_eth'] ?? 0) * (float)($proposal['price_eur'] ?? 0));

        return $notional >= $trigger;
    }

    private function resolveAppConfig(): ?Config
    {
        if ($this->container === null || !$this->container->has('config')) {
            return null;
        }
        $c = $this->container->get('config');

        return $c instanceof Config ? $c : null;
    }

    private function runHeuristics(array $proposal, array $oracleResult): array
    {
        $checks = [];

        // Check 1: Oracle alert actief?
        if (!($oracleResult['ok'] ?? true)) {
            $checks[] = ['check' => 'oracle_guard',  'result' => 'FAIL',
                         'reason' => $oracleResult['alert'] ?? 'Oracle alert actief'];
        } else {
            $checks[] = ['check' => 'oracle_guard', 'result' => 'PASS', 'reason' => 'Geen oracle alert'];
        }

        // Check 2: Prijs geldig
        $price = (float)($proposal['price_eur'] ?? 0);
        if ($price <= 0 || $price > 1_000_000) {
            $checks[] = ['check' => 'price_sanity', 'result' => 'FAIL',
                         'reason' => "Ongeldige prijs: €{$price}"];
        } else {
            $checks[] = ['check' => 'price_sanity', 'result' => 'PASS', 'reason' => "Prijs €{$price} OK"];
        }

        // Check 3: RSI in geldig bereik
        $rsi = (float)($proposal['signal']['rsi'] ?? 50);
        if ($rsi < 0 || $rsi > 100) {
            $checks[] = ['check' => 'rsi_sanity', 'result' => 'FAIL',
                         'reason' => "RSI {$rsi} buiten bereik [0-100]"];
        } else {
            $checks[] = ['check' => 'rsi_sanity', 'result' => 'PASS', 'reason' => "RSI {$rsi} OK"];
        }

        // Check 4: Signaalsterkte niet suspicieus hoog
        $strength = (int)($proposal['signal']['strength'] ?? 0);
        if ($strength > 95) {
            $checks[] = ['check' => 'signal_sanity', 'result' => 'WARN',
                         'reason' => "Signaalsterkte {$strength}% is abnormaal hoog — mogelijk data-error"];
        } else {
            $checks[] = ['check' => 'signal_sanity', 'result' => 'PASS', 'reason' => "Signaalsterkte {$strength}% OK"];
        }

        // Check 5: Bedrag niet nul
        $amountEth = (float)($proposal['amount_eth'] ?? 0);
        if ($amountEth <= 0) {
            $checks[] = ['check' => 'amount_sanity', 'result' => 'FAIL',
                         'reason' => "Trade bedrag {$amountEth} ETH is nul of negatief"];
        } else {
            $checks[] = ['check' => 'amount_sanity', 'result' => 'PASS', 'reason' => "{$amountEth} ETH OK"];
        }

        // Check 6: Physical Sentiment Filter (alleen voor BUY signalen)
        $side = strtoupper((string)($proposal['side'] ?? ''));
        if ($side === 'BUY') {
            $checks[] = $this->runSentimentCheck();
        }

        return $checks;
    }

    private function runSentimentCheck(): array
    {
        try {
            $analyzer = new SentimentAnalyzer($this->container, $this->basePath);
            $score    = $analyzer->getScore();
            $scoreStr = number_format($score, 2);

            if ($score < self::SENTIMENT_VETO_THRESHOLD) {
                $reason = "Trade geblokkeerd door negatief fysiek sentiment (score: {$scoreStr})";
                $this->logSentimentVeto($score, $reason);
                return [
                    'check'  => 'physical_sentiment',
                    'result' => 'FAIL',
                    'reason' => $reason,
                    'score'  => $score,
                ];
            }

            return [
                'check'  => 'physical_sentiment',
                'result' => 'PASS',
                'reason' => "Sentiment OK (score: {$scoreStr})",
                'score'  => $score,
            ];
        } catch (\Throwable $e) {
            // Nooit traden blokkeren door een technische sentimentfout
            return [
                'check'  => 'physical_sentiment',
                'result' => 'WARN',
                'reason' => 'Sentiment check mislukt: ' . $e->getMessage(),
                'score'  => 0.0,
            ];
        }
    }

    private function logSentimentVeto(float $score, string $reason): void
    {
        $log = [
            'ts'     => date('c'),
            'event'  => 'PHYSICAL_SENTIMENT_VETO',
            'score'  => $score,
            'reason' => $reason,
        ];
        $dir = $this->basePath . '/data/evolution/trading';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($dir . '/validator_vetoes.jsonl', json_encode($log) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Deep Reasoning Loop via DeepReasoningService (Long-Chain Reasoning).
     *
     * Stappen:
     *   1. AgentToolbox  — WhaleWatcher + Monte Carlo (snelle veto)
     *   2. DeepReasoningService — Context Pack + Chain-of-Thought (3 PRO / 3 CON / Zelfkritiek)
     *   3. Verdict met confidence-drempel voor Standard Tier
     *
     * @return array{0: 'APPROVE'|'VETO', 1: float, 2: string}
     */
    private function runDeepAnalysis(array $proposal, float $tradingEur): array
    {
        try {
            // ── Stap A: AgentToolbox (WhaleWatcher + Monte Carlo) — snelle veto ─
            $history = (array)($proposal['price_history'] ?? []);
            $toolbox = (new AgentToolbox($this->basePath, null, $this->container))->analyze($proposal, $history);

            if ($toolbox['veto']) {
                return ['VETO', 0.0, 'Low'];
            }

            // ── Stap B: Deep Reasoning Loop (Chain-of-Thought + Context Pack) ─
            $deepReasoning = new DeepReasoningService($this->container, $this->basePath);
            $result        = $deepReasoning->run($proposal, $toolbox, $tradingEur);

            return [
                $result['verdict'],
                $result['cost_eur'],
                $result['confidence'],
            ];
        } catch (\Throwable $e) {
            return ['APPROVE', 0.0, 'Unknown']; // Failsafe bij AI-storing
        }
    }

    private function runAiCheck(array $proposal): array
    {
        try {
            $llm     = new \App\Domain\AI\LlmClient($this->container);
            $context = json_encode($proposal, JSON_PRETTY_PRINT);
            $prompt  = "Je bent een voorzichtige trading validator. Gegeven dit handels-voorstel:\n{$context}\n\n"
                     . "Antwoord ALLEEN met: APPROVE of VETO\n"
                     . "Dan één zin reden. Voorbeeld: 'APPROVE: RSI en trend zijn consistent.'";

            $result = $llm->callModel(ModelSelectorService::MODEL_HAIKU, 'Je bent een voorzichtige trading validator.', $prompt);
            $text   = strtoupper(trim((string)($result['content'] ?? '')));
            $cost   = (float)($result['cost_eur'] ?? 0.001);

            $verdict = str_starts_with($text, 'VETO') ? 'VETO' : 'APPROVE';
            return [$verdict, $cost];
        } catch (\Throwable $e) {
            return ['APPROVE', 0.0]; // Failsafe: goedkeuren bij AI-storing
        }
    }

    private function logVeto(array $proposal, array $verdict): void
    {
        $log = [
            'ts'       => date('c'),
            'event'    => 'VALIDATOR_VETO',
            'proposal' => $proposal,
            'verdict'  => $verdict,
        ];
        $dir = $this->basePath . '/data/evolution/trading';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($dir . '/validator_vetoes.jsonl', json_encode($log) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function buildVerdict(string $verdict, string $reason, array $heuristics, bool $aiUsed, float $costEur): array
    {
        return [
            'verdict'    => $verdict,
            'reason'     => $reason,
            'heuristics' => $heuristics,
            'ai_used'    => $aiUsed,
            'cost_eur'   => $costEur,
        ];
    }
}
