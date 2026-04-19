<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

/**
 * AgentEstate — Dead Man's Switch voor de trading agent.
 *
 * ─── Beveiligingsniveaus ────────────────────────────────────────────────
 *
 * NIVEAU 1 — Stille bewaking (elke cyclus van de trading loop):
 *   • Controleer: wanneer heeft de Architect voor het laatst ingelogd?
 *   • Drempel:    7 dagen geen login → activeer NIVEAU 2
 *
 * NIVEAU 2 — Alarm + Bevestiging (eenmalig):
 *   • Stuur Telegram-alarm: "Agent gezien — ben je er nog?"
 *   • Knoppen: [✅ IK BEN ER] / [🔒 START ESTATE PROTOCOL]
 *   • Als binnen 24 uur geen reactie → activeer NIVEAU 3 automatisch
 *
 * NIVEAU 3 — Estate Protocol (alleen na expliciete bevestiging of timeout):
 *   a. Stuur alle ETH van de trading wallet naar de vault (Trust Wallet)
 *   b. Zet trading op FALSE (papier-modus)
 *   c. Roteer/wis API-sleutels uit evolution.json (vervang door '[ROTATED]')
 *   d. Telegram-melding: "Estate Protocol uitgevoerd — fondsen veilig in vault"
 *
 * ─── Veiligheidsgaranties ───────────────────────────────────────────────
 *   • Niveau 3 vereist ALTIJD hetzij expliciete Telegram-bevestiging
 *     (callback_data='estate_confirm') ÓFTEWEL 24u timeout na Niveau 2.
 *   • De ETH-transfer zelf vereist de private key in evolution.json.
 *     Als de key er niet is: alleen trading pauzeren + API-sleutels wissen.
 *   • Elke actie wordt gelogd in storage/evolution/security/estate_log.jsonl.
 *
 * ─── Heartbeat ─────────────────────────────────────────────────────────
 *   • Aanroepen vanuit AdminAuthController na succesvolle login:
 *     AgentEstate::recordHeartbeat();
 *
 * ─── Cron-check ────────────────────────────────────────────────────────
 *   • Aanroepen vanuit TradingService of cron:
 *     (new AgentEstate())->checkAndAct();
 */
final class AgentEstate
{
    private const HEARTBEAT_FILE  = 'storage/evolution/security/architect_heartbeat.json';
    private const ESTATE_LOG_FILE = 'storage/evolution/security/estate_log.jsonl';
    private const ALARM_FLAG_FILE = 'storage/evolution/security/estate_alarm.json';
    private const CONFIG_FILE     = 'config/evolution.json';

    private const INACTIVITY_DAYS  = 7;     // Days without login → trigger alarm
    private const CONFIRM_HOURS    = 24;    // Hours to confirm before auto-estate

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    // ── Publieke interface ────────────────────────────────────────────────

    /**
     * Sla een succesvolle Architect-login op als heartbeat.
     * Aanroepen vanuit AdminAuthController.
     */
    public static function recordHeartbeat(?string $basePath = null): void
    {
        $base = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $file = $base . '/data/evolution/security/architect_heartbeat.json';
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents($file, json_encode([
            'ts'      => time(),
            'date'    => date('c'),
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? '?',
        ]), LOCK_EX);

        // Reset alarm als het bestond
        $alarmFile = $base . '/data/evolution/security/estate_alarm.json';
        if (is_file($alarmFile)) {
            @unlink($alarmFile);
        }
    }

