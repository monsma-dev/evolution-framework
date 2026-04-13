<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Test-simulator facade — PHPUnit gate for Junior / preflight (no authority over Judge/Police).
 */
final class EvolutionTrialRunner
{
    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function runFullSuite(int $timeoutSeconds = 180): array
    {
        return EvolutionTestingService::runPhpUnit([], max(30, min(900, $timeoutSeconds)));
    }

    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function runFilter(string $filter, int $timeoutSeconds = 120): array
    {
        $f = trim($filter);

        return EvolutionTestingService::runPhpUnit(
            $f === '' ? [] : ['--filter', $f],
            max(30, min(600, $timeoutSeconds))
        );
    }
}
