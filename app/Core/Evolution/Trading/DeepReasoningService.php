<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Evolution\Intelligence\StrategistTradingGate;
use Psr\Container\ContainerInterface;

/**
 * DeepReasoningService — Long-Chain Reasoning voor trading beslissingen.
 *
 * Architectuur ("Deep Thinking in 4 stappen"):
 *
 *   STAP 1 — CONTEXT PACK (Cross-Pollination)
 *             Bouw een ~5.000-woord kennisblok uit:
 *             • TradeMemoryService  (historische patronen uit Vector Memory)
 *             • WhaleWatcher        (on-chain walvis-bewegingen, live)
 *             • SimulationSandbox   (Monte Carlo 1000 scenario's)
 *             • StudySessionService (geleerdeheuristieken van dagelijkse studie)
 *             • TavilySearch        (live ETH-nieuws, optioneel)
 *
 *   STAP 2 — PRO-ARGUMENTEN (3 redenen vóór de trade)
 *   STAP 3 — CON-ARGUMENTEN (3 redenen tégen de trade)
 *   STAP 4 — ZELFKRITIEK    ("waarom is dit misschien een slecht idee?")
 *   STAP 5 — EINDOORDEEL    (APPROVE|VETO + CONFIDENCE High|Medium|Low)
 *
 * Alle stappen worden gelogd naar de ReasoningLogger (Live Feed).
 * Eén Sonnet-call met geforceerde Chain-of-Thought response.
 * Kosten: ~€0.008–0.015 per deep-reasoning cyclus.
 */
final class DeepReasoningService
{
    private const TAVILY_API      = 'https://api.tavily.com/search';
    private const TAVILY_CACHE    = 'storage/evolution/trading/tavily_cache.json';
    private const TAVILY_CACHE_TTL = 300; // 5 minuten cache

    private ?ContainerInterface $container;
    private string $basePath;

    public function __construct(?ContainerInterface $container, string $basePath)
    {
        $this->container = $container;
        $this->basePath  = $basePath;
    }

    /**
     * Voer de volledige Deep Reasoning Loop uit.
     *
     * @param  array  $proposal    Trade-voorstel (side, rsi, price, sentiment, amount_eth, ...)
     * @param  array  $toolboxResult  Resultaat van AgentToolbox (whale + monte carlo)
     * @param  float  $tradingEur  Huidig trading wallet saldo
     * @return array{verdict: 'APPROVE'|'VETO', confidence: string, reasoning_chain: array,
     *               cost_eur: float, self_critique: string, pro_args: string[], con_args: string[]}
     */
    public function run(array $proposal, array $toolboxResult, float $tradingEur): array
    {
        $logger = new ReasoningLogger($this->basePath);

        // ── Stap 1: Context Pack bouwen ───────────────────────────────────
        $contextPack = $this->buildContextPack($proposal, $toolboxResult);
        $logger->writeStep([
            'step'    => 'context_pack',
            'agent'   => 'Architect',
            'icon'    => '📦',
            'summary' => sprintf('Context Pack opgebouwd: %d tekens uit %d bronnen',
                strlen($contextPack['text']), $contextPack['source_count']),
            'status'  => 'ok',
            'persona' => 'architect',
        ]);

        // ── Stap 2–5: Chain-of-Thought LLM-call ──────────────────────────
        [$chainResult, $cost] = $this->runChainOfThought($proposal, $contextPack['text'], $tradingEur);

        // ── Log elke stap naar de Reasoning Feed ─────────────────────────
        foreach ($chainResult['pro_args'] as $i => $arg) {
            $logger->writeStep([
                'step'    => 'pro_argument_' . ($i + 1),
                'agent'   => 'Architect',
                'icon'    => '✅',
                'summary' => 'PRO ' . ($i + 1) . ': ' . $arg,
                'status'  => 'ok',
                'persona' => 'architect',
            ]);
        }

        foreach ($chainResult['con_args'] as $i => $arg) {
            $logger->writeStep([
                'step'    => 'con_argument_' . ($i + 1),
                'agent'   => 'Architect',
                'icon'    => '⚠️',
                'summary' => 'CON ' . ($i + 1) . ': ' . $arg,
                'status'  => 'warn',
                'persona' => 'architect',
            ]);
        }

        $logger->writeStep([
            'step'    => 'self_critique',
            'agent'   => 'Architect',
            'icon'    => '🔍',
            'summary' => 'ZELFKRITIEK: ' . $chainResult['self_critique'],
            'status'  => 'warn',
            'persona' => 'architect',
        ]);

        $logger->writeStep([
            'step'    => 'deep_reasoning_verdict',
            'agent'   => 'Architect',
            'icon'    => $chainResult['verdict'] === 'APPROVE' ? '🧠' : '🚫',
            'summary' => sprintf('Deep Reasoning: %s (%s confidence) — %s',
                $chainResult['verdict'],
                $chainResult['confidence'],
                $chainResult['reason']
            ),
            'status'  => $chainResult['verdict'] === 'APPROVE' ? 'ok' : 'veto',
            'persona' => 'architect',
        ]);

        return array_merge($chainResult, ['cost_eur' => $cost]);
    }

