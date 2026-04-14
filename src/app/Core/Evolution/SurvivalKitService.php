<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * "Rugzak": golden config copies + env key names (never values) + optional emergency script excerpt for Architect prompts.
 */
final class SurvivalKitService
{
    /**
     * Prominente NL-regel + golden-config namen + rest van de survival-context.
     * Gebruik dit voor de Architect system prompt (de AI ziet letterlijk welke golden configs meegaan).
     */
    public static function getRugzakContext(Config $config): string
    {
        $evo = $config->get('evolution', []);
        if (!is_array($evo)) {
            return '';
        }
        $sk = $evo['survival_kit'] ?? [];
        if (!is_array($sk) || !filter_var($sk['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $maxChars = max(500, min(50000, (int) ($sk['max_chars'] ?? 8000)));
        $maxGolden = max(1, min(10, (int) ($sk['max_golden_copies'] ?? 3)));
        $globPat = trim((string) ($sk['golden_state_glob'] ?? 'storage/evolution/golden_state/*.json'));
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);

        $pattern = $base . '/' . ltrim(str_replace('\\', '/', $globPat), '/');
        $files = @glob($pattern) ?: [];
        $goldenNames = [];
        if ($files !== []) {
            usort($files, static function (string $a, string $b): int {
                return filemtime($b) <=> filemtime($a);
            });
            $files = array_slice($files, 0, $maxGolden);
            foreach ($files as $path) {
                $goldenNames[] = basename($path);
            }
        }

        $n = count($goldenNames);
        if ($n > 0) {
            $rugzakLead = "\n\n**In je rugzak zitten deze {$n} golden configs:** " . implode(', ', $goldenNames) . ".\n";
        } else {
            $rugzakLead = "\n\n**In je rugzak:** nog geen golden_state-bestanden (glob `{$globPat}`) — voeg JSON onder storage/evolution/golden_state/ toe voor vaste herstelpunten.\n";
        }

        $parts = [$rugzakLead, "\n## Survival kit (golden state + recovery hints)\n"];
        $parts[] = 'Env key names to preserve (values are never shown here): ';
        $keyNames = $sk['env_key_names'] ?? ['APP_ENV', 'DB_HOST'];
        $names = is_array($keyNames) ? $keyNames : [];
        $listed = [];
        foreach ($names as $k) {
            $k = trim((string) $k);
            if ($k === '') {
                continue;
            }
            $listed[] = $k . (getenv($k) !== false ? ' [present in runtime]' : ' [unset]');
        }
        $parts[] = $listed === [] ? '(none configured)' : implode(', ', $listed);
        $parts[] = "\n";

        if ($files !== []) {
            $parts[] = "### Golden state inhoud (nieuwste eerst, max {$maxGolden})\n";
            foreach ($files as $path) {
                $bn = basename($path);
                $raw = @file_get_contents($path);
                $snippet = is_string($raw) ? $raw : '';
                if ($snippet === '') {
                    continue;
                }
                $parts[] = "--- {$bn} ---\n";
                $parts[] = self::truncate($snippet, (int) ($maxChars / max(1, count($files)))) . "\n";
            }
        }

        $scriptRel = trim((string) ($sk['emergency_script'] ?? ''));
        if ($scriptRel !== '') {
            $sp = $base . '/' . ltrim($scriptRel, '/');
            if (is_file($sp) && is_readable($sp)) {
                $ex = (string) @file_get_contents($sp);
                $parts[] = "### Emergency restore script (excerpt)\n";
                $parts[] = self::truncate($ex, 2500) . "\n";
            }
        }

        $block = implode('', $parts);

        return self::truncate($block, $maxChars);
    }

    public static function promptAppend(Config $config): string
    {
        return self::getRugzakContext($config);
    }

    private static function truncate(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($s) <= $max) {
                return $s;
            }

            return mb_substr($s, 0, $max) . "\n… [truncated]\n";
        }
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max) . "\n… [truncated]\n";
    }
}
