<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Emergency pause: .lock file stops auto-apply + Ghost; admin can resume. Stores context for "Redial" post-mortem.
 */
final class EvolutionKillSwitchService
{
    public const LOCK_FILE = 'storage/evolution/EVOLUTION_PAUSE.lock';

    /** Hardware-style stop: aanwezigheid = alles stopt (naast JSON LOCK). */
    public const STOP_FILE = 'storage/evolution/STOP_EVOLUTION';

    public static function isPaused(?Config $config = null): bool
    {
        $cfg = $config;
        if ($cfg !== null) {
            $ks = $cfg->get('evolution.kill_switch', []);
            if (is_array($ks) && !filter_var($ks['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
                return false;
            }
        }

        return is_file(BASE_PATH . '/' . self::LOCK_FILE)
            || is_file(BASE_PATH . '/' . self::STOP_FILE);
    }

    /**
     * @param array<string, mixed> $context last architect activity, patches, etc.
     */
    public static function pause(string $reason, array $context = []): void
    {
        $dir = dirname(BASE_PATH . '/' . self::LOCK_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $postMortem = self::buildPostMortem($context);
        $payload = [
            'paused_at' => gmdate('c'),
            'reason' => mb_substr($reason, 0, 2000),
            'context' => $context,
            'post_mortem' => $postMortem,
            'pending_redial' => true,
        ];
        @file_put_contents(
            BASE_PATH . '/' . self::LOCK_FILE,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
        $stopLine = gmdate('c') . " STOP_EVOLUTION\nreason: " . mb_substr($reason, 0, 500) . "\n";
        @file_put_contents(BASE_PATH . '/' . self::STOP_FILE, $stopLine);
        EvolutionLogger::log('kill_switch', 'paused', ['reason' => $reason]);
        EvolutionNodePubSub::tryPublish('kill_switch', ['action' => 'pause', 'reason' => mb_substr($reason, 0, 500)]);
    }

    public static function resume(): void
    {
        @unlink(BASE_PATH . '/' . self::LOCK_FILE);
        @unlink(BASE_PATH . '/' . self::STOP_FILE);
        EvolutionLogger::log('kill_switch', 'resumed', []);
        EvolutionNodePubSub::tryPublish('kill_switch', ['action' => 'resume']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readLock(): ?array
    {
        $p = BASE_PATH . '/' . self::LOCK_FILE;
        if (is_file($p)) {
            $raw = @file_get_contents($p);
            $j = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($j) ? $j : null;
        }

        $s = BASE_PATH . '/' . self::STOP_FILE;
        if (is_file($s)) {
            $raw = @file_get_contents($s);

            return [
                'paused_at' => gmdate('c'),
                'reason' => 'STOP_EVOLUTION (alleen lockfile, geen JSON payload)',
                'context' => ['stop_file_excerpt' => mb_substr((string) $raw, 0, 2000)],
                'post_mortem' => self::buildPostMortem(['source' => 'STOP_EVOLUTION_only']),
                'pending_redial' => true,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function buildPostMortem(array $context): string
    {
        $lines = [
            'De-escalation / post-mortem',
            'Evolution is gestopt (kill-switch of STOP_EVOLUTION).',
            'Waar de AI mee bezig was (context): ' . json_encode($context, JSON_UNESCAPED_UNICODE),
            '',
            'Laatste drie relevante logregels (architect / auto_apply / patch / kill_switch indien aanwezig):',
        ];

        $logPath = EvolutionLogger::logPath();
        $tail = is_file($logPath) ? (@file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        if ($tail !== []) {
            $picked = [];
            foreach (array_reverse($tail) as $ln) {
                $s = (string) $ln;
                if (str_contains($s, '"channel":"architect"')
                    || str_contains($s, '"channel":"auto_apply"')
                    || str_contains($s, '"channel":"patch"')
                    || str_contains($s, '"channel":"kill_switch"')) {
                    $picked[] = $s;
                    if (count($picked) >= 3) {
                        break;
                    }
                }
            }
            if ($picked === []) {
                foreach (array_slice($tail, -3) as $ln) {
                    $picked[] = (string) $ln;
                }
            }
            foreach ($picked as $ln) {
                $lines[] = '  ' . mb_substr($ln, 0, 400);
            }
            $lines[] = '';
            $lines[] = 'evolution.log fragment (laatste 12 regels):';
            foreach (array_slice($tail, -12) as $ln) {
                $lines[] = '  ' . mb_substr((string) $ln, 0, 300);
            }
        }

        $vt = BASE_PATH . '/data/evolution/visual_timeline.jsonl';
        if (is_file($vt)) {
            $vlines = @file($vt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($vlines) && $vlines !== []) {
                $lines[] = '';
                $lines[] = 'Laatste visual memory entries:';
                foreach (array_slice($vlines, -3) as $vl) {
                    $lines[] = '  ' . mb_substr((string) $vl, 0, 280);
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Volgende stap: (A) Resume na review, (B) rollback via reset-human / shadow-list, of (C) Policy Guard / evolution.json aanpassen.';

        return implode("\n", $lines);
    }
}
