<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * TelegramNotifier — sends structured agent alerts via Telegram Bot API.
 *
 * Config (evolution.json → "telegram"):
 *   enabled      bool   – master switch
 *   bot_token    string – from env TELEGRAM_BOT_TOKEN
 *   chat_id      string – from env TELEGRAM_CHAT_ID (user or group)
 *   min_level    string – minimum level to send: debug|info|warning|alert (default: warning)
 *
 * Usage:
 *   TelegramNotifier::send($config, 'High-intent signal found: buyer for BMW M3 in NL', 'alert');
 *   TelegramNotifier::highIntent($config, $signal);   // growth machine shortcut
 *   TelegramNotifier::budgetWarning($config, 18.50, 20.0);
 */
final class TelegramNotifier
{
    private const API_URL  = 'https://api.telegram.org/bot%s/sendMessage';
    private const TIMEOUT  = 5;
    private const LEVELS   = ['debug' => 0, 'info' => 1, 'warning' => 2, 'alert' => 3];
    private const EMOJI    = ['debug' => '🔍', 'info' => 'ℹ️', 'warning' => '⚠️', 'alert' => '🚨'];

    // ─── Public API ──────────────────────────────────────────────────────────

    public static function send(Config $config, string $message, string $level = 'info'): bool
    {
        $cfg = self::cfg($config);
        if (!$cfg['enabled'] || $cfg['token'] === '' || $cfg['chat_id'] === '') {
            return false;
        }
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$cfg['min_level']] ?? 1)) {
            return false;
        }

        $emoji = self::EMOJI[$level] ?? 'ℹ️';
        $text  = "{$emoji} *Evolution Agent* [{$level}]\n\n" . self::escape($message)
               . "\n\n`" . gmdate('Y-m-d H:i') . " UTC`";

        return self::post($cfg['token'], $cfg['chat_id'], $text);
    }

    /**
     * Growth Machine: high-intent signal alert.
     * @param array{title?: string, score?: float, source?: string, url?: string} $signal
     */
    public static function highIntent(Config $config, array $signal): bool
    {
        $title  = (string)($signal['title'] ?? 'Unknown signal');
        $score  = number_format((float)($signal['score'] ?? 0.0), 2);
        $source = (string)($signal['source'] ?? '?');
        $url    = (string)($signal['url'] ?? '');

        $msg = "🎯 *High-Intent Signal Detected*\n\n"
             . "*Title:* " . self::escape($title) . "\n"
             . "*Score:* {$score}\n"
             . "*Source:* {$source}\n"
             . ($url !== '' ? "*URL:* " . self::escape($url) : '');

        return self::send($config, $msg, 'alert');
    }

    /**
     * Budget guardrail warning.
     */
    public static function budgetWarning(Config $config, float $spentEur, float $capEur): bool
    {
        $pct = $capEur > 0 ? round($spentEur / $capEur * 100, 1) : 0;
        $msg = "Monthly AI budget at {$pct}%\n"
             . "Spent: €" . number_format($spentEur, 4) . " / €" . number_format($capEur, 2) . "\n"
             . ($spentEur >= $capEur ? "🔴 ECO MODE ACTIVE — Ollama fallback engaged" : "");

        return self::send($config, $msg, $spentEur >= $capEur ? 'alert' : 'warning');
    }

    /**
     * Emergency kill switch notification.
     */
    public static function killSwitchActivated(Config $config): bool
    {
        return self::send(
            $config,
            "🛑 EMERGENCY KILL SWITCH ACTIVATED\nAll outbound AI calls are blocked.\nRemove `/storage/app/KILLED` to resume.",
            'alert'
        );
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * @return array{enabled: bool, token: string, chat_id: string, min_level: string}
     */
    private static function cfg(Config $config): array
    {
        $tg = $config->get('evolution.telegram', []);
        $enabled = is_array($tg) && filter_var($tg['enabled'] ?? false, FILTER_VALIDATE_BOOL);

        $token  = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: (is_array($tg) ? ($tg['bot_token'] ?? '') : '')));
        $chatId = trim((string)(getenv('TELEGRAM_CHAT_ID')   ?: (is_array($tg) ? ($tg['chat_id'] ?? '') : '')));
        $minLvl = is_array($tg) ? (string)($tg['min_level'] ?? 'warning') : 'warning';

        return ['enabled' => $enabled, 'token' => $token, 'chat_id' => $chatId, 'min_level' => $minLvl];
    }

    private static function post(string $token, string $chatId, string $text): bool
    {
        $url  = sprintf(self::API_URL, rawurlencode($token));
        $body = json_encode(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown']);
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

        if ($result !== false) {
            $j = json_decode($result, true);
            if (is_array($j) && ($j['ok'] ?? false)) {
                return true;
            }
        }

        EvolutionLogger::log('telegram', 'send_failed', ['chat_id' => $chatId]);
        return false;
    }

    private static function escape(string $text): string
    {
        return str_replace(['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'],
            ['\_', '\*', '\[', '\]', '\(', '\)', '\~', '\`', '\>', '\#', '\+', '\-', '\=', '\|', '\{', '\}', '\.', '\!'],
            $text);
    }
}
