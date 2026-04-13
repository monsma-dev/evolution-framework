<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * R&D-autopilot: GitHub/web discovery, raw-read via GithubCdnBridge, framework-fit vs DNA/KG.
 */
final class EvolutionLibraryScoutService
{
    /**
     * Vaste instructies voor Architect-chat (Borrow & Better).
     */
    public static function promptAppend(Config $config): string
    {
        $sc = self::cfg($config);
        if (!filter_var($sc['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $dna = new CodeDnaScoringService();
        $critical = array_slice($dna->getCriticalClasses($config), 0, 5);
        $critLine = $critical === []
            ? '(geen lage DNA-scores in top 5)'
            : implode(', ', array_map(static fn (array $c) => $c['fqcn'] . ' (' . $c['score'] . '/10)', $critical));

        return <<<PROMPT


LIBRARY_SCOUT (open source explorer — Borrow & Better):
- Gebruik web_search + GitHub om libraries te vinden die een concreet probleem oplossen (verifieer licentie: MIT/BSD/Apache prefereren).
- Voor "strip & own": lees GEEN hele vendor trees in de prompt; vraag om raw.githubusercontent.com URLs of gebruik admin API github-raw om max. ~500KB snippet te bekijken.
- Framework-fit: vergelijk met Knowledge Graph en lage Code-DNA targets: {$critLine}
- Voorkeur: kopieer alleen essentiële logica (enkele classes) naar App\\Support\\* met strict_types, PHP 8.3+ syntax, geen onnodige dependencies — geen 10MB pakket als 200 regels volstaan.
- Shadow deploy: alleen Evolution-namespace / storage patches volgens bestaande ArchitecturalPolicyGuard.
- dependency-audit endpoint: pulse vs vendor-grootte laten meewegen vóór nieuwe composer require.

PROMPT;
    }

    /**
     * @return array{ok: bool, block?: string, error?: string}
     */
    public static function searchLibrariesForProblem(Config $config, string $problem): array
    {
        $sc = self::cfg($config);
        if (!filter_var($sc['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'library_scout disabled'];
        }
        $q = trim($problem);
        if ($q === '') {
            return ['ok' => false, 'error' => 'empty problem'];
        }
        $q = 'site:github.com PHP library ' . $q;

        return WebSearchAdapter::buildContextBlock($config, $q);
    }

    /**
     * @return array{ok: bool, body?: string, error?: string}
     */
    public static function fetchAndSummarizeRaw(Config $config, string $url): array
    {
        $sc = self::cfg($config);
        if (!filter_var($sc['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'library_scout disabled'];
        }
        $max = max(10000, min(2000000, (int)($sc['max_raw_bytes'] ?? 524288)));

        return GithubCdnBridge::fetchRaw($url, $max);
    }

    /**
     * @return array<string, mixed>
     */
    private static function cfg(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $s = is_array($evo) ? ($evo['library_scout'] ?? []) : [];
        if (!is_array($s)) {
            $s = [];
        }

        return array_merge([
            'enabled' => true,
            'max_raw_bytes' => 524288,
        ], $s);
    }
}