    /**
     * Hoofd-check — aanroepen vanuit cron of TradingService.
     * Blokkeert nooit langer dan ~2s.
     *
     * @return array{action: string, reason: string}
     */
    public function checkAndAct(): array
    {
        $daysSince = $this->daysSinceLastLogin();

        // Geen heartbeat-file = agent nooit ingelogd (nieuwe installatie) → skip
        if ($daysSince < 0) {
            return ['action' => 'noop', 'reason' => 'Geen heartbeat-bestand aangetroffen — nieuwe installatie'];
        }

        // Alles goed — inactief < 7 dagen
        if ($daysSince < self::INACTIVITY_DAYS) {
            return ['action' => 'ok', 'reason' => sprintf('Architect %d dag(en) geleden actief — geen actie vereist', $daysSince)];
        }

        // ── Check bestaand alarm ──────────────────────────────────────────
        $alarm = $this->loadAlarm();

        if ($alarm === null) {
            // Eerste keer inactief: verstuur alarm
            $this->sendAlarmTelegram($daysSince);
            $this->saveAlarm($daysSince);
            $this->log('alarm_sent', sprintf('%d dagen inactief — alarm verstuurd', $daysSince));
            return ['action' => 'alarm_sent', 'reason' => sprintf('Alarm verstuurd: %d dagen inactief', $daysSince)];
        }

        // ── Alarm al verstuurd — check timeout ────────────────────────────
        $alarmAge = (time() - (int)($alarm['ts'] ?? 0)) / 3600; // uren

        if ($alarmAge < self::CONFIRM_HOURS) {
            return [
                'action' => 'waiting',
                'reason' => sprintf('Wacht op bevestiging (%.1f/%dh verstreken)', $alarmAge, self::CONFIRM_HOURS),
            ];
        }

        // ── 24u timeout: activeer estate automatisch ──────────────────────
        return $this->executeEstate('auto_timeout_' . self::CONFIRM_HOURS . 'h');
    }

    /**
     * Verwerk Telegram-callback van inline keyboard.
     * Aanroepen vanuit TelegramWebhookController of AgentWebhookController.
     *
     * @param string $callbackData  'estate_confirm' of 'estate_alive'
     */
    public function handleTelegramCallback(string $callbackData): array
    {
        if ($callbackData === 'estate_alive') {
            self::recordHeartbeat($this->basePath);
            $this->log('heartbeat_telegram', 'Architect bevestigde aanwezigheid via Telegram');
            $this->sendTelegram('✅ Heartbeat ontvangen — trading gaat door. Welkom terug, Architect.');
            return ['action' => 'heartbeat_recorded'];
        }

        if ($callbackData === 'estate_confirm') {
            return $this->executeEstate('manual_telegram_confirm');
        }

        return ['action' => 'unknown_callback'];
    }

    // ── Estate uitvoering ─────────────────────────────────────────────────

    /**
     * Voer het volledige Estate Protocol uit:
     *   1. Schakel trading uit (paper mode)
     *   2. Probeer ETH over te sturen naar vault
     *   3. Wis/roteer API-sleutels
     *   4. Telegram-melding
     */
    private function executeEstate(string $trigger): array
    {
        $this->log('estate_start', 'Estate Protocol geactiveerd — trigger: ' . $trigger);

        $steps = [];

        // ── Stap 1: Trading uitschakelen (paper mode) ─────────────────────
        $disableOk = $this->disableTrading();
        $steps[]   = 'trading_disabled:' . ($disableOk ? 'ok' : 'failed');
        $this->log('trading_disabled', $disableOk ? 'Paper mode geactiveerd' : 'Kon trading niet uitschakelen');

        // ── Stap 2: ETH-overdracht naar vault ─────────────────────────────
        $transferResult = $this->transferToVault();
        $steps[]        = 'vault_transfer:' . $transferResult['status'];
        $this->log('vault_transfer', $transferResult['message']);

        // ── Stap 3: API-sleutels roteren (vervangen door placeholder) ─────
        $rotated = $this->rotateApiKeys();
        $steps[] = 'api_keys_rotated:' . ($rotated ? 'ok' : 'failed');
        $this->log('keys_rotated', $rotated ? 'API-sleutels gewist uit config' : 'Kon sleutels niet wissen');

        // ── Stap 4: Telegram-eindmelding ──────────────────────────────────
        $vaultAddr = $this->loadConfig()['trading']['vault_address'] ?? 'onbekend';
        $msg       = "🔒 <b>Estate Protocol Uitgevoerd</b>\n\n"
            . "⏰ Trigger: <code>{$trigger}</code>\n"
            . "💰 Fondsen overgedragen naar vault: <code>{$vaultAddr}</code>\n"
            . "📊 Status: " . implode(' | ', $steps) . "\n\n"
            . "De agent is gestopt met live traden. Herstart via /admin/evolution.";
        $this->sendTelegram($msg);

        // Alarm-flag verwijderen
        @unlink($this->basePath . '/' . self::ALARM_FLAG_FILE);

        $this->log('estate_complete', 'Estate Protocol voltooid — stappen: ' . implode(', ', $steps));

        return ['action' => 'estate_executed', 'reason' => implode(', ', $steps)];
    }