    // ── Context Pack (Cross-Pollination) ─────────────────────────────────

    /**
     * Bouw een rijke context van alle beschikbare kennisbronnen.
     *
     * @return array{text: string, source_count: int}
     */
    private function buildContextPack(array $proposal, array $toolboxResult): array
    {
        $sections     = [];
        $sourceCount  = 0;

        // ── 0. Strategist — trading_nn Vector Memory + TradingPredictor ─────────
        $strategistCtx = StrategistTradingGate::snapshotForDeepReasoning($this->basePath, $proposal);
        $modelName     = (string) ($strategistCtx['scores']['model'] ?? '');
        $sections[]    = "=== STRATEGIST / trading_nn + NEURAL ===\n"
            . sprintf(
                "trend_prediction: %.4f | modernity_score: %.4f | model: %s\n",
                $strategistCtx['trend_prediction'],
                $strategistCtx['modernity_score'],
                $modelName
            )
            . "Recent trading_nn (Vector Memory, verkort):\n"
            . ($strategistCtx['vm_snippets'] !== ''
                ? $strategistCtx['vm_snippets']
                : '(geen rijen — periodiek evolve:trade status --record uitvoeren)')
            . "\nRegel: trend_prediction moet > 0 geweest zijn om Validator te passeren; Director-principes hebben voorrang — bij twijfel VETO.";
        $sourceCount++;

        // ── 1. Historische trade memories ─────────────────────────────────
        ['text' => $memText, 'count' => $memCount] = $this->loadRecentMemories();
        if ($memText !== '') {
            $sections[] = "=== VECTOR MEMORY (laatste {$memCount} trades) ===\n" . $memText;
            $sourceCount++;
        }

        // ── 2. Study Session inzichten ────────────────────────────────────
        $hints = (new StudySessionService(null, $this->basePath))->loadInsights();
        if (!empty($hints)) {
            $sections[] = "=== GELEERDEHEURISTIEKEN (studie-sessies) ===\n"
                . implode("\n", array_map(fn($h) => "• {$h}", $hints));
            $sourceCount++;
        }

        // ── 3. On-chain Whale data ────────────────────────────────────────
        $whale = $toolboxResult['whale'] ?? [];
        if (!empty($whale)) {
            $sections[] = "=== ON-CHAIN WHALE DATA ===\n"
                . "Verdict: " . ($whale['verdict'] ?? 'NEUTRAL') . "\n"
                . "Reden: "   . ($whale['reason']  ?? 'N/A')    . "\n"
                . sprintf("Buy volume: %.4f ETH | Sell volume: %.4f ETH | Net: %+.4f ETH | Transacties: %d",
                    $whale['buy_volume_eth']  ?? 0,
                    $whale['sell_volume_eth'] ?? 0,
                    $whale['net_eth']         ?? 0,
                    $whale['tx_count']        ?? 0
                );
            $sourceCount++;
        }

        // ── 4. Monte Carlo simulatie ──────────────────────────────────────
        $sim = $toolboxResult['simulation'] ?? [];
        if (!empty($sim)) {
            $sections[] = "=== MONTE CARLO SIMULATIE (1000 scenario's) ===\n"
                . ($sim['reason'] ?? 'N/A') . "\n"
                . sprintf("Win rate: %.1f%% | Gem. return: %+.2f%% | P5 return: %+.2f%%",
                    ($sim['win_rate'] ?? 0)        * 100,
                    $sim['avg_return_pct'] ?? 0,
                    $sim['p5_return_pct']  ?? 0
                );
            $sourceCount++;
        }

        // ── 5. Huidig voorstel signaal ────────────────────────────────────
        $rsi       = $proposal['rsi'] ?? $proposal['signal']['rsi'] ?? 'N/A';
        $sentiment = $proposal['sentiment'] ?? 'N/A';
        $strength  = $proposal['signal']['strength'] ?? 'N/A';
        $price     = $proposal['price_eur'] ?? 'N/A';
        $sections[] = "=== HUIDIG SIGNAAL ===\n"
            . "RSI: {$rsi} | Sentiment: {$sentiment} | Signaalsterkte: {$strength}%\n"
            . "Prijs: €{$price} | Zijde: " . ($proposal['side'] ?? '?');

        // ── 6. Social Arbitrage — Aziatisch vs Westers sentiment (Tavily) ──
        $socialArbitrage = $this->fetchSocialArbitrage();
        if ($socialArbitrage['text'] !== '') {
            $sections[] = "=== SOCIAL ARBITRAGE (Aziatisch vs Westers sentiment) ===\n"
                . $socialArbitrage['text'];
            if ($socialArbitrage['divergence'] !== '') {
                $sections[] = "⚡ ARBITRAGE KANS: " . $socialArbitrage['divergence'];
            }
            $sourceCount++;
        }

        // ── 7. Mempool Heatmap (Rust binary output) ───────────────────────
        $mempool = $this->loadMempoolHeatmap();
        if ($mempool !== '') {
            $sections[] = "=== MEMPOOL HEATMAP (Anticipation Engine) ===\n" . $mempool;
            $sourceCount++;
        }

        // ── 8. Architect Legacy Knowledge — persoonlijke doelen ───────────
        $profileCtx = (new \App\Core\Evolution\Intelligence\ArchitectProfile($this->basePath))->contextString();
        if ($profileCtx !== '') {
            $sections[] = "=== ARCHITECT PROFIEL & DOELEN ===\n" . $profileCtx;
            $sourceCount++;
        }

        return [
            'text'         => implode("\n\n", $sections),
            'source_count' => $sourceCount,
        ];
    }

