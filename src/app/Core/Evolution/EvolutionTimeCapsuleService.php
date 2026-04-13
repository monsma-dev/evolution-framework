<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use DateInterval;
use DateTimeImmutable;

/**
 * Evolution Time-Capsule — elke N maanden een verzegelde snapshot voor toekomstige agents (bescheidenheid / erfgoed).
 */
final class EvolutionTimeCapsuleService
{
    private const SEAL_DIR = 'storage/evolution/time_capsule';
    private const LAST_SEAL = 'storage/evolution/time_capsule/last_seal.json';

    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $t = is_array($evo) ? ($evo['time_capsule'] ?? []) : null;

        return is_array($t) && filter_var($t['enabled'] ?? true, FILTER_VALIDATE_BOOL) ? $t : null;
    }

    public static function isSealDue(Config $config): bool
    {
        $t = self::cfg($config);
        if ($t === null) {
            return false;
        }
        $months = max(1, min(24, (int) ($t['interval_months'] ?? 6)));
        $path = BASE_PATH . '/' . self::LAST_SEAL;
        if (!is_file($path)) {
            return true;
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        $lastTs = is_array($j) && isset($j['sealed_at']) ? strtotime((string) $j['sealed_at']) : false;
        if ($lastTs === false) {
            return true;
        }
        $next = (new DateTimeImmutable('@' . $lastTs))->add(new DateInterval('P' . $months . 'M'));

        return (new DateTimeImmutable('now')) >= $next;
    }

    /**
     * Verzegel capsule: humble code fossil, intent tail, boodschap aan toekomstige self.
     *
     * @return array{ok: bool, path?: string, error?: string}
     */
    public static function sealCapsule(Config $config, bool $force = false): array
    {
        $t = self::cfg($config);
        if ($t === null) {
            return ['ok' => false, 'error' => 'time_capsule disabled'];
        }
        if (!$force && !self::isSealDue($config)) {
            return ['ok' => false, 'error' => 'not due yet (use --force)'];
        }

        $dir = BASE_PATH . '/' . self::SEAL_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $humble = self::sampleHumbleCode($t);
        $intentFossil = self::intentLogFossil($t);
        $msg = (string) ($t['message_to_future_self_template'] ?? '');
        if ($msg === '') {
            $msg = 'To our future selves: remember the clumsy first steps, the Governor’s vision, and that growth is a process — stay humble.';
        }

        $capsule = [
            'sealed_at' => gmdate('c'),
            'php_version' => PHP_VERSION,
            'humble_code_sample' => $humble,
            'intent_log_fossil' => $intentFossil,
            'message_to_future_self' => $msg,
            'note' => 'Open in 6+ months to recall where we came from.',
        ];

        $fn = $dir . '/sealed_' . gmdate('Y-m-d_His') . '.json';
        $enc = json_encode($capsule, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (!is_string($enc) || @file_put_contents($fn, $enc . "\n", LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'cannot write capsule'];
        }

        @file_put_contents(
            BASE_PATH . '/' . self::LAST_SEAL,
            json_encode(['sealed_at' => $capsule['sealed_at'], 'file' => basename($fn)], JSON_PRETTY_PRINT) . "\n",
            LOCK_EX
        );

        EvolutionWikiService::appendTimeCapsuleIndex($config, basename($fn), $capsule['sealed_at']);
        EvolutionLogger::log('time_capsule', 'sealed', ['file' => basename($fn)]);

        return ['ok' => true, 'path' => $fn];
    }

    /**
     * @param array<string, mixed> $tCfg
     */
    private static function sampleHumbleCode(array $tCfg): string
    {
        $rel = (string) ($tCfg['humble_code_rel_path'] ?? 'src/app/Support/helpers.php');
        if (str_contains($rel, '..')) {
            return '';
        }
        $full = BASE_PATH . '/' . ltrim($rel, '/');
        if (!is_file($full)) {
            return '[no file at ' . $rel . ']';
        }
        $raw = (string) @file_get_contents($full);

        return mb_substr($raw, 0, (int) ($tCfg['humble_max_chars'] ?? 4000));
    }

    /**
     * @param array<string, mixed> $tCfg
     */
    private static function intentLogFossil(array $tCfg): string
    {
        $path = BASE_PATH . '/storage/evolution/intent_log.jsonl';
        if (!is_file($path)) {
            return '[intent_log missing — optional]';
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $n = max(1, min(20, (int) ($tCfg['intent_fossil_lines'] ?? 5)));

        return implode("\n", array_slice($lines, -$n));
    }
}