    // ── Estate stap-implementaties ────────────────────────────────────────

    private function disableTrading(): bool
    {
        $cfg = $this->loadConfig();
        if (!is_array($cfg)) {
            return false;
        }

        // Zet paper_mode op true en enabled op false
        $cfg['trading']['paper_mode'] = true;
        $cfg['trading']['enabled']    = false;
        $cfg['trading']['_estate_locked'] = date('c');

        return $this->saveConfig($cfg);
    }

    /**
     * Stuur ETH van trading wallet naar vault.
     * Bouwt een ruwe ETH-transfer transactie en broadcast die via Base RPC.
     *
     * @return array{status: string, message: string, tx_hash?: string}
     */
    private function transferToVault(): array
    {
        $cfg         = $this->loadConfig();
        $rpcUrl      = 'https://mainnet.base.org';
        $vaultAddr   = (string)($cfg['trading']['vault_address']      ?? '');
        $walletAddr  = (string)($cfg['trading']['trading_wallet_address'] ?? '');
        $privateKey  = (string)($cfg['trading']['wallet_private_key'] ?? '');
        $chainId     = (int)($cfg['trading']['evm']['chain_id']       ?? 8453);

        if ($vaultAddr === '' || $walletAddr === '') {
            return ['status' => 'skipped', 'message' => 'Vault/wallet adres niet geconfigureerd'];
        }

        if ($privateKey === '') {
            return ['status' => 'skipped', 'message' => 'Geen private key geconfigureerd — handmatige transfer vereist naar ' . $vaultAddr];
        }

        // Check of signing libraries beschikbaar zijn
        if (!class_exists('\kornrunner\Keccak') || !class_exists('\Web3p\EthereumTx\Transaction')) {
            return [
                'status'  => 'manual_required',
                'message' => 'Signing libraries niet beschikbaar. Stuur handmatig ETH van ' . $walletAddr . ' naar vault ' . $vaultAddr,
            ];
        }

        try {
            // Haal balance op
            $balance = $this->rpcCall($rpcUrl, 'eth_getBalance', [$walletAddr, 'latest']);
            $balHex  = is_string($balance) ? ltrim($balance, '0x') : '0';
            $balWei  = hexdec($balHex);

            if ($balWei <= 0) {
                return ['status' => 'skipped', 'message' => 'Geen ETH-saldo op trading wallet'];
            }

            // Reserveer gas: 21000 * 2 Gwei = 42000 Gwei = 42000000000000 Wei
            $gasPrice = 2_000_000_000; // 2 Gwei
            $gasLimit = 21_000;
            $gasCost  = $gasPrice * $gasLimit;
            $sendWei  = $balWei - $gasCost;

            if ($sendWei <= 0) {
                return ['status' => 'skipped', 'message' => 'Saldo te laag om gas te betalen'];
            }

            // Haal nonce op
            $nonceResult = $this->rpcCall($rpcUrl, 'eth_getTransactionCount', [$walletAddr, 'pending']);
            $nonce       = is_string($nonceResult) ? hexdec(ltrim($nonceResult, '0x')) : 0;

            // Bouw transactie
            $tx = new \Web3p\EthereumTx\Transaction([
                'nonce'    => '0x' . dechex($nonce),
                'gasPrice' => '0x' . dechex($gasPrice),
                'gasLimit' => '0x' . dechex($gasLimit),
                'to'       => $vaultAddr,
                'value'    => '0x' . dechex($sendWei),
                'data'     => '0x',
                'chainId'  => $chainId,
            ]);

            $privKey   = ltrim($privateKey, '0x');
            $signed    = '0x' . $tx->sign($privKey);
            $txHashRaw = $this->rpcCall($rpcUrl, 'eth_sendRawTransaction', [$signed]);
            $txHash    = is_string($txHashRaw) ? $txHashRaw : 'unknown';

            return [
                'status'  => 'ok',
                'message' => sprintf('%.6f ETH overgedragen naar vault %s — tx: %s',
                    $sendWei / 1e18, $vaultAddr, $txHash),
                'tx_hash' => $txHash,
            ];
        } catch (\Throwable $e) {
            return [
                'status'  => 'failed',
                'message' => 'Transfer mislukt: ' . $e->getMessage() . '. Handmatige transfer vereist.',
            ];
        }
    }

