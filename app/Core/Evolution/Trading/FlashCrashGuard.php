<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * FlashCrashGuard — Beschermt tegen plotselinge "wick"-crashes bij 15m scalping.
 *
 * Werking (PHP side):
 *   - Slaat elke tick de koers op in een rolling 5-minuten buffer.
 *   - Als de prijs > CRASH_THRESHOLD_PCT (5%) daalt binnen WINDOW_SECONDS (60s),
 *     schrijft het een FLASH_CRASH.lock bestand → TradingService pauzeert.
 *   - Auto-clear na RECOVERY_MINUTES (5 min) mits prijs stabiel.
 *
 * Rust side (zie src/rust/flash_crash_monitor/):
 *   - Zelfde lock-bestand; Rust polling elke 10s kan sneller reageren dan PHP-ticks.
 *   - Rust binary schrijft hetzelfde FLASH_CRASH.lock → TradingService checkt dit.
 *
 * Lock-bestand: storage/evolution/trading/FLASH_CRASH.lock
 */
final class FlashCrashGuard
{
    private const LOCK_FILE           = 'storage/evolution/trading/FLASH_CRASH.lock';
    private const PRICE_HISTORY_FILE  = 'storage/evolution/trading/flash_crash_prices.json';
    private const CRASH_THRESHOLD_PCT = 5.0;   // Crash bij >= 5% daling
    private const WINDOW_SECONDS      = 60;    // Binnen 60 seconden
    private const RECOVERY_MINUTES    = 5;     // Auto-clear na 5 minuten
    private const HISTORY_TTL_SECONDS = 300;   // Bewaar 5 minuten koershistorie

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Controleer op flash crash en registreer de huidige prijs.
     * Aanroepen aan het begin van elke trading tick.
     *
     * @return array{crashed: bool, reason: string, drop_pct: float}
     */
    public function checkAndRecord(float $currentPrice): array
    {
        $this->recordPrice($currentPrice);

        $lockFile = $this->basePath . '/' . self::LOCK_FILE;

        // ── Actieve lock: check auto-clear ────────────────────────────────
        if (is_file($lockFile)) {
            $lockAge = time() - (int)filemtime($lockFile);
            if ($lockAge >= self::RECOVERY_MINUTES * 60) {
                @unlink($lockFile);
                return [
                    'crashed'  => false,
                    'reason'   => 'Flash crash lock vervallen na ' . self::RECOVERY_MINUTES . ' min — trading hervat',
                    'drop_pct' => 0.0,
                ];
            }
            $remaining = self::RECOVERY_MINUTES * 60 - $lockAge;
            return [
                'crashed'  => true,
                'reason'   => sprintf('⚡ FLASH CRASH LOCK actief — auto-clear over %ds', $remaining),
                'drop_pct' => 0.0,
            ];
        }

        // ── Detectie: max daling in window ────────────────────────────────
        $history = $this->loadPriceHistory();
        $dropPct = $this->maxDropWithinWindow($history, $currentPrice);

        if ($dropPct >= self::CRASH_THRESHOLD_PCT) {
            $reason = sprintf(
                '⚡ FLASH CRASH: prijs daalde %.2f%% in %ds — trading gepauzeerd voor %d min',
                $dropPct,
                self::WINDOW_SECONDS,
                self::RECOVERY_MINUTES
            );
            $dir = dirname($lockFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            file_put_contents($lockFile, json_encode([
                'drop_pct'   => round($dropPct, 3),
                'price'      => $currentPrice,
                'detected'   => date('c'),
                'auto_clear' => date('c', time() + self::RECOVERY_MINUTES * 60),
                'source'     => 'php',
            ]));
            $this->sendRiskManagerAlert($dropPct, $currentPrice);
            return ['crashed' => true, 'reason' => $reason, 'drop_pct' => $dropPct];
        }

        return ['crashed' => false, 'reason' => '', 'drop_pct' => round($dropPct, 3)];
    }

    /** Geeft true als FLASH_CRASH.lock actief is (geschreven door PHP of Rust). */
    public function isActive(): bool
    {
        return is_file($this->basePath . '/' . self::LOCK_FILE);
    }

    /** Handmatig verwijderen van de lock (voor CLI reset). */
    public function clearLock(): void
    {
        $lock = $this->basePath . '/' . self::LOCK_FILE;
        if (is_file($lock)) {
            unlink($lock);
        }
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    private function sendRiskManagerAlert(float $dropPct, float $currentPrice): void
    {
        $token  = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
        $chatId = trim((string)(getenv('TELEGRAM_CHAT_ID') ?: ''));
        if ($token === '' || $chatId === '') {
            return;
        }

        $persona  = AgentPersonality::riskManager();
        $drop     = number_format($dropPct, 2);
        $price    = number_format($currentPrice, 2);
        $recovery = self::RECOVERY_MINUTES;

        $msg = $persona->rawFormat(
            "⚡ <b>FLASH CRASH GEDETECTEERD</b>\n\n"
            . "Koersdaling: <code>{$drop}%</code> binnen " . self::WINDOW_SECONDS . " seconden\n"
            . "Huidig ETH/EUR: <code>€{$price}</code>\n\n"
            . "🛑 Alle trades zijn gepauzeerd voor <b>{$recovery} minuten</b>.\n"
            . "De Rust flash-crash monitor is ook actief.\n"
            . "Gebruik /wake om handmatig te hervatten na de lock-periode.",
            'Flash Crash Alert'
        );

        $body = json_encode([
            'chat_id'                  => $chatId,
            'text'                     => $msg,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if ($body === false) {
            return;
        }

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', rawurlencode($token));
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($body),
            'content'       => $body,
            'timeout'       => 5,
            'ignore_errors' => true,
        ]]);

        @file_get_contents($url, false, $ctx);
    }

    private function maxDropWithinWindow(array $history, float $currentPrice): float
    {
        $now     = time();
        $maxDrop = 0.0;
        foreach ($history as $entry) {
            if (($now - (int)($entry['ts'] ?? 0)) > self::WINDOW_SECONDS) {
                continue;
            }
            $pastPrice = (float)($entry['price'] ?? 0);
            if ($pastPrice > 0) {
                $drop    = ($pastPrice - $currentPrice) / $pastPrice * 100;
                $maxDrop = max($maxDrop, $drop);
            }
        }
        return $maxDrop;
    }

    private function recordPrice(float $price): void
    {
        if ($price <= 0) {
            return;
        }

        $history   = $this->loadPriceHistory();
        $now       = time();
        $history[] = ['ts' => $now, 'price' => round($price, 2)];

        // Bewaar alleen laatste 5 minuten
        $history = array_values(array_filter(
            $history,
            fn(array $h) => ($now - (int)($h['ts'] ?? 0)) <= self::HISTORY_TTL_SECONDS
        ));

        $file = $this->priceHistoryPath();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode($history), LOCK_EX);
    }

    private function loadPriceHistory(): array
    {
        $file = $this->priceHistoryPath();
        if (!is_file($file)) {
            return [];
        }
        return json_decode((string)file_get_contents($file), true) ?? [];
    }

    private function priceHistoryPath(): string
    {
        return $this->basePath . '/' . self::PRICE_HISTORY_FILE;
    }
}