    /** @return array{text: string, count: int} */
    private function loadRecentMemories(): array
    {
        $file = $this->basePath . '/data/evolution/trading/trade_memories.jsonl';
        if (!is_file($file)) {
            return ['text' => '', 'count' => 0];
        }

        $lines = array_slice(
            array_filter(explode("\n", (string)file_get_contents($file))),
            -20
        );

        $result = [];
        foreach ($lines as $line) {
            $m = json_decode($line, true);
            if (!is_array($m)) {
                continue;
            }
            $outcome    = $m['outcome'] ?? null;
            $outcomeStr = match ($outcome) {
                'win'  => '✅ WIN',
                'loss' => '❌ LOSS',
                'flat' => '🟡 FLAT',
                default => '⏳ pending',
            };
            $result[] = sprintf(
                "RSI=%.1f | Sentiment=%.2f | Actie=%s | %s | %s",
                $m['rsi']       ?? 0,
                $m['sentiment'] ?? 0,
                $m['action']    ?? '?',
                $outcomeStr,
                $m['created_at'] ?? ''
            );
        }

        return ['text' => implode("\n", $result), 'count' => count($result)];
    }

    /** Haal ETH-nieuws op via Tavily (gecached 5 minuten). */
    private function fetchTavilyNews(): string
    {
        $configFile = $this->basePath . '/config/evolution.json';
        if (!is_file($configFile)) {
            return '';
        }

        $config = json_decode((string)file_get_contents($configFile), true);
        $apiKey = trim((string)($config['tavily_api_key'] ?? ''));
        if ($apiKey === '') {
            return '';
        }

        // Check cache
        $cacheFile = $this->basePath . '/' . self::TAVILY_CACHE;
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && (time() - (int)($cached['_ts'] ?? 0)) < self::TAVILY_CACHE_TTL) {
                return (string)($cached['text'] ?? '');
            }
        }

        try {
            $payload = json_encode([
                'api_key'        => $apiKey,
                'query'          => 'Ethereum ETH price news today',
                'search_depth'   => 'basic',
                'max_results'    => 3,
                'include_answer' => true,
            ]);

            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nUser-Agent: EvolutionTradingBot/1.0\r\n",
                'content'       => $payload,
                'timeout'       => 5,
                'ignore_errors' => true,
            ]]);

            $raw  = @file_get_contents(self::TAVILY_API, false, $ctx);
            $data = $raw ? json_decode($raw, true) : null;

            if (!is_array($data)) {
                return '';
            }

            $lines = [];
            if (isset($data['answer'])) {
                $lines[] = "Samenvatting: " . substr((string)$data['answer'], 0, 300);
            }
            foreach ((array)($data['results'] ?? []) as $r) {
                $lines[] = "• " . ($r['title'] ?? '') . " — " . substr((string)($r['content'] ?? ''), 0, 150);
            }

            $text = implode("\n", $lines);
            @file_put_contents($cacheFile, json_encode(['_ts' => time(), 'text' => $text]), LOCK_EX);

            return $text;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Social Arbitrage — Vergelijk Aziatisch vs Westers crypto-sentiment.
     *
     * Aziatische markten lopen vaak 4-8 uur voor op Westerse trends door tijdzones.
     * Als Aziatisch sentiment sterk bullish is terwijl Westers neutraal/bearish is,
     * is er een potentiële arbitragekans.
     *
     * @return array{text: string, divergence: string}
     */
    private function fetchSocialArbitrage(): array
    {
        $configFile = $this->basePath . '/config/evolution.json';
        if (!is_file($configFile)) {
            return ['text' => '', 'divergence' => ''];
        }

        $config = json_decode((string)file_get_contents($configFile), true);
        $apiKey = trim((string)($config['tavily_api_key'] ?? ''));
        if ($apiKey === '') {
            return ['text' => '', 'divergence' => ''];
        }

        $cacheFile = $this->basePath . '/data/evolution/trading/social_arbitrage_cache.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && (time() - (int)($cached['_ts'] ?? 0)) < 600) {
                return ['text' => (string)($cached['text'] ?? ''), 'divergence' => (string)($cached['divergence'] ?? '')];
            }
        }

        try {
            $asianResult   = $this->tavilySearch($apiKey, 'Ethereum ETH crypto bullish bearish Japan Korea Asia 今日');
            $westernResult = $this->tavilySearch($apiKey, 'Ethereum ETH price sentiment today Europe USA');

            if ($asianResult === '' && $westernResult === '') {
                return ['text' => '', 'divergence' => ''];
            }

            // Simpele sentiment scoring op basis van keyword counts
            $asianBullish  = substr_count(strtolower($asianResult), 'bullish')
                           + substr_count(strtolower($asianResult), 'rally')
                           + substr_count(strtolower($asianResult), 'surge');
            $asianBearish  = substr_count(strtolower($asianResult), 'bearish')
                           + substr_count(strtolower($asianResult), 'crash')
                           + substr_count(strtolower($asianResult), 'drop');
            $westernBullish = substr_count(strtolower($westernResult), 'bullish')
                            + substr_count(strtolower($westernResult), 'rally')
                            + substr_count(strtolower($westernResult), 'surge');
            $westernBearish = substr_count(strtolower($westernResult), 'bearish')
                            + substr_count(strtolower($westernResult), 'crash')
                            + substr_count(strtolower($westernResult), 'drop');

            $asianScore   = $asianBullish - $asianBearish;
            $westernScore = $westernBullish - $westernBearish;
            $divergence   = $asianScore - $westernScore;

            $asianLabel   = $asianScore  > 0 ? '🟢 Bullish' : ($asianScore  < 0 ? '🔴 Bearish' : '🟡 Neutraal');
            $westernLabel = $westernScore > 0 ? '🟢 Bullish' : ($westernScore < 0 ? '🔴 Bearish' : '🟡 Neutraal');

            $text = "Aziatisch sentiment: {$asianLabel} (score: {$asianScore})\n"
                  . "Westers sentiment: {$westernLabel} (score: {$westernScore})\n"
                  . "Nieuws (Aziatisch): " . substr($asianResult,   0, 300) . "\n"
                  . "Nieuws (Westers):  " . substr($westernResult,  0, 300);

            $divergenceNote = '';
            if ($divergence >= 3) {
                $divergenceNote = sprintf(
                    'Aziatisch sentiment loopt STERK voor op Westers (divergentie: +%d). Vroeg instapmoment mogelijk.',
                    $divergence
                );
            } elseif ($divergence <= -3) {
                $divergenceNote = sprintf(
                    'Westers sentiment loopt voor op Aziatisch (divergentie: %d). Wees voorzichtig.',
                    $divergence
                );
            }

            @file_put_contents($cacheFile, json_encode([
                '_ts'        => time(),
                'text'       => $text,
                'divergence' => $divergenceNote,
            ]), LOCK_EX);

            return ['text' => $text, 'divergence' => $divergenceNote];
        } catch (\Throwable) {
            return ['text' => '', 'divergence' => ''];
        }
    }

    /** Voer één Tavily-zoekopdracht uit en geef samenvatting terug. */
    private function tavilySearch(string $apiKey, string $query): string
    {
        $payload = json_encode([
            'api_key'      => $apiKey,
            'query'        => $query,
            'search_depth' => 'basic',
            'max_results'  => 3,
            'include_answer' => true,
        ]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nUser-Agent: EvolutionTradingBot/1.0\r\n",
            'content'       => $payload,
            'timeout'       => 5,
            'ignore_errors' => true,
        ]]);

        $raw  = @file_get_contents(self::TAVILY_API, false, $ctx);
        $data = $raw ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            return '';
        }

        $parts = [];
        if (isset($data['answer'])) {
            $parts[] = substr((string)$data['answer'], 0, 200);
        }
        foreach ((array)($data['results'] ?? []) as $r) {
            $parts[] = '• ' . substr((string)($r['title'] ?? ''), 0, 80);
        }

        return implode(' | ', $parts);
    }

    /** Lees de Mempool Heatmap gegenereerd door de Rust binary. */
    private function loadMempoolHeatmap(): string
    {
        $file = $this->basePath . '/data/evolution/trading/mempool_heatmap.json';
        if (!is_file($file)) {
            return '';
        }

        // Niet ouder dan 5 minuten
        if ((time() - (int)filemtime($file)) > 300) {
            return '';
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            return '';
        }

        $signal       = $data['signal']        ?? 'NEUTRAL';
        $buyPressure  = round((float)($data['buy_pressure']  ?? 0.5) * 100);
        $gasPressure  = $data['gas_pressure']   ?? 'UNKNOWN';
        $txAvg        = (int)($data['tx_count_avg'] ?? 0);
        $rsiAdj       = (int)($data['rsi_adjustment'] ?? 0);
        $pendingTx    = (int)($data['pending_tx'] ?? 0);

        $signalEmoji = match($signal) {
            'BUY_PRESSURE'  => '🟢',
            'SELL_PRESSURE' => '🔴',
            default         => '🟡',
        };

        $rsiNote = $rsiAdj !== 0
            ? sprintf(' | RSI-drempel aanpassing: %+d (automatisch)', $rsiAdj)
            : '';

        return sprintf(
            '%s Signaal: %s | Koop-druk: %d%% | Gas: %s | Gem. transacties/block: %d | Pending: %d%s',
            $signalEmoji, $signal, $buyPressure, $gasPressure, $txAvg, $pendingTx, $rsiNote
        );
    }

    // ── Chain-of-Thought LLM-call ─────────────────────────────────────────

    /**
     * Stuur één Sonnet-call met geforceerde multi-stap Chain-of-Thought.
     * Parse het antwoord in PRO/CON/ZELFKRITIEK/EINDOORDEEL secties.
     *
     * @return array{0: array{verdict: string, confidence: string, reason: string,
     *               pro_args: string[], con_args: string[], self_critique: string}, 1: float}
     */
    private function runChainOfThought(array $proposal, string $contextPack, float $tradingEur): array
    {
        if ($this->container === null) {
            return [$this->neutralResult('Geen AI-container'), 0.0];
        }

        try {
            $selector = new ModelSelectorService($this->basePath);
            $model    = $selector->selectModel($tradingEur, ModelSelectorService::PURPOSE_DEEP_HISTORY_AUDIT);

            $proposalJson = json_encode([
                'side'           => $proposal['side'] ?? '?',
                'rsi'            => $proposal['rsi'] ?? $proposal['signal']['rsi'] ?? 0,
                'sentiment'      => $proposal['sentiment'] ?? 0,
                'price_eur'      => $proposal['price_eur'] ?? 0,
                'amount_eth'     => $proposal['amount_eth'] ?? 0,
                'signal_strength'=> $proposal['signal']['strength'] ?? 0,
                'trading_eur'    => $tradingEur,
            ], JSON_PRETTY_PRINT);

            $systemPrompt = 'Je bent een senior crypto trading analyst met 10 jaar ervaring. '
                . 'Je denkt stap voor stap na (Chain-of-Thought) voordat je een beslissing neemt. '
                . 'Je bent kritisch op je eigen redenering en geeft toe als iets onzeker is.';

            $userPrompt = <<<PROMPT
TRADE VOORSTEL:
{$proposalJson}

CONTEXT PACK (alle beschikbare informatie):
{$contextPack}

Je MOET de volgende 5 stappen VOLLEDIG uitwerken in je antwoord:

STAP 2 — PRO-ARGUMENTEN (schrijf PRECIES 3 redenen VÓÓR deze trade):
PRO_1: [één zin]
PRO_2: [één zin]
PRO_3: [één zin]

STAP 3 — CON-ARGUMENTEN (schrijf PRECIES 3 redenen TÉGEN deze trade):
CON_1: [één zin]
CON_2: [één zin]
CON_3: [één zin]

STAP 4 — ZELFKRITIEK (wees eerlijk: waarom zou dit een slechte trade kunnen zijn?):
SELF_CRITIQUE: [één paragraaf, max 100 woorden]

STAP 5 — EINDOORDEEL (op basis van bovenstaande redenering, niet je gevoel):
CONFIDENCE: High|Medium|Low
VERDICT: APPROVE|VETO
REDEN: [één zin conclusie]

Regels:
- High Confidence = alle PRO-argumenten sterk, zelfkritiek weerlegbaar, ≥80% Monte Carlo
- Medium Confidence = gemengd beeld, twijfel over 1-2 punten → VETO bij Standard Tier
- Low Confidence = dominante CON-argumenten of sterke zelfkritiek → altijd VETO
- STRATEGIST / Director: als CONTEXT PACK STRATEGIST een trend_prediction <= 0 toont of Director HOLD impliceert (tegen DR-01..DR-04), dan VERDICT: VETO ongeacht andere PRO-argumenten.
PROMPT;

            $llm    = new \App\Domain\AI\LlmClient($this->container);
            $result = $llm->callModel($model, $systemPrompt, $userPrompt);
            $text   = (string)($result['content'] ?? '');
            $cost   = (float)($result['cost_eur'] ?? 0.008);

            $selector->recordCost($model, $cost);

            return [$this->parseChainResponse($text, $tradingEur), $cost];
        } catch (\Throwable $e) {
            return [$this->neutralResult('LLM-fout: ' . $e->getMessage()), 0.0];
        }
    }

    /** Parse de gestructureerde Chain-of-Thought response. */
    private function parseChainResponse(string $text, float $tradingEur): array
    {
        $upper = strtoupper($text);

        // PRO-argumenten
        $pros = [];
        foreach (['PRO_1:', 'PRO_2:', 'PRO_3:'] as $key) {
            if (preg_match('/' . preg_quote($key, '/') . '\s*(.+)/i', $text, $m)) {
                $pros[] = trim($m[1]);
            }
        }

        // CON-argumenten
        $cons = [];
        foreach (['CON_1:', 'CON_2:', 'CON_3:'] as $key) {
            if (preg_match('/' . preg_quote($key, '/') . '\s*(.+)/i', $text, $m)) {
                $cons[] = trim($m[1]);
            }
        }

        // Zelfkritiek
        $selfCritique = '';
        if (preg_match('/SELF_CRITIQUE:\s*(.+?)(?=\n\n|\nSTAP|CONFIDENCE:)/si', $text, $m)) {
            $selfCritique = trim($m[1]);
        }

        // Confidence
        $confidence = 'Low';
        if (str_contains($upper, 'CONFIDENCE: HIGH')) {
            $confidence = 'High';
        } elseif (str_contains($upper, 'CONFIDENCE: MEDIUM')) {
            $confidence = 'Medium';
        }

        // Reden
        $reason = '';
        if (preg_match('/REDEN:\s*(.+)/i', $text, $m)) {
            $reason = trim($m[1]);
        }

        // Verdict — Standard Tier (€100+): alleen High Confidence mag door
        $rawVerdict = str_contains($upper, 'VERDICT: VETO') ? 'VETO' : 'APPROVE';
        $verdict = $rawVerdict;

        if ($verdict === 'APPROVE') {
            if ($tradingEur >= 100.0 && $confidence !== 'High') {
                $verdict = 'VETO';
                $reason  = "Deep Reasoning: {$confidence} Confidence — Standard Tier vereist High. " . $reason;
            } elseif ($confidence === 'Low') {
                $verdict = 'VETO';
                $reason  = "Deep Reasoning: Low Confidence — altijd VETO. " . $reason;
            }
        }

        return [
            'verdict'      => $verdict,
            'confidence'   => $confidence,
            'reason'       => $reason ?: 'Geen reden gespecificeerd',
            'pro_args'     => $pros ?: ['PRO-argumenten niet geparset'],
            'con_args'     => $cons ?: ['CON-argumenten niet geparset'],
            'self_critique'=> $selfCritique ?: 'Geen zelfkritiek gegenereerd',
        ];
    }

    private function neutralResult(string $reason): array
    {
        return [
            'verdict'      => 'APPROVE',
            'confidence'   => 'Unknown',
            'reason'       => $reason,
            'pro_args'     => [],
            'con_args'     => [],
            'self_critique'=> '',
        ];
    }
}
