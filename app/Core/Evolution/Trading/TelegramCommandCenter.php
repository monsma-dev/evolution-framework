<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use Psr\Container\ContainerInterface;

/**
 * TelegramCommandCenter — Verwerkt inkomende Telegram bot-commando's.
 *
 * Commando's:
 *   /status       — Toon saldo van Main, Trading en Vault + totale winst naar Vault gestuurd.
 *   /trade_logic  — Toon huidige RSI en Sentiment-score.
 *   /help         — Commando-overzicht.
 *
 * Installatie:
 *   1. Stel TELEGRAM_BOT_TOKEN en TELEGRAM_CHAT_ID in .env in.
 *   2. Voeg een route toe: POST /webhook/telegram-bot → EvolutionTelegramController::webhook()
 *   3. Registreer webhook bij Telegram:
 *      https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://jouwdomein.nl/webhook/telegram-bot
 *
 * Of gebruik polling via: php ai_bridge.php evolve:wallet telegram:poll
 */
final class TelegramCommandCenter
{
    private const API_URL = 'https://api.telegram.org/bot%s/sendMessage';
    private const TIMEOUT = 6;

    private string              $token;
    private string              $allowedChatId;
    private ?ContainerInterface $container;
    private string              $basePath;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null)
    {
        $this->container = $container;
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));

        // Lees credentials uit evolution.json (JSON-config regel) met getenv() fallback
        [$token, $chatId]    = $this->loadCredentials();
        $this->token         = $token;
        $this->allowedChatId = $chatId;
    }

    /** @return array{0: string, 1: string} */
    private function loadCredentials(): array
    {
        $file = $this->basePath . '/config/evolution.json';
        if (is_file($file)) {
            $cfg = json_decode((string)file_get_contents($file), true);
            if (is_array($cfg)) {
                $token  = trim((string)(
                    $cfg['telegram']['bot_token']              ??
                    $cfg['notifications']['telegram']['bot_token'] ??
                    ''
                ));
                $chatId = trim((string)(
                    $cfg['telegram']['chat_id']              ??
                    $cfg['notifications']['telegram']['chat_id'] ??
                    ''
                ));
                if ($token !== '' && $chatId !== '') {
                    return [$token, $chatId];
                }
            }
        }
        // Fallback op omgevingsvariabelen (legacy)
        return [
            trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: '')),
            trim((string)(getenv('TELEGRAM_CHAT_ID')   ?: '')),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->allowedChatId !== '';
    }

    /**
     * Stuur een testbericht naar de geconfigureerde chat.
     * Aanroepen vanuit AdminEvolutionDashboardController::telegramTest().
     */
    public function sendTestMessage(string $text = ''): bool
    {
        if ($text === '') {
            $text = "✅ <b>Telegram Verbindingstest</b>\n\nDe agent kan jou bereiken.\nTijdstip: " . date('d-m-Y H:i:s');
        }
        return $this->send($this->allowedChatId, $text);
    }

    /**
     * Verwerk een binnenkomende Telegram update (JSON payload).
     *
     * @param array<string, mixed> $update
     */
    public function handleUpdate(array $update): bool
    {
        // ── Inline keyboard callbacks ──────────────────────────────────────
        if (isset($update['callback_query'])) {
            return $this->handleCallbackQuery($update['callback_query']);
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return false;
        }

        $chatId = (string)($message['chat']['id'] ?? '');
        $text   = trim((string)($message['text'] ?? ''));

        // Beveilig: alleen berichten van de geconfigureerde chat-ID
        if ($this->allowedChatId !== '' && $chatId !== $this->allowedChatId) {
            return false;
        }

        $command = strtolower(strtok($text, ' ') ?: '');

        $reply = match ($command) {
            '/status'      => $this->commandStatus(),
            '/trade_logic' => $this->commandTradeLogic(),
            '/vacation'    => $this->commandVacation(),
            '/wake'        => $this->commandWake(),
            '/study'       => $this->commandStudy(),
            '/observer'    => $this->commandObserver(),
            '/backtest'    => $this->commandBacktest(),
            '/help'        => $this->commandHelp(),
            default        => null,
        };

        if ($reply === null) {
            return false;
        }

        return $this->send($chatId, $reply);
    }

    // ── Commando-handlers ────────────────────────────────────────────────

    private function commandStatus(): string
    {
        $feed  = new PriceFeedService($this->basePath);
        $price = $feed->getCurrentPrice('ethereum', 'eur');
        if ($price <= 0) {
            $price = 2400.0;
        }

        $cm      = new CapitalManager($this->container, $this->basePath);
        $summary = $cm->balanceSummary($price);

        $agentEur        = (float)($summary['agent']['eur']   ?? 0);
        $agentEth        = (float)($summary['agent']['eth']   ?? 0);
        $tradingEur      = (float)($summary['trading']['eur'] ?? 0);
        $tradingEth      = (float)($summary['trading']['eth'] ?? 0);
        $vaultEur        = (float)($summary['vault']['eur']   ?? 0);
        $vaultEth        = (float)($summary['vault']['eth']   ?? 0);
        $profitEur       = (float)($summary['vault_transferred_eur'] ?? 0);
        $ethPrice        = (float)($summary['eth_price_eur']  ?? $price);
        $vaultTrigger    = (float)($summary['capital_rules']['vault_trigger_eur'] ?? 120.0);
        $vaultFloor      = (float)($summary['capital_rules']['vault_floor_eur']   ?? 100.0);

        // ── Progress to Vault balk ─────────────────────────────
        $progressRatio = $vaultTrigger > 0 ? min(1.0, $tradingEur / $vaultTrigger) : 0.0;
        $filled        = (int)round($progressRatio * 10);
        $bar           = str_repeat('█', $filled) . str_repeat('░', 10 - $filled);
        $remaining     = max(0.0, $vaultTrigger - $tradingEur);
        $progressPct   = (int)round($progressRatio * 100);

        // ── Performance ───────────────────────────────────────
        $ledger      = new TradingLedger($this->basePath);
        $todayFlow   = $ledger->todayNetFlowEur();
        $flowPrefix  = $todayFlow >= 0 ? '+' : '';
        $flowFormatted = $flowPrefix . number_format($todayFlow, 2);

        $agentEurFmt   = number_format($agentEur, 2);
        $tradingEurFmt = number_format($tradingEur, 2);
        $vaultEurFmt   = number_format($vaultEur, 2);
        $profitFmt     = number_format($profitEur, 2);
        $remainingFmt  = number_format($remaining, 2);
        $ethPriceFmt   = number_format($ethPrice, 2);

        // ── Agent State ─────────────────────────────────────────
        $stateManager = new AgentStateManager($this->basePath);
        $stateInfo    = $stateManager->stateInfo();
        $stateEmoji   = match ($stateInfo['state']) {
            AgentStateManager::STATE_TRADING  => '🟢',
            AgentStateManager::STATE_RESTING  => '🛌',
            AgentStateManager::STATE_STUDYING => '📚',
            AgentStateManager::STATE_VACATION => '🏖️',
            default                           => '⚪',
        };
        $stateLabel = $stateInfo['state'];
        $stateReason = $stateInfo['reason'] ? "\n   <i>{$stateInfo['reason']}</i>" : '';
        $stateResume = '';
        if ($stateInfo['remaining_secs'] !== null && $stateInfo['remaining_secs'] > 0) {
            $h = (int)floor($stateInfo['remaining_secs'] / 3600);
            $m = (int)floor(($stateInfo['remaining_secs'] % 3600) / 60);
            $stateResume = "\n   Resume over: <code>{$h}u {$m}m</code>";
        }

        return "💼 <b>Wallet Status</b>\n\n"
            . "{$stateEmoji} <b>Agent Status: {$stateLabel}</b>{$stateReason}{$stateResume}\n\n"
            . "🤖 <b>Agent (Main)</b>\n"
            . "   ETH: <code>{$agentEth}</code>\n"
            . "   EUR: <code>€{$agentEurFmt}</code>\n\n"
            . "📈 <b>Trading</b>\n"
            . "   ETH: <code>{$tradingEth}</code>\n"
            . "   EUR: <code>€{$tradingEurFmt}</code>\n\n"
            . "🏦 <b>Vault</b>\n"
            . "   ETH: <code>{$vaultEth}</code>\n"
            . "   EUR: <code>€{$vaultEurFmt}</code>\n\n"
            . "🎯 <b>Progress to Vault</b>\n"
            . "   [{$bar}] {$progressPct}%\n"
            . "   €{$tradingEurFmt} / €{$vaultTrigger} drempel\n"
            . "   Nog <b>€{$remainingFmt}</b> nodig voor Harvest → Vault (vloer €{$vaultFloor})\n\n"
            . "📉 <b>Performance</b>\n"
            . "   Vandaag: <code>€{$flowFormatted}</code>\n"
            . "   Veiliggesteld in Kluis: <code>€{$profitFmt}</code>\n"
            . "   Volgende harvest bij saldo: <code>€{$vaultTrigger}</code>\n\n"
            . "📊 ETH/EUR: <code>€{$ethPriceFmt}</code>\n"
            . "<i>" . date('d-m-Y H:i') . " UTC</i>";
    }

    private function commandTradeLogic(): string
    {
        $feed  = new PriceFeedService($this->basePath);
        $price = $feed->getCurrentPrice('ethereum', 'eur');

        $history = $feed->getPriceHistory('ethereum', 'eur', 3);
        if (count($history) < 25) {
            return "⚠️ Onvoldoende prijsdata beschikbaar (" . count($history) . " candles).";
        }

        $strategy = new TradingStrategy([], $this->basePath);
        $signal   = $strategy->analyse($history);
        $rsi      = number_format((float)($signal['rsi'] ?? 0), 2);
        $sigLabel = (string)($signal['signal'] ?? 'HOLD');
        $reason   = (string)($signal['reason'] ?? '');
        $trend    = (string)($signal['trend'] ?? 'FLAT');
        $strength = (int)($signal['strength'] ?? 0);

        // Sentiment
        $analyzer      = new SentimentAnalyzer($this->container, $this->basePath);
        $sentimentData = $analyzer->currentSentiment();
        $sentScore     = number_format((float)($sentimentData['score'] ?? 0), 2);
        $sentSource    = (string)($sentimentData['source'] ?? 'keyword');
        $sentLabel     = match (true) {
            (float)$sentScore >= 0.3  => '🟢 Bullish',
            (float)$sentScore <= -0.3 => '🔴 Bearish (STOP)',
            default                   => '🟡 Neutraal',
        };

        $sigEmoji = match ($sigLabel) {
            'BUY'  => '🟢',
            'SELL' => '🔴',
            default => '⚪',
        };

        $blocked = ((float)$sentScore < -0.3 && $sigLabel === 'BUY')
            ? "\n⛔ <b>BUY geblokkeerd door negatief sentiment!</b>"
            : '';

        return "🧠 <b>Trading Logica</b>\n\n"
            . "📉 <b>RSI (1h):</b> <code>{$rsi}</code>\n"
            . "📊 <b>Trend:</b> <code>{$trend}</code>\n"
            . "💪 <b>Signaalsterkte:</b> <code>{$strength}/100</code>\n"
            . "{$sigEmoji} <b>Signaal:</b> <code>{$sigLabel}</code>\n"
            . "<i>{$reason}</i>\n\n"
            . "🗞️ <b>Fysiek Sentiment:</b> {$sentLabel}\n"
            . "   Score: <code>{$sentScore}</code> (bron: {$sentSource})\n"
            . "   Grens: score &lt; -0.30 = trade geblokkeerd{$blocked}\n\n"
            . "💲 ETH/EUR: <code>€" . number_format($price, 2) . "</code>\n"
            . "<i>" . date('d-m-Y H:i') . " UTC</i>";
    }

    private function commandVacation(): string
    {
        $sm = new AgentStateManager($this->basePath);
        $sm->forceState(AgentStateManager::STATE_VACATION, 'Handmatig via Telegram /vacation', 0);
        return "🏖️ <b>Vakantie geactiveerd!</b>\n\n"
            . "De agent is gestopt met handelen.\n"
            . "Gebruik /wake om de handel te hervatten.";
    }

    private function commandWake(): string
    {
        $sm = new AgentStateManager($this->basePath);
        $sm->forceState(AgentStateManager::STATE_TRADING, 'Handmatig gewekt via Telegram /wake');
        return "🟢 <b>Agent gewekt!</b>\n\n"
            . "Terug naar normale TRADING modus.\n"
            . "Volgende tick: signalen worden opnieuw geanalyseerd.";
    }

    private function commandStudy(): string
    {
        $sm      = new AgentStateManager($this->basePath);
        $study   = new StudySessionService(null, $this->basePath);
        $sm->forceState(AgentStateManager::STATE_STUDYING, 'Handmatig gestart via Telegram /study', 2);
        $result  = $study->run();
        $sm->markStudyComplete();

        if ($result['ok']) {
            return "📚 <b>Studie-sessie voltooid!</b>\n\n"
                . "✅ {$result['insights_count']} nieuwe inzichten opgeslagen\n"
                . "💰 Kosten: <code>€{$result['cost_eur']}</code>\n"
                . "<i>{$result['summary']}</i>";
        }

        return "📚 <b>Studie-sessie</b>\n\n⚠️ {$result['summary']}";
    }

    private function commandHelp(): string
    {
        $persona = AgentPersonality::junior();
        return $persona->rawFormat(
            "/status — Saldo van alle wallets + winst + agent state\n"
            . "/trade_logic — Huidige RSI en Sentiment\n"
            . "/vacation — Zet agent in VAKANTIE modus (stop trading)\n"
            . "/wake — Wek agent op uit rust/vakantie\n"
            . "/study — Start handmatige studie-sessie (Sonnet analyse)\n"
            . "/observer — Scan codebase op nieuwe upgrades (Architect Feedback Loop)\n"
            . "/backtest — Wekelijkse RSI-strategie backtest (Architect analyse)\n"
            . "/help — Dit overzicht\n\n"
            . "<i>Commando's zijn alleen beschikbaar voor de geconfigureerde chat-ID.</i>",
            'Evolution Agent Bot'
        );
    }

    // ── Architect Feedback Loop ───────────────────────────────────────────

    /**
     * Stuur een gepersonaliseerd upgrade-bericht naar de Architect via Telegram.
     * Wordt aangeroepen door ArchitectObserver na het detecteren van nieuwe code.
     *
     * @param string $agentMessage  Het door Sonnet gegenereerde bericht (plain tekst, geen HTML)
     * @param string $context       Optionele context-tag (bijv. 'new_tool', 'strategy_change')
     */
    public function notifyArchitectOfUpgrade(string $agentMessage, string $context = 'upgrade'): bool
    {
        if (!$this->isConfigured() || $agentMessage === '') {
            return false;
        }

        $emoji = match ($context) {
            'new_tool'        => '🔧',
            'new_class'       => '🏗️',
            'strategy_change' => '📊',
            'new_method'      => '⚙️',
            default           => '🧠',
        };

        $html = "{$emoji} <b>Agent Feedback — Architect Observer</b>\n\n"
              . htmlspecialchars($agentMessage, ENT_QUOTES, 'UTF-8', false)
              . "\n\n<i>" . date('d-m-Y H:i') . " UTC — Evolution Intelligence</i>";

        return $this->send($this->allowedChatId, $html);
    }

    // ── Commando-handlers (uitbreiding) ───────────────────────────────────

    private function commandBacktest(): string
    {
        try {
            $backtester = new StrategyBacktester($this->container, $this->basePath);
            $result     = $backtester->run();

            $persona    = AgentPersonality::architect();

            if (!$result['ok']) {
                return $persona->rawFormat(
                    "⚠️ Backtest kon niet worden uitgevoerd:\n<i>{$result['summary']}</i>",
                    'Strategy Backtest'
                );
            }

            $arrow  = $result['best_rsi'] < $result['current_rsi'] ? '⬇️' : ($result['best_rsi'] > $result['current_rsi'] ? '⬆️' : '↔️');
            $sign   = $result['improvement_pct'] >= 0 ? '+' : '';

            return $persona->rawFormat(
                "📊 <b>Strategy Backtest voltooid</b>\n\n"
                . "Huidige RSI-drempel: <code>{$result['current_rsi']}</code>\n"
                . "Beste alternatief: <code>{$result['best_rsi']}</code> {$arrow}\n"
                . "Verwachte verbetering: <b>{$sign}{$result['improvement_pct']}%</b> win-rate\n\n"
                . "<b>Agent advies:</b>\n"
                . htmlspecialchars($result['advice'], ENT_QUOTES, 'UTF-8', false) . "\n\n"
                . "💰 AI-kosten: <code>€{$result['cost_eur']}</code>",
                'Strategy Backtest'
            );
        } catch (\Throwable $e) {
            return AgentPersonality::riskManager()->rawFormat(
                '⚠️ Backtest-fout: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES),
                'Strategy Backtest Error'
            );
        }
    }

    private function commandObserver(): string
    {
        try {
            $observer = new \App\Core\Evolution\Intelligence\ArchitectObserver($this->container, $this->basePath);
            $result   = $observer->run();

            if ($result['new_classes'] === 0 && $result['new_methods'] === 0 && $result['strategy_changes'] === 0) {
                return "🧠 <b>Architect Observer</b>\n\n"
                     . "✅ Geen nieuwe upgrades gedetecteerd.\n"
                     . "De codebase is ongewijzigd ten opzichte van de vorige sessie.";
            }

            return "🧠 <b>Architect Observer — Resultaat</b>\n\n"
                 . "🏗️ Nieuwe klassen: <code>{$result['new_classes']}</code>\n"
                 . "⚙️ Nieuwe methoden: <code>{$result['new_methods']}</code>\n"
                 . "📊 Strategie-wijzigingen: <code>{$result['strategy_changes']}</code>\n"
                 . "💰 AI-kosten: <code>€{$result['cost_eur']}</code>\n\n"
                 . "<i>{$result['summary']}</i>\n\n"
                 . "✉️ Persoonlijk bericht verstuurd naar dit gesprek.";
        } catch (\Throwable $e) {
            return "🧠 <b>Architect Observer</b>\n\n⚠️ Fout: " . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    // ── Inline Keyboard Callback Handler ─────────────────────────────────

    /** @param array<string, mixed> $callbackQuery */
    private function handleCallbackQuery(array $callbackQuery): bool
    {
        $chatId       = (string)($callbackQuery['message']['chat']['id'] ?? '');
        $callbackData = (string)($callbackQuery['data'] ?? '');
        $callbackId   = (string)($callbackQuery['id'] ?? '');

        // Beveilig: alleen geconfigureerde chat
        if ($this->allowedChatId !== '' && $chatId !== $this->allowedChatId) {
            return false;
        }

        // Bevestig callback aan Telegram (anders blijft spinner staan)
        $this->answerCallbackQuery($callbackId);

        // ── Estate callbacks ────────────────────────────────────────────
        if (in_array($callbackData, ['estate_alive', 'estate_confirm'], true)) {
            $estate = new \App\Core\Evolution\Intelligence\AgentEstate($this->basePath);
            $result = $estate->handleTelegramCallback($callbackData);
            return true;
        }

        // ── Strategy optimization callbacks ────────────────────────────
        if ($callbackData === 'strategy_accept') {
            try {
                $optimizer = new \App\Core\Evolution\Intelligence\StrategyOptimizer($this->container, $this->basePath);
                $proposals = $optimizer->recentProposals(1);
                if (!empty($proposals[0])) {
                    $p = $proposals[0];
                    $reply = "✅ <b>Optimalisatie Geaccepteerd</b>\n\nRSI: {$p['current_rsi']} → {$p['proposed_rsi']}\nTP: {$p['current_tp']}% → {$p['proposed_tp']}%\n\nInstellingen worden bij de volgende nachtelijke studie toegepast.";
                } else {
                    $reply = '⚠️ Geen recent voorstel gevonden.';
                }
            } catch (\Throwable $e) {
                $reply = '⚠️ Fout bij accepteren: ' . $e->getMessage();
            }
            return $this->send($chatId, $reply);
        }

        if ($callbackData === 'strategy_reject') {
            return $this->send($chatId, '❌ <b>Optimalisatie Genegeerd</b>\n\nHuidige strategie-instellingen blijven actief.');
        }

        return false;
    }

    private function answerCallbackQuery(string $callbackId): void
    {
        if (!$this->isConfigured() || $callbackId === '') {
            return;
        }
        $url  = sprintf('https://api.telegram.org/bot%s/answerCallbackQuery', rawurlencode($this->token));
        $body = json_encode(['callback_query_id' => $callbackId]);
        if ($body === false) {
            return;
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => 4,
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
    }

    // ── Telegram API ─────────────────────────────────────────────────────

    private function send(string $chatId, string $html): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $url  = sprintf(self::API_URL, rawurlencode($this->token));
        $body = json_encode([
            'chat_id'                  => $chatId,
            'text'                     => $html,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if ($body === false) {
            return false;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($body),
                'content'       => $body,
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            return false;
        }

        $json = json_decode($result, true);
        return is_array($json) && ($json['ok'] ?? false) === true;
    }
}
