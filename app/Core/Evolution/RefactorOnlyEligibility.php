<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Gates auto-apply for non-breaking refactors: identical public API + minimum DNA gain.
 */
final class RefactorOnlyEligibility
{
    /**
     * @return array{ok: bool, error?: string, before_score?: int, after_score?: int}
     */
    public static function assertEligible(Config $config, string $fqcn, string $newPhp): array
    {
        $evo = $config->get('evolution', []);
        $ro = is_array($evo) ? ($evo['refactor_only_autonomy'] ?? []) : [];
        if (!is_array($ro) || !filter_var($ro['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'refactor_only_autonomy disabled'];
        }

        $minGain = max(1, (int)($ro['min_dna_gain'] ?? 2));

        if (!PublicSignatureComparer::publicSignaturesMatch($fqcn, $newPhp)) {
            return ['ok' => false, 'error' => 'Public signatures differ from live file — refactor-only blocked'];
        }

        $dna = new CodeDnaScoringService();
        $before = $dna->scoreClass($fqcn)['score'];
        $after = $dna->scorePhpSource($fqcn, $newPhp)['score'];

        if ($after < $before + $minGain) {
            return [
                'ok' => false,
                'error' => "DNA gain too small: {$before} → {$after} (need +{$minGain})",
                'before_score' => $before,
                'after_score' => $after,
            ];
        }

        return ['ok' => true, 'before_score' => $before, 'after_score' => $after];
    }

    /**
     * Same structural rules for autonomous "high" applies under weekly budget.
     *
     * @return array{ok: bool, error?: string, before_score?: int, after_score?: int}
     */
    public static function assertForHighBudget(Config $config, string $fqcn, string $newPhp): array
    {
        $evo = $config->get('evolution', []);
        $eb = is_array($evo) ? ($evo['evolutionary_budget'] ?? []) : [];
        if (!is_array($eb) || !filter_var($eb['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'evolutionary_budget disabled'];
        }

        $ro = is_array($evo) ? ($evo['refactor_only_autonomy'] ?? []) : [];
        $minGain = max(1, (int)($eb['min_dna_gain'] ?? $ro['min_dna_gain'] ?? 2));

        if (!PublicSignatureComparer::publicSignaturesMatch($fqcn, $newPhp)) {
            return ['ok' => false, 'error' => 'Public signatures differ — high budget apply blocked'];
        }

        $dna = new CodeDnaScoringService();
        $before = $dna->scoreClass($fqcn)['score'];
        $after = $dna->scorePhpSource($fqcn, $newPhp)['score'];

        if ($after < $before + $minGain) {
            return [
                'ok' => false,
                'error' => "DNA gain too small for budget apply: {$before} → {$after} (need +{$minGain})",
                'before_score' => $before,
                'after_score' => $after,
            ];
        }

        return ['ok' => true, 'before_score' => $before, 'after_score' => $after];
    }
}
