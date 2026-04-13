<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Predictive "Opportunity Score": blends infra risk, growth/search demand, and revenue metrics for roadmap hints.
 */
final class EvolutionOracleService
{
    public const FORECAST_FILE = 'storage/evolution/oracle_forecast.json';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{opportunity_score: float, narrative: string, factors: array<string, mixed>}
     */
    public function computeForecast(): array
    {
        $cfg = $this->container->get('config');
        $o = $cfg->get('evolution.oracle', []);
        if (!is_array($o) || !filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return [
                'opportunity_score' => 0.0,
                'narrative' => 'Oracle disabled.',
                'factors' => [],
            ];
        }

        $sentinel = (new EvolutionInfrasentinelService($this->container))->snapshotForHealth();
        $risk = 0.0;
        if (!empty($sentinel['enabled']) && isset($sentinel['infrastructure_risk_score'])) {
            $risk = min(100, (float) $sentinel['infrastructure_risk_score']);
        }

        $conv = RevenueGuardService::conversionMetrics24h();
        $convRate = (float) ($conv['conversion_rate_pct'] ?? 0);

        $searchDemand = $this->searchDemandScore();

        $jitSnap = OpcacheIntelligenceService::jitSnapshot($cfg);
        $hitPct = (float) ($jitSnap['opcache_hit_rate'] ?? 0);
        $bufPct = (float) ($jitSnap['buffer_usage_pct'] ?? 0);
        $jitEffHint = min(
            15.0,
            max(0.0, (100.0 - $hitPct) * 0.12 + max(0.0, $bufPct - 75.0) * 0.1)
        );

        $infraOpportunity = min(40, $risk * 0.35);
        $uxOpportunity = max(0, 25 - min(25, $convRate * 2));
        $growthOpportunity = min(35, $searchDemand);

        $score = min(100, round($infraOpportunity + $uxOpportunity + $growthOpportunity, 1));

        $narrative = 'Opportunity score ' . $score . '/100. ';
        if ($risk > 40) {
            $narrative .= 'Infrastructure signals suggest prioritizing version upgrades (e.g. MySQL) before large UX wins. ';
        }
        if ($convRate < 5 && ($conv['views'] ?? 0) > 50) {
            $narrative .= 'Conversion has headroom — checkout latency experiments may yield several %. ';
        }
        if ($searchDemand > 15) {
            $narrative .= 'Search demand indicates new tool pages (compare, filters) could capture intent. ';
        }
        if ($hitPct > 0) {
            $narrative .= 'OPcache/JIT: hit≈' . round($hitPct, 1) . '%, JIT buffer≈' . round($bufPct, 1)
                . '% — refactors that reduce churn and batch patches may recover ~' . round($jitEffHint, 1)
                . '% effective hot-path throughput once the JIT re-specializes. ';
        }

        $factors = [
            'infrastructure_risk' => $risk,
            'conversion_rate_pct' => $convRate,
            'search_demand_score' => $searchDemand,
            'opcache_hit_rate_pct' => $hitPct,
            'jit_buffer_usage_pct' => $bufPct,
            'jit_efficiency_recovery_hint_pct' => round($jitEffHint, 1),
        ];

        $this->persistForecast($score, $narrative, $factors);

        return [
            'opportunity_score' => $score,
            'narrative' => trim($narrative),
            'factors' => $factors,
        ];
    }

    public function promptSection(): string
    {
        $cfg = $this->container->get('config');
        $o = $cfg->get('evolution.oracle', []);
        if (!is_array($o) || !filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $f = $this->computeForecast();

        return "\n\nORACLE (business roadmap hints — advisory):\n"
            . '  opportunity_score: ' . $f['opportunity_score'] . "\n"
            . '  ' . $f['narrative'] . "\n"
            . '  Align technical upgrades (infra sentinel) with CRO/checkout wins; use shadow deploys before production.';
    }

    private function searchDemandScore(): float
    {
        $path = BASE_PATH . '/' . EvolutionGrowthHackerService::SEARCH_LOG;
        if (!is_file($path)) {
            return 0.0;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $sum = 0;
        foreach (array_slice($lines, -2000) as $line) {
            $j = json_decode((string) $line, true);
            if (!is_array($j)) {
                continue;
            }
            $sum += max(1, (int) ($j['n'] ?? $j['count'] ?? 1));
        }

        return min(35, log(1 + $sum) * 5);
    }

    /**
     * @param array<string, mixed> $factors
     */
    private function persistForecast(float $score, string $narrative, array $factors): void
    {
        $path = BASE_PATH . '/' . self::FORECAST_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = [
            'updated_at' => gmdate('c'),
            'opportunity_score' => $score,
            'narrative' => $narrative,
            'factors' => $factors,
            'business_roadmap_widget' => [
                'title' => 'AI opportunity',
                'score' => $score,
                'summary' => $narrative,
            ],
        ];
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
