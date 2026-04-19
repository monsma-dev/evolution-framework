<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * ReasoningLogger — schrijft de volledige beslissingsketen van elke trade naar een JSONL feed.
 *
 * Elke stap in de keten (RSI → Sentiment → WhaleWatcher → Validator → Judge → Executor)
 * wordt als één JSON-regel opgeslagen. De Live Reasoning Feed in het admin-dashboard
 * leest dit bestand en toont een scrollbare tijdlijn.
 *
 * Opslag: storage/evolution/trading/reasoning_feed.jsonl
 *
 * Stap-schema:
 * {
 *   "ts":          "2026-04-14T20:00:00+00:00",
 *   "trade_id":    "cr_abc123",
 *   "step":        "RSI_ANALYSE",
 *   "agent":       "Junior",
 *   "persona":     "junior",
 *   "status":      "ok" | "warn" | "veto" | "trade",
 *   "icon":        "📊",
 *   "summary":     "RSI 39 gedetecteerd — oversold signaal",
 *   "data":        { ... }
 * }
 */
final class ReasoningLogger
{
    public const FEED_FILE   = 'storage/evolution/trading/reasoning_feed.jsonl';
    public const MAX_ENTRIES = 500; // Roteer na 500 regels

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Schrijf de volledige beslissingsketen van een trade naar het feed-bestand.
     * Wordt aangeroepen vanuit TradingCourtRecord::record().
     */
    public function writeChain(
        string $tradeId,
        array  $signal,
        array  $oracle,
        array  $validator,
        array  $governance,
        array  $trade
    ): void {
        $steps   = $this->buildChain($tradeId, $signal, $oracle, $validator, $governance, $trade);
        $path    = $this->basePath . '/' . self::FEED_FILE;
        $dir     = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $lines = [];
        foreach ($steps as $step) {
            $lines[] = json_encode($step, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        @file_put_contents($path, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
        $this->rotate($path);
    }

    /**
     * Schrijf één willekeurige stap naar de feed.
     * Gebruik vanuit DeepReasoningService en andere services die losse stappen loggen.
     *
     * @param array{step: string, agent: string, icon: string, summary: string,
     *              status: string, persona: string, trade_id?: string, data?: array} $entry
     */
    public function writeStep(array $entry): void
    {
        $row = [
            'ts'       => date('c'),
            'trade_id' => $entry['trade_id'] ?? 'deep_reasoning_' . date('YmdHis'),
            'step'     => $entry['step']     ?? 'REASONING',
            'agent'    => $entry['agent']    ?? 'Architect',
            'persona'  => $entry['persona']  ?? 'architect',
            'icon'     => $entry['icon']     ?? '🧠',
            'summary'  => $entry['summary']  ?? '',
            'status'   => $entry['status']   ?? 'ok',
            'data'     => $entry['data']     ?? [],
        ];

        $path = $this->basePath . '/' . self::FEED_FILE;
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            $path,
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
        $this->rotate($path);
    }

    /**
     * Haal de meest recente N stappen op (nieuwste eerst).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $path = $this->basePath . '/' . self::FEED_FILE;
        if (!is_file($path)) {
            return [];
        }

        $lines   = array_filter(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $entries = [];

        foreach (array_reverse($lines) as $line) {
            $row = json_decode((string)$line, true);
            if (is_array($row)) {
                $entries[] = $row;
            }
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Haal alle stappen voor een specifieke trade op.
     *
     * @return list<array<string, mixed>>
     */
    public function forTrade(string $tradeId): array
    {
        $path = $this->basePath . '/' . self::FEED_FILE;
        if (!is_file($path)) {
            return [];
        }

        $lines  = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $row = json_decode((string)$line, true);
            if (is_array($row) && ($row['trade_id'] ?? '') === $tradeId) {
                $result[] = $row;
            }
        }

        return $result;
    }

    // ── Chain builder ─────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function buildChain(
        string $tradeId,
        array  $signal,
        array  $oracle,
        array  $validator,
        array  $governance,
        array  $trade
    ): array {
        $ts   = date('c');
        $side = strtoupper($trade['side'] ?? '?');

        $steps = [];

        // ── Stap 1: RSI analyse (Junior) ──────────────────────────────────
        $rsi    = round((float)($signal['rsi']      ?? 0), 2);
        $trend  = (string)($signal['trend']          ?? 'FLAT');
        $str    = (int)($signal['strength']          ?? 0);
        $sigLbl = (string)($signal['signal']         ?? 'HOLD');
        $steps[] = $this->step($tradeId, $ts, 'RSI_ANALYSE', 'Junior', 'junior', '📊',
            "RSI {$rsi} — trend: {$trend} — signaal: {$sigLbl} ({$str}%)",
            $sigLbl === 'HOLD' ? 'warn' : 'ok',
            ['rsi' => $rsi, 'trend' => $trend, 'signal' => $sigLbl, 'strength' => $str]
        );

        // ── Stap 2: Sentiment analyse (Architect) ─────────────────────────
        $sentScore  = round((float)($signal['sentiment_score'] ?? 0), 2);
        $sentLabel  = match(true) {
            $sentScore >= 0.3  => 'Bullish 🟢',
            $sentScore <= -0.3 => 'Bearish 🔴',
            default            => 'Neutraal 🟡',
        };
        $sentStatus = $sentScore <= -0.3 ? 'warn' : 'ok';
        $steps[] = $this->step($tradeId, $ts, 'SENTIMENT_CHECK', 'Architect', 'architect', '🗞️',
            "Sentiment: {$sentLabel} (score: {$sentScore})",
            $sentStatus,
            ['score' => $sentScore, 'label' => $sentLabel]
        );

        // ── Stap 3: Oracle / prijsvalidatie (Junior) ──────────────────────
        $divPct  = round((float)($oracle['divergence_pct'] ?? 0), 2);
        $oracleOk = ($oracle['ok'] ?? true);
        $steps[] = $this->step($tradeId, $ts, 'ORACLE_PRICE_CHECK', 'Junior', 'junior', '🔮',
            $oracleOk
                ? "Prijsbronnen valide — divergentie: {$divPct}%"
                : "⚠️ Prijsdivergentie te groot: {$divPct}%",
            $oracleOk ? 'ok' : 'warn',
            ['divergence_pct' => $divPct, 'ok' => $oracleOk, 'sources' => $oracle['sources'] ?? []]
        );

        // ── Stap 4: Validator heuristiek + AI (Validator Agent) ───────────
        $valVerdict = (string)($validator['verdict'] ?? 'UNKNOWN');
        $valReason  = (string)($validator['reason']  ?? '');
        $valAiUsed  = (bool)($validator['ai_used']   ?? false);
        $steps[] = $this->step($tradeId, $ts, 'VALIDATOR_CHECK', 'Validator', 'risk_manager', '⚖️',
            "Validator: {$valVerdict}" . ($valReason ? " — {$valReason}" : '') . ($valAiUsed ? ' [AI-check]' : ''),
            $valVerdict === 'APPROVE' ? 'ok' : 'veto',
            ['verdict' => $valVerdict, 'reason' => $valReason, 'ai_used' => $valAiUsed]
        );

        if ($valVerdict !== 'APPROVE') {
            // Keten stopt hier
            $steps[] = $this->step($tradeId, $ts, 'TRADE_BLOCKED', 'RiskManager', 'risk_manager', '🛑',
                "Trade geblokkeerd door Validator: {$valReason}",
                'veto',
                ['blocked_by' => 'validator', 'reason' => $valReason]
            );
            return $steps;
        }

        // ── Stap 5: Governance / Judge (Architect) ────────────────────────
        $govVerdict = (string)($governance['verdict']  ?? 'UNKNOWN');
        $votes      = (array)($governance['votes']     ?? []);
        $govCost    = round((float)($governance['cost_eur'] ?? 0), 4);
        $voteCount  = count($votes);
        $steps[] = $this->step($tradeId, $ts, 'GOVERNANCE_CONSENSUS', 'Architect', 'architect', '🏛️',
            "Judge / Consensus: {$govVerdict} ({$voteCount} stemmen) — AI-kosten: €{$govCost}",
            $govVerdict === 'APPROVE' ? 'ok' : 'veto',
            ['verdict' => $govVerdict, 'votes' => $votes, 'cost_eur' => $govCost]
        );

        if ($govVerdict !== 'APPROVE') {
            $steps[] = $this->step($tradeId, $ts, 'TRADE_BLOCKED', 'RiskManager', 'risk_manager', '🛑',
                "Trade geblokkeerd door Governance consensus",
                'veto',
                ['blocked_by' => 'governance']
            );
            return $steps;
        }

        // ── Stap 6: Trade uitgevoerd (Executor) ───────────────────────────
        $amount  = round((float)($trade['amount_eth'] ?? 0), 6);
        $price   = round((float)($trade['price_eur']  ?? 0), 2);
        $mode    = (string)($trade['mode']             ?? 'paper');
        $txHash  = (string)($trade['tx_hash']          ?? '');
        $steps[] = $this->step($tradeId, $ts, 'TRADE_EXECUTED', 'Executor', 'junior', '✅',
            "{$side} {$amount} ETH @ €{$price} [{$mode}]" . ($txHash ? " TX: " . substr($txHash, 0, 12) . "…" : ''),
            'trade',
            ['side' => $side, 'amount_eth' => $amount, 'price_eur' => $price, 'mode' => $mode, 'tx_hash' => $txHash]
        );

        return $steps;
    }

    /** @return array<string, mixed> */
    private function step(
        string $tradeId,
        string $ts,
        string $step,
        string $agent,
        string $persona,
        string $icon,
        string $summary,
        string $status,
        array  $data = []
    ): array {
        return [
            'ts'       => $ts,
            'trade_id' => $tradeId,
            'step'     => $step,
            'agent'    => $agent,
            'persona'  => $persona,
            'icon'     => $icon,
            'summary'  => $summary,
            'status'   => $status,
            'data'     => $data,
        ];
    }

    /** Roteer het bestand als het te groot wordt (bewaar laatste MAX_ENTRIES regels). */
    private function rotate(string $path): void
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= self::MAX_ENTRIES) {
            return;
        }

        $keep = array_slice($lines, -self::MAX_ENTRIES);
        @file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
    }
}
