<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Koppelt geschatte AI-kosten (TokenTracker / credit-ledger) aan CRO-conversie en geschatte omzet.
 * Dashboard: AI Profitability Score + narrative; optioneel auto-throttle (goedkoop model) bij lage ROI.
 */
final class EvolutionProfitabilityService
{
    /**
     * @return array<string, mixed>
     */
    private static function profitabilityConfig(Config $config): array
    {
        $evo = $config->get('evolution', []);
        if (!is_array($evo)) {
            return [];
        }
        $p = $evo['profitability'] ?? [];

        return is_array($p) ? $p : [];
    }

    public static function isEnabled(Config $config): bool
    {
        $p = self::profitabilityConfig($config);

        return is_array($p) && filter_var($p['enabled'] ?? true, FILTER_VALIDATE_BOOL);
    }

    /**
     * Best-effort: forceer goedkoop model wanneer ROI onder drempel ligt (geen circulaire call naar evaluateBeforeCall).
     */
    public static function shouldForceCheapDueToRoi(Config $config): bool
    {
        $p = self::profitabilityConfig($config);
        if (!is_array($p) || !filter_var($p['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return false;
        }
        if (!filter_var($p['auto_throttle'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $minSpend = (float)($p['min_spend_eur_before_throttle'] ?? 0.25);
        $minRoi = (float)($p['min_roi_ratio'] ?? 1.0);
        $perConv = (float)($p['estimated_value_per_conversion_eur'] ?? 0.0);
        if ($perConv <= 0) {
            return false;
        }

        $monitor = new AiCreditMonitor($config);
        $spend = $monitor->getLedgerSpendSummary();
        $today = (float)($spend['today_eur'] ?? 0.0);
        if ($today < $minSpend) {
            return false;
        }

        $conv = RevenueGuardService::conversionMetrics24h();
        $conversions = (int)($conv['conversions'] ?? 0);
        $estimatedRevenue = $conversions * $perConv;
        $roi = $estimatedRevenue / max(0.0001, $today);

        return $roi < $minRoi;
    }

    /**
     * Volledige snapshot voor admin-dashboard + optioneel wegschrijven naar JSON.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Config $config): array
    {
        $p = self::profitabilityConfig($config);
        if (!is_array($p) || !filter_var($p['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return [
                'ok' => true,
                'enabled' => false,
                'message' => 'Evolution profitability is uitgeschakeld in evolution.profitability.',
            ];
        }

        $perConv = (float)($p['estimated_value_per_conversion_eur'] ?? 0.0);
        $wValue = (float)($p['score_weight_value'] ?? 0.55);
        $wCost = (float)($p['score_weight_cost'] ?? 0.45);
        $sumW = $wValue + $wCost;
        if ($sumW <= 0) {
            $wValue = 0.55;
            $wCost = 0.45;
            $sumW = 1.0;
        }
        $wValue /= $sumW;
        $wCost /= $sumW;

        $monitor = new AiCreditMonitor($config);
        $spend = $monitor->getLedgerSpendSummary();
        $today = (float)($spend['today_eur'] ?? 0.0);
        $last7 = (float)($spend['last_7d_eur'] ?? 0.0);

        $conv = RevenueGuardService::conversionMetrics24h();
        $conversions = (int)($conv['conversions'] ?? 0);
        $views = (int)($conv['views'] ?? 0);
        $ratePct = (float)($conv['conversion_rate_pct'] ?? 0.0);

        $estimatedRevenue24h = $perConv > 0
            ? round($conversions * $perConv, 2)
            : null;

        $roi = null;
        if ($perConv > 0 && $today > 0) {
            $roi = ($estimatedRevenue24h ?? 0) / $today;
        } elseif ($perConv > 0 && $today <= 0 && ($estimatedRevenue24h ?? 0) > 0) {
            $roi = 999.0;
        } elseif ($perConv > 0) {
            $roi = 0.0;
        }

        $valueNorm = min(100.0, $ratePct * 5.0);
        $costNorm = min(100.0, $today * (float)($p['cost_penalty_scale'] ?? 18.0));
        $score = (int) round(max(0.0, min(100.0, $wValue * $valueNorm + $wCost * (100.0 - $costNorm))));

        if ($perConv > 0 && $roi !== null) {
            $roiScore = min(100.0, 35.0 * log10(1.0 + max(0.0, $roi)));
            $score = (int) round(max(0.0, min(100.0, 0.5 * $score + 0.5 * $roiScore)));
        }

        $minRoi = (float)($p['min_roi_ratio'] ?? 1.0);
        $recommendation = 'neutral';
        if ($roi !== null && $roi >= $minRoi * 2.0 && $today > 0.01) {
            $recommendation = 'scale';
        } elseif ($roi !== null && $roi < $minRoi && $today >= (float)($p['min_spend_eur_before_throttle'] ?? 0.25)) {
            $recommendation = 'throttle';
        }

        $narrative = self::buildNarrativeNl(
            $today,
            $last7,
            $conversions,
            $views,
            $ratePct,
            $estimatedRevenue24h,
            $roi,
            $recommendation,
            $perConv
        );

        $out = [
            'ok' => true,
            'enabled' => true,
            'generated_at' => gmdate('c'),
            'spend' => $spend,
            'conversion_24h' => $conv,
            'estimated_revenue_24h_eur' => $estimatedRevenue24h,
            'estimated_value_per_conversion_eur' => $perConv,
            'roi_ratio' => $roi !== null ? round($roi, 4) : null,
            'ai_profitability_score' => $score,
            'recommendation' => $recommendation,
            'auto_throttle_would_apply' => self::shouldForceCheapDueToRoi($config),
            'narrative_nl' => $narrative,
        ];

        $snapPath = trim((string)($p['snapshot_path'] ?? 'storage/evolution/profitability_snapshot.json'));
        if ($snapPath !== '') {
            $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
            $full = $base . '/' . ltrim($snapPath, '/');
            $dir = dirname($full);
            if (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) {
                $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if ($json !== false) {
                    @file_put_contents($full, $json, LOCK_EX);
                }
            }
        }

        return $out;
    }

    /**
     * @param float|null $estimatedRevenue24h
     * @param float|null $roi
     */
    private static function buildNarrativeNl(
        float $todayEur,
        float $last7Eur,
        int $conversions,
        int $views,
        float $ratePct,
        $estimatedRevenue24h,
        $roi,
        string $recommendation,
        float $perConv
    ): string {
        $t = number_format($todayEur, 2, ',', '');
        $w = number_format($last7Eur, 2, ',', '');

        $tail = match ($recommendation) {
            'scale' => 'Ruimte om AI-intensiever in te zetten waar het rendement het draagt.',
            'throttle' => 'Overweeg goedkopere modellen of minder calls tot de ROI verbetert.',
            default => 'Blijf monitoren: balans tussen API-kosten en conversiewaarde.',
        };

        if ($perConv <= 0) {
            return sprintf(
                'Vandaag lag de geschatte AI-kosten rond €%s (afgelopen 7 dagen: €%s). '
                . 'Zet evolution.profitability.estimated_value_per_conversion_eur om ROI en omzetcontribution te tonen. '
                . 'Conversieratio (24u): %.2f%% (%d views). %s',
                $t,
                $w,
                $ratePct,
                $views,
                $tail
            );
        }

        $rev = $estimatedRevenue24h !== null ? number_format($estimatedRevenue24h, 2, ',', '') : '—';
        $roiS = $roi !== null ? number_format($roi, 2, ',', '') : '—';

        return sprintf(
            'Vandaag lag de geschatte AI-kosten rond €%s (afgelopen 7 dagen: €%s). '
            . 'In de laatste 24 uur zag ik %d conversies; geschatte omzetcontribution is €%s (ROI-verhouding ≈ %s). %s',
            $t,
            $w,
            $conversions,
            $rev,
            $roiS,
            $tail
        );
    }
}
