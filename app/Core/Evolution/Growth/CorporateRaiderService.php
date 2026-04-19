<?php

declare(strict_types=1);

namespace App\Core\Evolution\Growth;

use App\Core\Config;
use App\Core\Evolution\EvolutionLogger;

/**
 * Supervised "corporate raider" — scans for opportunities when vault balance crosses a threshold.
 *
 * Does NOT negotiate, purchase, or sign contracts. Logs intent for human execution only.
 */
final class CorporateRaiderService
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param array{vault_eur?: float} $vaultState From trading summary or telemetry.
     * @return array{active: bool, reason: string, ideas: list<string>}
     */
    public function evaluate(array $vaultState = []): array
    {
        $evo = $this->config->get('evolution.corporate_raider', []);
        if (!is_array($evo) || !filter_var($evo['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['active' => false, 'reason' => 'corporate_raider disabled', 'ideas' => []];
        }

        $trigger = max(1.0, (float)($evo['vault_trigger_eur'] ?? 120.0));
        $eur     = (float)($vaultState['vault_eur'] ?? 0.0);
        if ($eur < $trigger) {
            return [
                'active' => false,
                'reason' => sprintf('vault %.2f EUR < trigger %.2f EUR', $eur, $trigger),
                'ideas'  => [],
            ];
        }

        $ideas = [
            'Review aged affiliate domains (auction/marketplace listings) — manual DD only',
            'Micro-SaaS listings (Acquire.com, Flippa) — verify revenue and churn manually',
            'Social handles: use escrow marketplaces; no direct wallet payments',
        ];

        EvolutionLogger::log('growth', 'corporate_raider_scan', [
            'vault_eur' => $eur,
            'trigger'   => $trigger,
        ]);

        return [
            'active' => true,
            'reason' => sprintf('vault %.2f EUR >= %.2f EUR — human review required before any spend', $eur, $trigger),
            'ideas'  => $ideas,
        ];
    }
}
