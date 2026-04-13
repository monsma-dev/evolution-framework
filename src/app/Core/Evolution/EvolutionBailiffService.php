<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Orde: timers + budget pre-flight + (optioneel) deploy-uitvoering na vonnis.
 */
final class EvolutionBailiffService
{
    public function __construct(private readonly Container $container)
    {
    }

    public function timeRemaining(float $deadline): float
    {
        return max(0.0, $deadline - microtime(true));
    }

    public function isTimedOut(float $deadline): bool
    {
        return microtime(true) >= $deadline;
    }

    /**
     * @return array{ok: bool, reason?: string}
     */
    public function assertBudgetAllowsSession(Config $config): array
    {
        $monitor = new AiCreditMonitor($config);

        return $monitor->assertMonthBudgetHeadroomForPremiumCall(0.25);
    }

    /**
     * @return array{ok: bool, plan?: array<string, mixed>, error?: string}
     */
    public function executeDeployAfterPositiveVerdict(Config $config): array
    {
        $d = EvolutionDeployDroneService::planAtomicSwap($config);
        if (!($d['ok'] ?? false)) {
            return ['ok' => false, 'error' => $d['error'] ?? 'plan failed'];
        }

        EvolutionLogger::log('bailiff', 'deploy_plan', ['dry_run' => $d['dry_run'] ?? true]);

        return ['ok' => true, 'plan' => $d];
    }
}
