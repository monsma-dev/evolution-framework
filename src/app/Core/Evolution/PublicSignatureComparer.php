<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Compares normalized public method signatures between two PHP sources.
 * Used for refactor-only auto-apply (external API must stay identical).
 */
final class PublicSignatureComparer
{
    /**
     * @return list<string> sorted normalized public method signatures
     */
    public static function extractPublicSignatures(string $source): array
    {
        $sigs = [];
        if (preg_match_all('/public\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)(?:\s*:\s*([^{]+))?/s', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = trim($m[1]);
                $params = preg_replace('/\s+/', ' ', trim($m[2]));
                $return = isset($m[3]) ? preg_replace('/\s+/', ' ', trim($m[3])) : '';
                $sigs[] = "{$name}({$params}):{$return}";
            }
        }
        sort($sigs);

        return $sigs;
    }

    public static function publicSignaturesMatch(string $fqcn, string $newSource): bool
    {
        $relative = str_replace('\\', '/', substr($fqcn, 4));
        $origPath = BASE_PATH . '/src/app/' . $relative . '.php';
        $patchFile = BASE_PATH . '/storage/patches/' . $relative . '.php';
        if (is_file($patchFile)) {
            $origPath = $patchFile;
        }
        if (!is_file($origPath)) {
            return false;
        }
        $origSource = @file_get_contents($origPath);
        if (!is_string($origSource)) {
            return false;
        }

        return self::extractPublicSignatures($origSource) === self::extractPublicSignatures($newSource);
    }
}
