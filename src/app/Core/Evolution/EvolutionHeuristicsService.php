<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Zelf-curerende heuristieken uit de Learning Loop → RULES_OF_THUMB in prompts.
 */
final class EvolutionHeuristicsService
{
    private const RULES_FILE = 'storage/evolution/heuristics_rules.jsonl';

    /**
     * Herbouw heuristieken uit recente learning_history (failures/rollbacks).
     */
    public static function rebuildFromLearningHistory(Config $config): void
    {
        $h = self::cfg($config);
        if ($h === null || !filter_var($h['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $path = BASE_PATH . '/storage/evolution/learning_history.jsonl';
        if (!is_file($path)) {
            return;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        $lines = array_slice($lines, -800);

        $byTarget = [];
        $byType = ['twig' => 0, 'php' => 0];
        foreach ($lines as $line) {
            $j = @json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            if (($j['ok'] ?? true) === true && ($j['rolled_back'] ?? false) === false) {
                continue;
            }
            $t = trim((string)($j['target'] ?? ''));
            if ($t === '') {
                continue;
            }
            $byTarget[$t] = ($byTarget[$t] ?? 0) + 1;
            $ty = (string)($j['type'] ?? 'php');
            if (isset($byType[$ty])) {
                $byType[$ty]++;
            }
        }

        $minRepeat = max(2, min(10, (int)($h['min_repeat_for_rule'] ?? 3)));
        $newRules = [];
        foreach ($byTarget as $target => $cnt) {
            if ($cnt < $minRepeat) {
                continue;
            }
            $rule = "Vermijd herhaalde auto-apply fouten op `{$target}` ({$cnt}x recent); eerst handmatig review.";
            $newRules[] = $rule;
        }
        if ($byType['twig'] >= 5) {
            $newRules[] = 'Twig: na meerdere mislukte applies — kleinere patches, geen brede selectors, test shadow template.';
        }
        if ($byType['php'] >= 8) {
            $newRules[] = 'PHP shadow patches: run PHPUnit gate en Policy Guard voordat je opnieuw dezelfde namespace raakt.';

        }

        foreach (array_unique($newRules) as $rule) {
            self::appendRuleLine($config, $rule, 'learning_loop_aggregate');
        }
    }

    /**
     * Publieke regel toevoegen (bijv. handmatig of vanuit andere service).
     */
    public static function appendRule(Config $config, string $rule, string $source = 'manual'): void
    {
        $h = self::cfg($config);
        if ($h === null || !filter_var($h['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }
        $rule = trim($rule);
        if ($rule === '') {
            return;
        }
        self::appendRuleLine($config, $rule, $source);
    }

    public static function promptAppend(Config $config): string
    {
        $h = self::cfg($config);
        if ($h === null || !filter_var($h['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        if (filter_var($h['auto_rebuild_on_prompt'] ?? true, FILTER_VALIDATE_BOOL)) {
            self::rebuildFromLearningHistory($config);
        }

        $maxChars = max(500, min(12000, (int)($h['max_prompt_chars'] ?? 3500)));
        $maxRules = max(5, min(40, (int)($h['max_rules_in_prompt'] ?? 18)));

        $path = BASE_PATH . '/' . self::RULES_FILE;
        if (!is_file($path)) {
            return '';
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return '';
        }
        $rules = [];
        foreach (array_slice($lines, -$maxRules) as $line) {
            $j = @json_decode($line, true);
            if (is_array($j) && isset($j['rule'])) {
                $rules[] = '- ' . (string)$j['rule'];
            }
        }
        if ($rules === []) {
            return '';
        }
        $block = "\n\nRULES_OF_THUMB (EvolutionHeuristics — leer uit het verleden, niet herhalen):\n" . implode("\n", $rules);
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
        $h = is_array($evo) ? ($evo['heuristics'] ?? null) : null;

        return is_array($h) ? $h : null;
    }

    private static function appendRuleLine(Config $config, string $rule, string $source): void
    {
        if (self::ruleAlreadyStored($rule)) {
            return;
        }
        $path = BASE_PATH . '/' . self::RULES_FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $fp = hash('sha256', $rule);
        $row = [
            'ts' => gmdate('c'),
            'source' => $source,
            'rule' => $rule,
            'id' => substr($fp, 0, 16),
        ];
        @file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function ruleAlreadyStored(string $rule): bool
    {
        $path = BASE_PATH . '/' . self::RULES_FILE;
        if (!is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);

        return is_string($raw) && str_contains($raw, substr($rule, 0, 64));
    }
}
