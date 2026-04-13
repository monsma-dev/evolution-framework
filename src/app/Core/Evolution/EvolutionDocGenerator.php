<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Doc-pen — Junior drafts PHPDoc / human-readable notes for the Governor (via EvolutionMentorService).
 */
final class EvolutionDocGenerator
{
    /**
     * @return array{ok: bool, text?: string, error?: string}
     */
    public static function draftDocblockForSnippet(Config $config, string $classOrFunctionName, string $phpSnippet): array
    {
        $mentor = new EvolutionMentorService();
        $instruction = 'Write a precise PHPDoc block (with @param/@return where applicable) for this PHP snippet. '
            . 'Name context: ' . $classOrFunctionName . '. Output ONLY the docblock comment lines.';

        return $mentor->juniorDelegate($config, 'doc_pen', $instruction, $phpSnippet);
    }
}
