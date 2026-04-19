<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Evolution Notifier — Alert DNA
 *
 * Sends structured notifications (Telegram / email) for infrastructure events.
 *
 * Every alert has a severity level and can carry an optional "Business Case"
 * with cost/ROI figures and a one-click approval command.
 *
 * ─── Configuration (evolution.json) ─────────────────────────────────────────
 *
 *  "notifications": {
 *    "telegram": { "enabled": true, "bot_token": "...", "chat_id": "-100..." },
 *    "email":    { "enabled": false, "to": "you@example.com", "from": "ai@framework.local" }
 *  }
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *  $n = new EvolutionNotifier($config);
 *
 *  // Simple alert
 *  $n->warn('Scheduler down', 'PID file missing — restarting now.');
 *
 *  // Business case with one-click approval
 *  $approval = $n->businessCase(
 *      what:    'Upgrade to t4g.medium (Graviton)',
 *      why:     'CPU > 85% sustained for 4h during Llama tasks',
 *      cost:    '+$4.50/month',
 *      roi:     'Saves $12/month in Claude credits via faster local inference',
 *      command: 'evolve:provision scale --tier=medium'
 *  );
 *  // Returns the approval ID so you can pass it to EvolutionApprovalGateway
 */
final class EvolutionNotifier
{
    private const NOTIFICATION_LOG = '/var/www/html/data/evolution/notification_log.jsonl';
    private const TELEGRAM_API     = 'https://api.telegram.org/bot';

    public function __construct(private readonly Config $config) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /** Infrastructure critical alert — server issue, immediate attention */
    public function critical(string $title, string $details): void
    {
        $this->send('🔴 CRITICAL', $title, $details, null);
    }

    /** Warning — issue detected, suggested action available */
    public function warn(string $title, string $details, ?string $approvalId = null): void
    {
        $this->send('⚠️  WARNING', $title, $details, $approvalId);
    }

    /** Informational — action completed, health restored */
    public function info(string $title, string $details): void
    {
        $this->send('✅ HEALED', $title, $details, null);
    }

    /**
     * Business case notification — Economist proposes infrastructure change.
     *
     * Returns the approval ID. Store it in EvolutionApprovalGateway before calling this.
     */
    public function businessCase(
        string $what,
        string $why,
        string $cost,
        string $roi,
        string $approvalId,
        string $command
    ): void {
        $body = $this->formatBusinessCase($what, $why, $cost, $roi, $approvalId, $command);

        $this->send('💡 ECONOMIST PROPOSAL', $what, $body, $approvalId);
    }

    // ── Core send ─────────────────────────────────────────────────────────────

    private function send(string $level, string $title, string $body, ?string $approvalId): void
    {
        $message = $this->formatMessage($level, $title, $body, $approvalId);

        $this->log($level, $title, $body, $approvalId);
        $this->sendTelegram($message);
        $this->sendEmail($title, $message);
    }

    // ── Formatters ────────────────────────────────────────────────────────────

    private function formatMessage(string $level, string $title, string $body, ?string $approvalId): string
    {
        $host = gethostname() ?: 'evolution-server';
        $ts   = date('Y-m-d H:i:s T');

        $msg  = "{$level}: {$title}\n";
        $msg .= "Server: {$host} · {$ts}\n";
        $msg .= str_repeat('─', 40) . "\n";
        $msg .= $body . "\n";

        if ($approvalId !== null) {
            $msg .= str_repeat('─', 40) . "\n";
            $msg .= "One-click approval:\n";
            $msg .= "  php ai_bridge.php evolve:provision approve --id={$approvalId}\n";
        }

        return $msg;
    }

    private function formatBusinessCase(
        string $what,
        string $why,
        string $cost,
        string $roi,
        string $approvalId,
        string $command
    ): string {
        return implode("\n", [
            "What   : {$what}",
            "Why    : {$why}",
            "Cost   : {$cost}",
            "ROI    : {$roi}",
            "",
            "Guard  : Rust ceiling check will run before execution",
            "Command: php ai_bridge.php evolve:provision approve --id={$approvalId}",
            "Expires: " . date('Y-m-d H:i', strtotime('+24 hours')),
        ]);
    }

    // ── Channels ──────────────────────────────────────────────────────────────

    private function sendTelegram(string $text): void
    {
        $cfg = $this->config->get('evolution.notifications.telegram', []);
        if (!is_array($cfg) || !($cfg['enabled'] ?? false)) {
            return;
        }

        $token  = trim((string) ($cfg['bot_token'] ?? ''));
        $chatId = trim((string) ($cfg['chat_id'] ?? ''));
        if ($token === '' || $chatId === '') {
            return;
        }

        $url     = self::TELEGRAM_API . $token . '/sendMessage';
        $payload = json_encode(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function sendEmail(string $subject, string $body): void
    {
        $cfg = $this->config->get('evolution.notifications.email', []);
        if (!is_array($cfg) || !($cfg['enabled'] ?? false)) {
            return;
        }

        $to   = trim((string) ($cfg['to'] ?? ''));
        $from = trim((string) ($cfg['from'] ?? 'ai@framework.local'));
        if ($to === '') {
            return;
        }

        $headers = "From: Evolution Framework <{$from}>\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "X-Mailer: EvolutionFramework/1.0\r\n";

        @mail($to, '[Evolution] ' . $subject, $body, $headers);
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    private function log(string $level, string $title, string $body, ?string $approvalId): void
    {
        $dir = dirname(self::NOTIFICATION_LOG);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $entry = [
            'level'       => $level,
            'title'       => $title,
            'body'        => mb_substr($body, 0, 500),
            'approval_id' => $approvalId,
            'sent_at'     => date('c'),
        ];

        file_put_contents(self::NOTIFICATION_LOG, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return list<array<string, mixed>> */
    public function recentNotifications(int $n = 20): array
    {
        if (!is_file(self::NOTIFICATION_LOG)) {
            return [];
        }

        $lines = array_filter(explode("\n", (string) file_get_contents(self::NOTIFICATION_LOG)));
        $lines = array_slice(array_reverse(array_values($lines)), 0, $n);

        return array_values(array_filter(array_map(
            static fn ($l) => json_decode($l, true),
            $lines
        )));
    }
}