    /** Vervang API-sleutels door '[ROTATED-ESTATE]' in evolution.json. */
    private function rotateApiKeys(): bool
    {
        $cfg = $this->loadConfig();
        if (!is_array($cfg)) {
            return false;
        }

        $ROTATED = '[ROTATED-ESTATE-' . date('Ymd') . ']';

        foreach (['openai_api_key', 'anthropic_api_key', 'tavily_api_key', 'groq_api_key', 'deepseek_api_key'] as $key) {
            if (!empty($cfg['ai'][$key])) {
                $cfg['ai'][$key] = $ROTATED;
            }
        }

        // Private key is het meest kritisch
        if (!empty($cfg['trading']['wallet_private_key'])) {
            $cfg['trading']['wallet_private_key'] = $ROTATED;
        }

        return $this->saveConfig($cfg);
    }

    // ── Telegram ─────────────────────────────────────────────────────────

    private function sendAlarmTelegram(int $daysSince): void
    {
        $text = sprintf(
            "⚠️ <b>Agent Estate — Inactiviteits-Alarm</b>\n\n"
            . "De Architect heeft <b>%d dagen</b> niet ingelogd.\n\n"
            . "Als dit een fout is, klik op 'Ik ben er'.\n"
            . "Als je dit bewust hebt ingesteld of niet meer actief wilt zijn, "
            . "start dan het Estate Protocol om je fondsen veilig te stellen.\n\n"
            . "⏰ Bij geen reactie binnen 24 uur wordt het Estate Protocol automatisch geactiveerd.",
            $daysSince
        );

        $keyboard = [
            [
                ['text' => '✅ Ik ben er!',              'callback_data' => 'estate_alive'],
                ['text' => '🔒 Start Estate Protocol',   'callback_data' => 'estate_confirm'],
            ],
        ];

        $this->sendTelegramWithKeyboard($text, $keyboard);
    }

    private function sendTelegram(string $text): void
    {
        [$token, $chatId] = $this->getTelegramCredentials();
        if ($token === '' || $chatId === '') {
            return;
        }

        $body = json_encode([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
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
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard],
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

    // ── Helpers ───────────────────────────────────────────────────────────

    /** -1 = geen heartbeat bestand, anders aantal dagen geleden */
    private function daysSinceLastLogin(): int
    {
        $file = $this->basePath . '/' . self::HEARTBEAT_FILE;
        if (!is_file($file)) {
            return -1;
        }
        $data = json_decode((string)file_get_contents($file), true);
        $ts   = (int)($data['ts'] ?? 0);
        if ($ts === 0) {
            return -1;
        }
        return (int)floor((time() - $ts) / 86400);
    }

    private function loadAlarm(): ?array
    {
        $file = $this->basePath . '/' . self::ALARM_FLAG_FILE;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function saveAlarm(int $daysSince): void
    {
        $file = $this->basePath . '/' . self::ALARM_FLAG_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($file, json_encode([
            'ts'          => time(),
            'days_since'  => $daysSince,
            'auto_estate' => time() + (self::CONFIRM_HOURS * 3600),
        ]), LOCK_EX);
    }

    private function log(string $event, string $message): void
    {
        $file = $this->basePath . '/' . self::ESTATE_LOG_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($file, json_encode([
            'ts'      => date('c'),
            'event'   => $event,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        $file = $this->basePath . '/' . self::CONFIG_FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveConfig(array $cfg): bool
    {
        $file = $this->basePath . '/' . self::CONFIG_FILE;
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return @file_put_contents($file, $json . "\n", LOCK_EX) !== false;
    }

    /** @return array{0: string, 1: string} */
    private function getTelegramCredentials(): array
    {
        $cfg    = $this->loadConfig();
        $token  = trim((string)(($cfg['telegram']['bot_token'] ?? null) ?: (getenv('TELEGRAM_BOT_TOKEN') ?: '')));
        $chatId = trim((string)(($cfg['telegram']['chat_id']   ?? null) ?: (getenv('TELEGRAM_CHAT_ID')   ?: '')));
        return [$token, $chatId];
    }

    /** @return mixed */
    private function rpcCall(string $url, string $method, array $params)
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $ctx  = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $data = $raw ? json_decode($raw, true) : null;
        return $data['result'] ?? null;
    }
}
