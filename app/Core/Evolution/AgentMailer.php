<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * AgentMailer — SMTP tool for Evolution agents to email leads directly.
 *
 * Config (evolution.json → "agent_mailer"):
 *   enabled      bool
 *   from_email   string – e.g. "growth@yourdomain.com"
 *   from_name    string – e.g. "Growth Agent"
 *   smtp_host    string – from env SMTP_HOST (default: sendmail fallback)
 *   smtp_port    int    – 587 (STARTTLS) or 465 (SSL)
 *   smtp_user    string – from env SMTP_USER
 *   smtp_pass    string – from env SMTP_PASS
 *   max_per_day  int    – hard rate limit (default: 20 emails/day)
 *
 * Compliance:
 *   - Every outbound email automatically appends an unsubscribe note.
 *   - Hard rate limit prevents spam/abuse.
 *   - Records every send in the AiCreditMonitor tool ledger as 'smtp'.
 */
final class AgentMailer
{
    private const MAX_PER_DAY_DEFAULT = 20;
    private const SEND_LOG_PATH = 'storage/evolution/agent_mail_log.json';

    public function __construct(
        private readonly Config $config,
        private readonly ?AiCreditMonitor $monitor = null
    ) {}

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Send an email to a lead.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function sendToLead(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $context = 'growth_machine'
    ): array {
        $cfg = $this->cfg();

        if (!$cfg['enabled']) {
            return ['ok' => false, 'reason' => 'AgentMailer disabled in config'];
        }

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'reason' => 'Invalid email address'];
        }

        if ($this->dailySendCount() >= $cfg['max_per_day']) {
            return ['ok' => false, 'reason' => "Daily email limit ({$cfg['max_per_day']}) reached"];
        }

        $fullBody = $htmlBody . $this->unsubscribeFooter($toEmail);

        $ok = $this->dispatch($cfg, $toEmail, $toName, $subject, $fullBody);

        $this->logSend($toEmail, $subject, $context, $ok);

        if ($ok && $this->monitor !== null) {
            // SMTP costs ~$0.0001 per email (e.g. SES pricing)
            $this->monitor->recordToolSpend('smtp', 0.0001);
        }

        return ['ok' => $ok, 'reason' => $ok ? null : 'SMTP dispatch failed'];
    }

    /**
     * How many emails have been sent today (UTC).
     */
    public function dailySendCount(): int
    {
        $log = $this->readLog();
        $today = gmdate('Y-m-d');
        return (int)($log['days'][$today] ?? 0);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * @return array{enabled: bool, from_email: string, from_name: string, smtp_host: string, smtp_port: int, smtp_user: string, smtp_pass: string, max_per_day: int}
     */
    private function cfg(): array
    {
        $am = $this->config->get('evolution.agent_mailer', []);
        $am = is_array($am) ? $am : [];

        return [
            'enabled'    => filter_var($am['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'from_email' => trim((string)(getenv('AGENT_MAILER_FROM') ?: ($am['from_email'] ?? ''))),
            'from_name'  => trim((string)($am['from_name'] ?? 'Growth Agent')),
            'smtp_host'  => trim((string)(getenv('SMTP_HOST') ?: ($am['smtp_host'] ?? ''))),
            'smtp_port'  => (int)($am['smtp_port'] ?? 587),
            'smtp_user'  => trim((string)(getenv('SMTP_USER') ?: ($am['smtp_user'] ?? ''))),
            'smtp_pass'  => trim((string)(getenv('SMTP_PASS') ?: ($am['smtp_pass'] ?? ''))),
            'max_per_day' => (int)($am['max_per_day'] ?? self::MAX_PER_DAY_DEFAULT),
        ];
    }

    /**
     * @param array{from_email: string, from_name: string, smtp_host: string, smtp_port: int, smtp_user: string, smtp_pass: string} $cfg
     */
    private function dispatch(array $cfg, string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        // Use PHP's built-in mail() as simple fallback (works with sendmail/postfix on EC2)
        if ($cfg['smtp_host'] === '') {
            $headers  = "From: {$cfg['from_name']} <{$cfg['from_email']}>\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $to = $toName !== '' ? "{$toName} <{$toEmail}>" : $toEmail;
            return @mail($to, $subject, $htmlBody, $headers);
        }

        // SMTP via stream (no external library needed)
        return $this->smtpSend($cfg, $toEmail, $toName, $subject, $htmlBody);
    }

    /**
     * Minimal SMTP client (STARTTLS on port 587, plain LOGIN auth).
     * @param array{from_email: string, from_name: string, smtp_host: string, smtp_port: int, smtp_user: string, smtp_pass: string} $cfg
     */
    private function smtpSend(array $cfg, string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $errno = 0; $errstr = '';
        $sock = @fsockopen($cfg['smtp_host'], $cfg['smtp_port'], $errno, $errstr, 10);
        if (!is_resource($sock)) {
            EvolutionLogger::log('agent_mailer', 'smtp_connect_fail', ['host' => $cfg['smtp_host'], 'err' => $errstr]);
            return false;
        }
        stream_set_timeout($sock, 10);

        $read = static function () use ($sock): string {
            return (string)fgets($sock, 512);
        };
        $write = static function (string $cmd) use ($sock): void {
            fwrite($sock, $cmd . "\r\n");
        };

        $read(); // banner
        $write("EHLO localhost");
        while (($line = $read()) !== '' && !str_starts_with($line, '250 ')) {}

        // STARTTLS
        if ($cfg['smtp_port'] === 587) {
            $write("STARTTLS");
            $read();
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write("EHLO localhost");
            while (($line = $read()) !== '' && !str_starts_with($line, '250 ')) {}
        }

        // AUTH LOGIN
        if ($cfg['smtp_user'] !== '') {
            $write("AUTH LOGIN");
            $read();
            $write(base64_encode($cfg['smtp_user']));
            $read();
            $write(base64_encode($cfg['smtp_pass']));
            $resp = $read();
            if (!str_starts_with($resp, '235')) {
                fclose($sock);
                return false;
            }
        }

        $from = $cfg['from_email'];
        $write("MAIL FROM:<{$from}>");
        $read();
        $write("RCPT TO:<{$toEmail}>");
        $read();
        $write("DATA");
        $read();

        $boundary = md5(uniqid('', true));
        $headers  = "From: {$cfg['from_name']} <{$from}>\r\n";
        $headers .= "To: {$toName} <{$toEmail}>\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: EvolutionAgentMailer/1.0\r\n";

        $write($headers . "\r\n" . $htmlBody . "\r\n.");
        $resp = $read();
        $write("QUIT");
        fclose($sock);

        return str_starts_with($resp, '250');
    }

    private function unsubscribeFooter(string $email): string
    {
        return "\n\n<hr style='border:none;border-top:1px solid #eee;margin:20px 0'>"
             . "<p style='font-size:11px;color:#999'>This message was sent by an automated growth agent. "
             . "You received this because your contact information is publicly available. "
             . "To opt out, reply with STOP or email <a href='mailto:optout@noreply'>optout</a>.</p>";
    }

    private function logSend(string $toEmail, string $subject, string $context, bool $ok): void
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $path = $base . '/' . self::SEND_LOG_PATH;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $log = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $log = is_string($raw) ? (json_decode($raw, true) ?? []) : [];
        }
        $today = gmdate('Y-m-d');
        if (!isset($log['days']) || !is_array($log['days'])) {
            $log['days'] = [];
        }
        $log['days'][$today] = ((int)($log['days'][$today] ?? 0)) + ($ok ? 1 : 0);

        if (!isset($log['sends']) || !is_array($log['sends'])) {
            $log['sends'] = [];
        }
        $log['sends'][] = [
            't'       => gmdate('c'),
            'to'      => $toEmail,
            'subject' => mb_substr($subject, 0, 80),
            'context' => $context,
            'ok'      => $ok,
        ];
        // Keep last 200 sends
        if (count($log['sends']) > 200) {
            $log['sends'] = array_slice($log['sends'], -200);
        }

        @file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @return array<string, mixed>
     */
    private function readLog(): array
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $path = $base . '/' . self::SEND_LOG_PATH;
        if (!is_file($path)) {
            return ['days' => [], 'sends' => []];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($j) ? $j : ['days' => [], 'sends' => []];
    }
}
