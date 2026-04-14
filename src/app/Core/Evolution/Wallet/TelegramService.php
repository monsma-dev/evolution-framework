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
              . "Netwerk: Base Mainnet\n\n"
              . "Wachtend op brandstof...";

        return $this->send($text);
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
        $file = BASE_PATH . '/src/config/evolution.json';
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
