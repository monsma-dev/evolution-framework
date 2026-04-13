<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Wraps PHPStan output into Architect / Ghost prompt text for aggressive cleanup (unused symbols).
 */
final class EvolutionDeadCodeNecromancyService
{
    public function promptSection(Config $config): string
    {
        $sa = $config->get('evolution.static_analysis', []);
        if (!is_array($sa) || !filter_var($sa['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $report = BASE_PATH . '/storage/evolution/static_analysis/last_phpstan.txt';
        if (!is_file($report) || filesize($report) < 20) {
            return "\n\nDEAD CODE / STATIC ANALYSIS: run EvolutionStaticAnalysisService (PHPStan level 9) to refresh last_phpstan.txt before necromancy proposals.";
        }

        $raw = (string) @file_get_contents($report);
        $snippet = mb_substr($raw, 0, 6000);
        if (trim($snippet) === '') {
            return '';
        }

        return "\n\nSTATIC ANALYSIS (PHPStan, necromancy — propose high_severity refactors to strip unreachable/unused code safely):\n"
            . $snippet
            . (mb_strlen($raw) > 6000 ? "\n…(truncated)…\n" : '');
    }
}
