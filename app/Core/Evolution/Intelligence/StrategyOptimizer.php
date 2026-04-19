<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

use App\Core\Evolution\Trading\StrategyBacktester;
use App\Core\Evolution\Trading\TradeMemoryService;
use App\Core\Evolution\Trading\AgentPersonality;
use Psr\Container\ContainerInterface;

/**
 * StrategyOptimizer — De "Auto-Tune" Engine.
 *
 * Werking (nachtelijk, vanuit StudySessionService):
 *   1. Vraagt StrategyBacktester om alle RSI-drempels (35–45) te simuleren.
 *   2. Test aanvullend alle TP-niveaus (1.0% – 5.0% in stappen van 0.5%).
 *   3. Vergelijkt gecombineerde score: win_rate × simulated_pnl_pct.
 *   4. Genereert een OptimizationProposal (best RSI + TP combinatie).
 *   5. Laat Sonnet uitleggen waarom de nieuwe instelling beter is.
 *   6. Beslist op basis van entity_mode:
 *      - "Particulier" → auto-apply in evolution.json + Telegram bevestiging.
 *      - "Bedrijf"     → Telegram met inline knoppen [ACCEPTEER] / [NEGEER].
 *   7. Sla voorstel op in optimization_proposals.jsonl (historiek).
 *
 * Opslag:
 *   storage/evolution/intelligence/optimization_proposals.jsonl
 */
final class StrategyOptimizer
{
    private const PROPOSALS_FILE  = 'storage/evolution/intelligence/optimization_proposals.jsonl';
    private const CONFIG_FILE     = 'config/evolution.json';
    private const MODEL_SONNET    = 'claude-sonnet-4-5';

    private const TP_TEST_RANGE   = [1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 5.0];
    private const RSI_TEST_RANGE  = [35, 37, 38, 39, 40, 41, 42, 43, 45];

    private ?ContainerInterface $container;
    private string $basePath;

    public function __construct(?ContainerInterface $container, string $basePath)
    {
        $this->container = $container;
        $this->basePath  = $basePath;
    }

    /**
     * Voer een volledige strategy-optimalisatie uit.
     *
     * @return array{ok: bool, proposal: array, applied: bool, cost_eur: float, summary: string}
     */
    public function run(): array
    {
        $memories = $this->loadRecentMemories(7);

        if (count($memories) < 5) {
            return [
                'ok'       => false,
                'proposal' => [],
                'applied'  => false,
                'cost_eur' => 0.0,
                'summary'  => 'Te weinig trade-herinneringen voor optimalisatie (min 5)',
            ];
        }

        $current = $this->loadCurrentSettings();

        // ── Stap 1: Test alle RSI × TP combinaties ────────────────────────
        $best    = null;
        $allRuns = [];

        foreach (self::RSI_TEST_RANGE as $rsi) {
            foreach (self::TP_TEST_RANGE as $tp) {
                $score   = $this->scoreCombo((float)$rsi, $tp, $memories);
                $allRuns[] = array_merge($score, ['rsi' => (float)$rsi, 'tp_pct' => $tp]);

                if ($best === null || $score['combined_score'] > $best['combined_score']) {
                    $best = array_merge($score, ['rsi' => (float)$rsi, 'tp_pct' => $tp]);
                }
            }
        }

        // ── Stap 1b: Architect profiel RSI-aanpassing ─────────────────────
        $profileAdj   = (new \App\Core\Evolution\Intelligence\ArchitectProfile($this->basePath))->rsiAdjustment();
        // Pas de winnende RSI aan op basis van doelen (clamp op test-bereik)
        if ($best !== null && $profileAdj !== 0.0) {
            $best['rsi']     = max(35.0, min(45.0, $best['rsi'] + $profileAdj));
            $best['profile_adj'] = $profileAdj;
        }

        // ── Stap 2: Baseline (huidige instellingen) ───────────────────────
        $baseline = $this->scoreCombo($current['rsi_buy'], $current['tp_level_1_pct'], $memories);

        // Significante verbetering vereist (>5%)
        $improvement = $baseline['combined_score'] > 0
            ? round(($best['combined_score'] - $baseline['combined_score']) / $baseline['combined_score'] * 100, 1)
            : 0.0;

        // ── Stap 3: Sonnet-verklaring ──────────────────────────────────────
        [$explanation, $cost] = $this->generateExplanation($current, $best, $baseline, $improvement, $memories);

        // ── Stap 4: Voorstel opbouwen ─────────────────────────────────────
        $proposal = [
            'ts'               => date('c'),
            'current_rsi'      => $current['rsi_buy'],
            'current_tp'       => $current['tp_level_1_pct'],
            'proposed_rsi'     => $best['rsi'],
            'proposed_tp'      => $best['tp_pct'],
            'improvement_pct'  => $improvement,
            'baseline_score'   => round($baseline['combined_score'], 4),
            'proposed_score'   => round($best['combined_score'], 4),
            'best_win_rate'    => $best['win_rate'],
            'baseline_win_rate'=> $baseline['win_rate'],
            'memories_used'    => count($memories),
            'explanation'      => $explanation,
            'status'           => 'pending',
        ];

        $this->appendProposal($proposal);

        // ── Stap 5: Autonoom beslissen of toepassen ───────────────────────
        $applied    = false;
        $entityMode = $this->loadEntityMode();

        if ($improvement >= 5.0) { // Alleen toepassen bij zinvolle verbetering
            if ($entityMode === 'Particulier') {
                $applied = $this->applySettings($best['rsi'], $best['tp_pct']);
                $this->notifyTelegramApplied($proposal, $applied);
            } else {
                $this->notifyTelegramProposal($proposal);
            }
        } else {
            $this->notifyTelegramNoChange($current, $improvement);
        }

        return [
            'ok'       => true,
            'proposal' => $proposal,
            'applied'  => $applied,
            'cost_eur' => round($cost, 4),
            'summary'  => sprintf(
                'Optimalisatie: RSI %s→%s, TP %s%%→%s%% (%+.1f%% verbetering)%s',
                $current['rsi_buy'], $best['rsi'],
                $current['tp_level_1_pct'], $best['tp_pct'],
                $improvement,
                $applied ? ' — AUTOMATISCH TOEGEPAST' : ''
            ),
        ];
    }

