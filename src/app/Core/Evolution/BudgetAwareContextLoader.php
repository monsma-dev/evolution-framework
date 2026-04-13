<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Glue for Architect prompts: vault Stage 1 + optional vector recall tuning via config.
 */
final class BudgetAwareContextLoader
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function appendToSystemPrompt(Config $config, array $messages): string
    {
        return EvolutionVaultService::promptBudgetAwareStage1($config, $messages);
    }
}
