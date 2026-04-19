<?php

declare(strict_types=1);

namespace App\Core\Evolution\Wallet;

/**
 * TelegramService — Send messages via Telegram Bot API.
 *
 * Configuration (evolution.json or env vars):
 *   TELEGRAM_BOT_TOKEN  — from @BotFather
 *   TELEGRAM_CHAT_ID    — your personal chat ID or group ID
 *
 * Setup:
 *   1. Create bot: message @BotFather on Telegram → /newbot
 *   2. Get token: e.g. 1234567890:ABCdef...
 *   3. Get chat ID: message @userinfobot or check the getUpdates API
 *   4. Set env vars: TELEGRAM_BOT_TOKEN=... TELEGRAM_CHAT_ID=...
 */
final class TelegramService
{
    private const API_BASE = 'https://api.telegram.org/bot';
    private const TIMEOUT  = 6;

    private string $token;
    private string $chatId;

    public function __construct()
    {
        $this->token  = (string)(getenv('TELEGRAM_BOT_TOKEN') ?: $this->configValue('telegram.bot_token'));
        $this->chatId = (string)(getenv('TELEGRAM_CHAT_ID')   ?: $this->configValue('telegram.chat_id'));
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }

    /** Send a plain text message. Returns true on success. */
    public function send(string $text, bool $silent = false): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        return $this->post('sendMessage', [
            'chat_id'              => $this->chatId,
            'text'                 => $text,
            'parse_mode'           => 'HTML',
            'disable_notification' => $silent,
        ]);
    }

    /** Na evolve:trade run --force: unlock-bevestiging met wallet + saldo op Base. */
    public function notifySystemUnlocked(string $tradingAddress, float $balanceEth): bool
    {
        $b = number_format($balanceEth, 4);
        $text = 'System Live - Balance: ' . $b . ' ETH (Base)';

        return $this->send($text);
    }

    /** Na evolve:trade verify: systeem online met actueel RPC-saldo (Base). */
    public function notifySystemOnline(float $balanceEth): bool
    {
        $b = number_format($balanceEth, 4);

        return $this->send('System Online - Balance: ' . $b . ' ETH');
    }

    /** Na evolve:trade verify: bevestiging op Base met RPC-saldo + scan-start. */
    public function notifyBaseSystemOnlineScanning(float $balanceEth): bool
    {
        $b = number_format($balanceEth, 4);

        return $this->send(
            '🚀 Systeem Online op Base. Saldo ' . $b . ' ETH gedetecteerd. Start scannen.'
        );
    }

    /** Na evolve:wallet verify --fix-metadata wanneer integriteit weer OK is. */
    public function notifyAgentIntegrityRestored(): bool
    {
        return $this->send(
            '✅ Agent Integriteit Hersteld. De sleutel en het adres op de server matchen nu. Systeem is klaar voor gebruik.'
        );
    }

    /** Future-Sight deep scan (evolve:trade future-sight): korte slotregel, geen log-dump. */
    public function notifyFutureSightComplete(string $directionNl): bool
    {
        $d = strtoupper(trim($directionNl));
        if ($d !== 'STIJGING' && $d !== 'DALING') {
            $d = 'STIJGING';
        }

        return $this->send(
            '🧠 AI Analyse voltooid. Marktvoorspelling: ' . $d . '. Eerste winstdoel ingesteld.'
        );
    }

    /** Ethereum mainnet (chain 1): live + saldo + scan (evolve:trade verify). */
    public function notifyEthereumMainnetLive(float $balanceEth): bool
    {
        $b = number_format($balanceEth, 4);

        return $this->send(
            '⚡ Systeem Live op Ethereum Mainnet. Saldo ' . $b . ' ETH gedetecteerd. Start scannen voor winst.'
        );
    }

    /** evolve:trade run --force: meldt RPC-saldo op het trading-adres (TRADING_WALLET_ADDRESS). */
    public function notifySystemForceOverride(float $balanceEth): bool
    {
        $addr = strtolower(trim((string)(getenv('TRADING_WALLET_ADDRESS') ?: '0x24577d06a0605ac900216fc8b065443fa86416ba')));
        $safe = htmlspecialchars($addr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $b    = number_format($balanceEth, 4);

        return $this->send(
            '🚀 System Live — forced trade run. Balance <b>' . $b . ' ETH (Base)</b> on <code>' . $safe . '</code>.'
        );
    }

    /** Send the "fuel received" notification on incoming transaction. */
    public function notifyFuelReceived(float $ethAmount, string $fromAddress, string $txHash): bool
    {
        $text = "⛽ <b>Brandstof ontvangen!</b>\n\n"
              . "Bedrag: <code>" . round($ethAmount, 6) . " ETH</code>\n"
              . "Van: <code>" . $fromAddress . "</code>\n"
              . "Tx: <code>" . $txHash . "</code>\n\n"
              . "Ik begin direct met de eerste marktscan. 🚀";

        return $this->send($text);
    }

    /** Send gas warning. */
    public function notifyLowGas(float $balance, float $gasPriceGwei): bool
    {
        $text = "⚠️ <b>Lage gas-reserve!</b>\n\n"
              . "Huidig saldo: <code>" . round($balance, 6) . " ETH</code>\n"
              . "Gas prijs: <code>{$gasPriceGwei} Gwei</code>\n\n"
              . "Stort meer ETH op het Base-adres om transacties te kunnen uitvoeren.";

        return $this->send($text);
    }

    /** Send agent startup notification. */
    public function notifyAgentStartup(string $address): bool
    {
        $text = "🧠 <b>Evolution Agent actief</b>\n\n"
              . "Wallet: <code>{$address}</code>\n"
              . "Netwerk: Base (chain 8453)\n\n"
              . "Wachtend op brandstof...";

        return $this->send($text);
    }

    /** Korte run-melding na evolve:trade run (cron). */
    public function notifyTradingRun(string $network, float $balanceEth, float $sentimentScore): bool
    {
        $text = sprintf(
            "📊 <b>Trading run</b>\n\nNetwerk: %s | Balans: %.6f ETH | Sentiment: %.2f",
            $network,
            $balanceEth,
            $sentimentScore
        );

        return $this->send($text);
    }

    /**
     * Heartbeat cron: CRITICAL — trading wallet onder gas/reserve op Base.
     * Gebruikt TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID (.env).
     */
    public function notifyHeartbeatGasCritical(float $balanceEth, float $reserveEthRequired, string $address): bool
    {
        $addr = $address !== '' ? $address : '(unset)';
        $addrEsc = htmlspecialchars($addr, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = "🚨 <b>CRITICAL: gas / reserve (heartbeat)</b>\n\n"
            . "Trading wallet op <b>Base</b> heeft te weinig ETH voor gas.\n\n"
            . "Saldo: <code>" . number_format($balanceEth, 8) . " ETH</code>\n"
            . "Minimum nodig: <code>" . number_format($reserveEthRequired, 8) . " ETH</code>\n"
            . "Adres: <code>{$addrEsc}</code>\n\n"
            . "Stuur ETH op Base naar dit adres; tick wordt overgeslagen tot saldo OK.";

        return $this->send($text);
    }

    /**
     * Heartbeat cron: BUY of SELL is daadwerkelijk uitgevoerd (paper of live).
     *
     * @param array<string, mixed> $result Tick-resultaat van TradingService::tick()
     */
    public function notifyHeartbeatTrade(string $action, array $result): bool
    {
        $action = strtoupper($action);
        $mode = htmlspecialchars((string)($result['mode'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $reason = htmlspecialchars((string)($result['reason'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines   = [];
        $lines[] = "📈 <b>Heartbeat: trade</b>";
        $lines[] = 'Actie: <code>' . htmlspecialchars($action, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code>'
            . ($mode !== '' ? " ({$mode})" : '');

        if (isset($result['amount_eth'])) {
            $lines[] = 'ETH: <code>' . round((float) $result['amount_eth'], 6) . '</code>';
        }
        if (isset($result['value_eur'])) {
            $lines[] = 'Waarde: €' . round((float) $result['value_eur'], 2);
        }
        if (isset($result['price_eur'])) {
            $lines[] = 'Prijs: €' . round((float) $result['price_eur'], 2) . '/ETH';
        }
        if (!empty($result['tx_hash'])) {
            $lines[] = 'Tx: <code>' . htmlspecialchars((string) $result['tx_hash'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code>';
        }
        if (isset($result['signal']) && is_array($result['signal'])) {
            $s  = $result['signal'];
            $sg = htmlspecialchars((string) ($s['signal'] ?? '?'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $st = $s['strength'] ?? '?';
            $lines[] = "Signaal: {$sg} ({$st}%)";
        }
        $lines[] = "Reden: {$reason}";

        return $this->send(implode("\n", $lines));
    }

    /** Na bridge of storting: trading-wallet op Base heeft voldoende ETH om te scannen. */
    public function notifyBridgeLandedOnBase(float $balanceEth): bool
    {
        $b = number_format($balanceEth, 4);

        return $this->send('Bridge Succesvol - Saldo: ' . $b . ' ETH op Base');
    }

    /** Test the connection. */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID not configured'];
        }
        $sent = $this->send('🔌 Evolution AI Framework — Telegram verbinding getest. ✓');
        return ['ok' => $sent, 'chat_id' => $this->chatId];
    }

    private function post(string $method, array $params): bool
    {
        $url = self::API_BASE . $this->token . '/' . $method;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !is_string($resp)) {
            return false;
        }
        $decoded = json_decode($resp, true);
        return (bool)($decoded['ok'] ?? false);
    }

    private function configValue(string $key): string
    {
        if (!defined('BASE_PATH')) {
            return '';
        }
        $file = BASE_PATH . '/config/evolution.json';
        if (!is_file($file)) {
            return '';
        }
        static $cfg = null;
        if ($cfg === null) {
            $data = json_decode((string) file_get_contents($file), true);
            $cfg  = is_array($data) ? $data : [];
        }
        $parts   = explode('.', $key);
        $current = $cfg;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return '';
            }
            $current = $current[$part];
        }
        return (string)($current ?? '');
    }
}
