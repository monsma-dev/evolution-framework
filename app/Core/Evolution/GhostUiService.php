<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Proactieve UI: signaleert CRO-stappen met veel views maar weinig clicks en zet een A/B-experiment klaar (CSS).
 */
final class GhostUiService
{
    /**
     * @return array{ok: bool, experiment?: string, skipped?: string, error?: string}
     */
    public function maybeCreateProactiveExperiment(Container $container): array
    {
        $cfg = $container->get('config');
        $g = self::cfg($cfg);
        if ($g === null || !filter_var($g['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'skipped' => 'ghost_ui disabled'];
        }

        if (EvolutionKillSwitchService::isPaused($cfg)) {
            return ['ok' => true, 'skipped' => 'kill-switch'];
        }

        $minViews = max(10, (int)($g['min_step_views'] ?? 40));
        $maxClickRatio = (float)($g['max_click_ratio'] ?? 0.08);

        $byStep = $this->aggregateCroSteps();
        if ($byStep === []) {
            return ['ok' => true, 'skipped' => 'no cro data'];
        }

        foreach ($byStep as $step => $agg) {
            $views = (int)($agg['views'] ?? 0);
            $clicks = (int)($agg['clicks'] ?? 0);
            if ($views < $minViews) {
                continue;
            }
            $ratio = $views > 0 ? $clicks / $views : 0;
            if ($ratio > $maxClickRatio) {
                continue;
            }

            $expId = 'ghost_ui_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($step));
            $expId = substr($expId, 0, 64);
            if (strlen($expId) < 6) {
                continue;
            }

            $label = 'Ghost UI: verhoog zichtbaarheid CTA — ' . $step;
            $selector = (string)($g['cta_css_scope'] ?? '.admin-dashboard-page button,.admin-dashboard-page a.btn');
            $treatment = $selector . '{ min-height:2.75rem; padding:0.5rem 1rem; font-weight:600; box-shadow:0 1px 2px rgba(0,0,0,.08); }';

            $ab = new DesignAbService();
            $save = $ab->saveExperiment([
                'id' => $expId,
                'label' => $label,
                'variants' => [
                    ['name' => 'control', 'css_snippet' => '/* baseline */'],
                    ['name' => 'larger_cta', 'css_snippet' => $treatment],
                ],
            ]);

            if (!($save['ok'] ?? false)) {
                return ['ok' => false, 'error' => $save['error'] ?? 'save experiment'];
            }

            EvolutionHeuristicsService::appendRule(
                $cfg,
                "CRO stap `{$step}` had lage click-ratio (≈" . round($ratio * 100, 1) . '%); experiment `' . $expId . '` staat klaar in ab_experiments.json.',
                'ghost_ui'
            );

            EvolutionLogger::log('ghost_ui', 'experiment_created', ['experiment' => $expId, 'step' => $step]);

            return ['ok' => true, 'experiment' => $expId];
        }

        return ['ok' => true, 'skipped' => 'no qualifying step'];
    }

    /**
     * @return array<string, array{views: int, clicks: int}>
     */
    private function aggregateCroSteps(): array
    {
        $path = BASE_PATH . '/data/evolution/cro_events.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $cutoff = gmdate('c', time() - 86400);
        $by = [];
        foreach (array_slice($lines, -3000) as $line) {
            $j = @json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $ts = (string)($j['timestamp'] ?? $j['ts'] ?? '');
            if ($ts < $cutoff) {
                continue;
            }
            $step = (string)($j['step'] ?? 'unknown');
            if (!isset($by[$step])) {
                $by[$step] = ['views' => 0, 'clicks' => 0];
            }
            $action = strtolower((string)($j['action'] ?? $j['event_type'] ?? ''));
            if (in_array($action, ['view', 'page_view'], true)) {
                $by[$step]['views']++;
            } elseif (in_array($action, ['click', 'conversion', 'success'], true)) {
                $by[$step]['clicks']++;
            }
        }

        return $by;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $g = is_array($evo) ? ($evo['ghost_ui'] ?? null) : null;

        return is_array($g) ? $g : null;
    }
}
