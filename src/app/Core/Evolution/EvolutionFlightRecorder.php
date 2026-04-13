<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Flight recorder: volledige state-dump vlak voor undead-restore of bij Pulse-falen.
 */
final class EvolutionFlightRecorder
{
    private const DIR = 'storage/evolution/flight_recorder';

    /**
     * @param array<string, mixed> $extra
     *
     * @return array{ok: bool, file?: string, error?: string}
     */
    public static function capture(Config $config, string $reason, array $extra = []): array
    {
        $fr = self::cfg($config);
        if ($fr === null || !filter_var($fr['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'disabled'];
        }

        $dir = BASE_PATH . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'mkdir failed'];
        }

        $tail = max(10, min(200, (int)($fr['log_tail_lines'] ?? 50)));
        $maxFiles = max(5, min(50, (int)($fr['max_incident_files'] ?? 20)));

        $payload = [
            'ts' => gmdate('c'),
            'reason' => $reason,
            'extra' => $extra,
            'php' => PHP_VERSION,
            'memory_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'load_avg' => function_exists('sys_getloadavg') ? @sys_getloadavg() : null,
            'pulse_last' => EvolutionPulseService::lastState(),
            'access_log_tail' => self::tailConfiguredLog($config, 'access'),
            'error_log_tail' => self::tailConfiguredLog($config, 'error'),
            'php_error_tail' => self::tailLocalFile('storage/logs/php-error.log', $tail),
            'intent_log_tail' => self::tailIntentLog($tail),
            'learning_tail' => self::tailLocalFile('storage/evolution/learning_history.jsonl', $tail),
            'evolution_log_tail' => self::tailLocalFile('storage/evolution/timeline.jsonl', min($tail, 30)),
        ];

        $name = 'incident-' . gmdate('Ymd-His') . '-' . preg_replace('/[^a-z0-9_-]+/i', '_', substr($reason, 0, 40)) . '.json';
        $path = $dir . '/' . $name;
        if (@file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            return ['ok' => false, 'error' => 'write failed'];
        }

        self::pruneOld($dir, $maxFiles);
        EvolutionLogger::log('flight_recorder', 'captured', ['reason' => $reason, 'file' => $name]);

        return ['ok' => true, 'file' => $name];
    }

    /**
     * Korte tekst voor Architect-prompt na restore (post-mortem).
     */
    public static function latestSummaryForPrompt(Config $config): string
    {
        $fr = self::cfg($config);
        if ($fr === null || !filter_var($fr['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        if (!filter_var($fr['append_to_architect_prompt'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $dir = BASE_PATH . '/' . self::DIR;
        if (!is_dir($dir)) {
            return '';
        }
        $files = glob($dir . '/incident-*.json') ?: [];
        if ($files === []) {
            return '';
        }
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $raw = @file_get_contents($files[0]);
        if (!is_string($raw)) {
            return '';
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return '';
        }
        $reason = LogAnonymizerService::scrub((string)($j['reason'] ?? ''), $config);
        $pulse = $j['pulse_last'] ?? [];
        $la = $j['load_avg'] ?? null;
        $mem = (int)($j['memory_bytes'] ?? 0);

        $mb = round($mem / 1048576, 2);

        return "\n\nFLIGHT_RECORDER (laatste incident): reason={$reason}; pulse_status=" . (string)($pulse['status'] ?? '?')
            . '; load_avg=' . json_encode($la) . "; memory_mb={$mb}. "
            . "Lees storage/evolution/flight_recorder/ voor volledige dump. Leg uit wat er misging en hoe toekomstige snapshots veiliger worden.\n";
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $fr = is_array($evo) ? ($evo['flight_recorder'] ?? null) : null;

        return is_array($fr) ? $fr : null;
    }

    private static function tailConfiguredLog(Config $config, string $which): string
    {
        $fr = self::cfg($config);
        $key = $which === 'access' ? 'access_log_path' : 'error_log_path';
        $path = '';
        if (is_array($fr)) {
            $path = trim((string)($fr[$key] ?? ''));
        }
        if ($path === '') {
            return '(geen pad geconfigureerd in evolution.flight_recorder.' . $key . ')';
        }
        $full = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : BASE_PATH . '/' . ltrim($path, '/');

        return self::tailLocalFile($full, (int)(is_array($fr) ? ($fr['log_tail_lines'] ?? 50) : 50), true);
    }

    private static function tailLocalFile(string $relativeOrAbsolute, int $lines, bool $absolute = false): string
    {
        $path = $absolute ? $relativeOrAbsolute : (BASE_PATH . '/' . ltrim($relativeOrAbsolute, '/'));
        if (!is_file($path) || !is_readable($path)) {
            return '(bestand ontbreekt of niet leesbaar: ' . $path . ')';
        }
        $content = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($content)) {
            return '(kon log niet lezen)';
        }
        $slice = array_slice($content, -$lines);

        return implode("\n", $slice);
    }

    private static function tailIntentLog(int $lines): string
    {
        $path = BASE_PATH . '/storage/evolution/intent_log.jsonl';
        return self::tailLocalFile($path, $lines, true);
    }

    private static function pruneOld(string $dir, int $keep): void
    {
        $files = glob($dir . '/incident-*.json') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
