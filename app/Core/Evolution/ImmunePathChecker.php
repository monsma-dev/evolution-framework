<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Shared hot-path immunity check for auto_apply targets (FQCN or template path).
 */
final class ImmunePathChecker
{
    public static function isImmune(string $target, Config $cfg): bool
    {
        if (EvolutionIgnoreRegistry::matches($target, $cfg)) {
            return true;
        }

        $evo = $cfg->get('evolution', []);
        $dr = is_array($evo) ? ($evo['dynamic_routing'] ?? []) : [];
        if (is_array($dr) && filter_var($dr['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            foreach ($dr['immune_whitelist_substrings'] ?? ['Domain\\Web\\Controllers\\Evolution\\'] as $w) {
                $w = (string) $w;
                if ($w !== '' && str_contains($target, $w)) {
                    return false;
                }
            }
        }

        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];
        $immune = is_array($aa) ? ($aa['immune_paths'] ?? []) : [];
        if (!is_array($immune) || $immune === []) {
            return false;
        }

        $targetLower = strtolower(str_replace('\\', '/', $target));
        foreach ($immune as $pattern) {
            $patternLower = strtolower(str_replace('\\', '/', (string) $pattern));
            if ($patternLower === '') {
                continue;
            }
            if (str_contains($targetLower, $patternLower)) {
                return true;
            }
        }

        return false;
    }
}
