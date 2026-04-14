<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Meta-evolution: appends learned rules to the Architect system prompt when the same
 * failure pattern repeats (e.g. Twig), based on Learning Loop history.
 */
final class PromptDNAEvolutionService
{
    private const STREAKS_FILE = 'storage/evolution/prompt_dna_streaks.json';
    private const RULES_FILE = 'storage/evolution/prompt_dna_rules.jsonl';

    /**
     * Call from LearningLoopService::record after writing history.
     *
     * @param array<string, mixed> $entry
     */
    public static function onLearningRecord(Config $config, array $entry): void
    {
        $pd = self::cfg($config);
        if ($pd === null || !filter_var($pd['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $type = (string)($entry['type'] ?? '');
        if ($type !== 'twig') {
            return;
        }
        if (($entry['ok'] ?? false) === true && ($entry['rolled_back'] ?? false) === false) {
            return;
        }

        $target = trim((string)($entry['target'] ?? ''));
        $reason = trim((string)($entry['rollback_reason'] ?? $entry['error'] ?? $entry['policy_violation'] ?? ''));
        if ($target === '' || $reason === '') {
            return;
        }

        $threshold = max(2, min(20, (int)($pd['failure_streak_threshold'] ?? 5)));
        $fingerprint = hash('sha256', $target . '|' . substr($reason, 0, 200));

        $path = BASE_PATH . '/' . self::STREAKS_FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $streaks = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $streaks = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        }
        if (!is_array($streaks)) {
            $streaks = [];
        }

        $streaks[$fingerprint] = [
            'count' => (int)($streaks[$fingerprint]['count'] ?? 0) + 1,
            'target' => $target,
            'last_reason' => $reason,
            'updated_at' => gmdate('c'),
        ];

        @file_put_contents($path, json_encode($streaks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($streaks[$fingerprint]['count'] < $threshold) {
            return;
        }

        $ruleLine = [
            'ts' => gmdate('c'),
            'fingerprint' => $fingerprint,
            'target' => $target,
            'hint' => 'Herhaalde Twig-fout (' . $streaks[$fingerprint]['count'] . 'x): ' . $reason
                . ' — gebruik alleen toegestane filters, vermijd brede selectors, test shadow template na apply.',
        ];
        $line = json_encode($ruleLine, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(BASE_PATH . '/' . self::RULES_FILE, $line, FILE_APPEND | LOCK_EX);

        unset($streaks[$fingerprint]);
        @file_put_contents($path, json_encode($streaks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        EvolutionLogger::log('prompt_dna', 'rule_appended', ['target' => $target, 'fingerprint' => $fingerprint]);
    }

    public static function promptAppend(Config $config): string
    {
        $pd = self::cfg($config);
        if ($pd === null || !filter_var($pd['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $maxChars = max(500, min(20000, (int)($pd['max_appendix_chars'] ?? 4000)));
        $path = BASE_PATH . '/' . self::RULES_FILE;
        if (!is_file($path)) {
            return '';
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return '';
        }
        $recent = array_slice($lines, -30);
        $hints = [];
        foreach ($recent as $line) {
            $j = @json_decode($line, true);
            if (is_array($j) && isset($j['hint'])) {
                $hints[] = '- ' . (string)$j['hint'];
            }
        }
        if ($hints === []) {
            return '';
        }
        $block = "\n\nPROMPT_DNA (meta-evolution — herhaalde fouten; volg deze hints strikt):\n" . implode("\n", $hints);
        if (strlen($block) > $maxChars) {
            $block = substr($block, 0, $maxChars) . "\n…(truncated)";
        }

        return $block;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $pd = is_array($evo) ? ($evo['prompt_dna'] ?? null) : null;

        return is_array($pd) ? $pd : null;
    }
}