    /**
     * Laad de meest recente proposals (voor het dashboard).
     *
     * @return list<array<string, mixed>>
     */
    public function recentProposals(int $limit = 14): array
    {
        $file = $this->basePath . '/' . self::PROPOSALS_FILE;
        if (!is_file($file)) {
            return [];
        }

        $lines  = array_filter(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $result = [];

        foreach (array_reverse($lines) as $line) {
            $p = json_decode((string)$line, true);
            if (is_array($p)) {
                $result[] = $p;
            }
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    // ── Scoring ────────────────────────────────────────────────────────────

    /**
     * Bereken een gecombineerde score voor RSI × TP combinatie op historische data.
     *
     * Score = win_rate × simulated_pnl_pct (hoger = beter)
     *
     * @param list<array<string, mixed>> $memories
     * @return array{win_rate: float, simulated_pnl_pct: float, total_trades: int, combined_score: float}
     */
    private function scoreCombo(float $rsiThreshold, float $tpPct, array $memories): array
    {
        $trades = 0;
        $wins   = 0;
        $pnl    = 0.0;

        foreach ($memories as $m) {
            $entryRsi  = (float)($m['rsi_at_entry'] ?? 50.0);
            $outcomePct = (float)($m['outcome_pct']  ?? 0.0);
            $side      = strtoupper((string)($m['side'] ?? 'BUY'));

            if ($side !== 'BUY') {
                continue;
            }

            if ($entryRsi > $rsiThreshold) {
                continue; // Dit signaal had deze RSI-drempel niet gehaald
            }

            $trades++;
            // Simuleer effect van TP: cap winst op tpPct
            $effectivePnl = min($outcomePct, $tpPct);
            if ($effectivePnl > 0) {
                $wins++;
            }
            $pnl += $effectivePnl;
        }

        $winRate = $trades > 0 ? round($wins / $trades * 100, 1) : 0.0;
        $avgPnl  = $trades > 0 ? round($pnl / $trades, 3)        : 0.0;

        return [
            'win_rate'          => $winRate,
            'simulated_pnl_pct' => round($pnl, 3),
            'avg_pnl_pct'       => $avgPnl,
            'total_trades'      => $trades,
            'combined_score'    => $winRate > 0 ? round($winRate * $avgPnl, 4) : 0.0,
        ];
    }

    // ── AI verklaring ──────────────────────────────────────────────────────

    /** @return array{0: string, 1: float} */
    private function generateExplanation(
        array $current,
        array $best,
        array $baseline,
        float $improvement,
        array $memories
    ): array {
        if ($this->container === null) {
            return ['Geen AI beschikbaar — voorstel gebaseerd op kwantitatieve analyse.', 0.0];
        }

        try {
            $llm    = new \App\Domain\AI\LlmClient($this->container);
            $prompt = "Je bent een kwantitatieve trading-analist. Leg in MAX 3 zinnen uit waarom de nieuwe strategie beter is.\n\n"
                . "HUIDIGE STRATEGIE: RSI-drempel={$current['rsi_buy']}, TP={$current['tp_level_1_pct']}%\n"
                . "Win-rate baseline: {$baseline['win_rate']}% | Gesimuleerde P&L: {$baseline['simulated_pnl_pct']}%\n\n"
                . "NIEUWE STRATEGIE: RSI-drempel={$best['rsi']}, TP={$best['tp_pct']}%\n"
                . "Win-rate nieuw: {$best['win_rate']}% | Gesimuleerde P&L: {$best['simulated_pnl_pct']}%\n"
                . "Verbetering: {$improvement}%\n\n"
                . "Analyseer " . count($memories) . " trades van de afgelopen week.\n"
                . "Schrijf in het Nederlands. Gebruik concrete getallen. Wees specifiek over marktomstandigheden.";

            $result = $llm->callModel(self::MODEL_SONNET, 'Je bent een kwantitatieve trading-analist.', $prompt);
            return [trim((string)($result['content'] ?? '')), (float)($result['cost_eur'] ?? 0.008)];
        } catch (\Throwable) {
            return ['Kwantitatieve analyse toont verbetering op basis van historische trade-data.', 0.0];
        }
    }

    // ── Config lezen / schrijven ───────────────────────────────────────────

    /** @return array{rsi_buy: float, tp_level_1_pct: float, tp_level_2_pct: float} */
    private function loadCurrentSettings(): array
    {
        $file = $this->basePath . '/' . self::CONFIG_FILE;
        if (!is_file($file)) {
            return ['rsi_buy' => 40.0, 'tp_level_1_pct' => 1.5, 'tp_level_2_pct' => 3.0];
        }

        $cfg = json_decode((string)file_get_contents($file), true);
        return [
            'rsi_buy'        => (float)(($cfg['trading']['strategy']['rsi_buy']        ?? null) ?? 40.0),
            'tp_level_1_pct' => (float)(($cfg['trading']['strategy']['tp_level_1_pct'] ?? null) ?? 1.5),
            'tp_level_2_pct' => (float)(($cfg['trading']['strategy']['tp_level_2_pct'] ?? null) ?? 3.0),
        ];
    }

    private function loadEntityMode(): string
    {
        $file = $this->basePath . '/' . self::CONFIG_FILE;
        if (!is_file($file)) {
            return 'Particulier';
        }
        $cfg = json_decode((string)file_get_contents($file), true);
        return (string)(($cfg['entity_mode'] ?? 'Particulier'));
    }

    /** Pas RSI + TP direct toe in evolution.json. */
    private function applySettings(float $rsi, float $tp): bool
    {
        $file = $this->basePath . '/' . self::CONFIG_FILE;
        if (!is_file($file)) {
            return false;
        }

        $cfg = json_decode((string)file_get_contents($file), true);
        if (!is_array($cfg)) {
            return false;
        }

        if (!isset($cfg['trading'])) {
            $cfg['trading'] = [];
        }
        if (!isset($cfg['trading']['strategy'])) {
            $cfg['trading']['strategy'] = [];
        }

        $cfg['trading']['strategy']['rsi_buy']        = $rsi;
        $cfg['trading']['strategy']['tp_level_1_pct'] = $tp;
        $cfg['trading']['strategy']['tp_level_2_pct'] = round($tp * 2.0, 1);
        $cfg['trading']['strategy']['_last_optimized'] = date('c');

        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        @file_put_contents($file, $json . "\n", LOCK_EX);
        return true;
    }

    /** Voeg voorstel toe aan de proposals-log. */
    private function appendProposal(array $proposal): void
    {
        $file = $this->basePath . '/' . self::PROPOSALS_FILE;
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            $file,
            json_encode($proposal, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    // ── Telegram notificaties ─────────────────────────────────────────────

    private function notifyTelegramApplied(array $proposal, bool $success): void
    {
        $arrow     = $proposal['proposed_rsi'] < $proposal['current_rsi'] ? '⬇️' : '⬆️';
        $statusEmoji = $success ? '✅' : '⚠️';
        $text = sprintf(
            "🤖 <b>Auto-Tune Engine</b> — Strategie %s\n\n"
            . "RSI-drempel: <code>%s</code> → <code>%s</code> %s\n"
            . "TP-niveau: <code>%s%%</code> → <code>%s%%</code>\n"
            . "Verbetering: <b>%+.1f%%</b>\n\n"
            . "📖 <i>%s</i>",
            $success ? 'automatisch bijgewerkt' : 'update mislukt',
            $proposal['current_rsi'], $proposal['proposed_rsi'], $arrow,
            $proposal['current_tp'], $proposal['proposed_tp'],
            $proposal['improvement_pct'],
            substr($proposal['explanation'] ?? '', 0, 300)
        );

        $this->sendTelegram($text);
    }

    private function notifyTelegramProposal(array $proposal): void
    {
        $text = sprintf(
            "🏢 <b>Strategie Optimalisatie Voorstel</b>\n\n"
            . "RSI: <code>%s</code> → <code>%s</code>\n"
            . "TP: <code>%s%%</code> → <code>%s%%</code>\n"
            . "Verwachte verbetering: <b>%+.1f%%</b>\n\n"
            . "📖 %s\n\n"
            . "Reageer via het /admin/evolution dashboard.",
            $proposal['current_rsi'], $proposal['proposed_rsi'],
            $proposal['current_tp'], $proposal['proposed_tp'],
            $proposal['improvement_pct'],
            substr($proposal['explanation'] ?? '', 0, 280)
        );

        // Inline keyboard [ACCEPTEER] / [NEGEER]
        $this->sendTelegramWithKeyboard($text, [
            [
                ['text' => '✅ ACCEPTEER OPTIMALISATIE', 'callback_data' => 'strategy_accept'],
                ['text' => '❌ NEGEER',                  'callback_data' => 'strategy_reject'],
            ],
        ]);
    }

    private function notifyTelegramNoChange(array $current, float $improvement): void
    {
        $text = sprintf(
            "📊 <b>Auto-Tune Engine</b> — Geen wijziging\n\n"
            . "Huidige instellingen blijven optimaal (RSI=%s, TP=%s%%)\n"
            . "Maximale verbetering gevonden: <b>%+.1f%%</b> — onder de drempel van 5%%",
            $current['rsi_buy'], $current['tp_level_1_pct'], $improvement
        );
        $this->sendTelegram($text);
    }

    private function sendTelegram(string $text): void
    {
        [$token, $chatId] = $this->getTelegramCredentials();
        if ($token === '' || $chatId === '') {
            return;
        }

        $body = json_encode([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if ($body === false) {
            return;
        }

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', rawurlencode($token));
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => 6,
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
    }

    private function sendTelegramWithKeyboard(string $text, array $keyboard): void
    {
        [$token, $chatId] = $this->getTelegramCredentials();
        if ($token === '' || $chatId === '') {
            return;
        }

        $body = json_encode([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => ['inline_keyboard' => $keyboard],
        ]);

        if ($body === false) {
            return;
        }

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', rawurlencode($token));
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => 6,
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
    }

    /** @return array{0: string, 1: string} */
    private function getTelegramCredentials(): array
    {
        $file = $this->basePath . '/' . self::CONFIG_FILE;
        $cfg  = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];

        $token  = trim((string)(($cfg['telegram']['bot_token'] ?? null) ?: (getenv('TELEGRAM_BOT_TOKEN') ?: '')));
        $chatId = trim((string)(($cfg['telegram']['chat_id']   ?? null) ?: (getenv('TELEGRAM_CHAT_ID')   ?: '')));

        return [$token, $chatId];
    }

    // ── Data laden ────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function loadRecentMemories(int $daysBack): array
    {
        $file   = $this->basePath . '/data/evolution/trading/trade_memories.jsonl';
        if (!is_file($file)) {
            return [];
        }

        $cutoff = time() - $daysBack * 86400;
        $lines  = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $m = json_decode((string)$line, true);
            if (!is_array($m)) {
                continue;
            }
            $ts = is_numeric($m['ts'] ?? null) ? (int)$m['ts'] : strtotime((string)($m['ts'] ?? ''));
            if ($ts < $cutoff || $m['outcome_pct'] === null) {
                continue;
            }
            $result[] = $m;
        }

        return $result;
    }
}
