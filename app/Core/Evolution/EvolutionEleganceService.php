<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Code poetry / standards guard: rejects patches that are technically valid but structurally poor.
 */
final class EvolutionEleganceService
{
    /**
     * @return string|null error message if should reject
     */
    public static function rejectIfUgly(string $php, Config $config): ?string
    {
        $cfg = $config;
        $el = $cfg->get('evolution.elegance', []);
        if (!is_array($el) || !filter_var($el['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $maxNesting = max(4, (int) ($el['max_if_nesting'] ?? 6));
        $nesting = self::maxBraceNesting($php);
        if ($nesting > $maxNesting) {
            return 'Elegance: te diepe geneste if/structuren (' . $nesting . ' > ' . $maxNesting . ') — refactoren naar early returns of kleine methodes.';
        }

        if (preg_match_all('/\$([a-z]){1,2}\b/', $php, $m) && count($m[0]) > (int) ($el['max_single_letter_vars'] ?? 8)) {
            return 'Elegance: te veel enkel-letter variabelen — gebruik beschrijvende namen.';
        }

        if (substr_count($php, 'else if') + substr_count($php, 'elseif') > (int) ($el['max_else_if_chains'] ?? 6)) {
            return 'Elegance: lange elseif-keten — overweeg match/switch of strategy.';
        }

        return null;
    }

    private static function maxBraceNesting(string $code): int
    {
        $max = 0;
        $depth = 0;
        $len = strlen($code);
        for ($i = 0; $i < $len; $i++) {
            $c = $code[$i];
            if ($c === '{') {
                $depth++;
                $max = max($max, $depth);
            } elseif ($c === '}') {
                $depth = max(0, $depth - 1);
            }
        }

        return $max;
    }
}
