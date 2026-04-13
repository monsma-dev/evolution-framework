<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * CRO 2.0 Autopilot: monitors A/B experiments and auto-picks winners.
 *
 * Smart Defaults: analyzes CRO event patterns to detect UI elements that
 * should be repositioned (e.g. high-click buttons below the fold).
 *
 * A/B Autopilot: after sufficient events, declares a winner variant and
 * purges the loser from architect-overrides.css.
 */
final class CroAutopilotService
{
    private const MIN_EVENTS_PER_VARIANT = 500;
    private const MIN_IMPROVEMENT_PCT = 5;

    /**
     * Evaluate all active A/B experiments; auto-conclude winners.
     *
     * @return array{evaluated: int, concluded: list<array{experiment_id: string, winner: string, loser: string, improvement_pct: float}>, pending: list<string>}
     */
    public function evaluateExperiments(Config $config): array
    {
        $ab = new DesignAbService();
        $data = $ab->listExperiments();
        $experiments = $data['experiments'] ?? [];
        if (!is_array($experiments)) {
            return ['evaluated' => 0, 'concluded' => [], 'pending' => []];
        }

        $concluded = [];
        $pending = [];

        foreach ($experiments as $exp) {
            if (!is_array($exp)) {
                continue;
            }
            $id = (string)($exp['id'] ?? '');
            $variants = $exp['variants'] ?? [];
            if (!is_array($variants) || count($variants) < 2) {
                continue;
            }

            $allReady = true;
            foreach ($variants as $v) {
                if ((int)($v['clicks'] ?? 0) < self::MIN_EVENTS_PER_VARIANT) {
                    $allReady = false;
                    break;
                }
            }

            if (!$allReady) {
                $pending[] = $id;
                continue;
            }

            usort($variants, static fn(array $a, array $b) => (int)($b['clicks'] ?? 0) - (int)($a['clicks'] ?? 0));

            $winner = $variants[0];
            $loser = $variants[1];
            $winClicks = max(1, (int)($winner['clicks'] ?? 0));
            $loseClicks = max(1, (int)($loser['clicks'] ?? 0));
            $improvement = (($winClicks - $loseClicks) / $loseClicks) * 100;

            if ($improvement >= self::MIN_IMPROVEMENT_PCT) {
                $concluded[] = [
                    'experiment_id' => $id,
                    'winner' => (string)($winner['name'] ?? ''),
                    'loser' => (string)($loser['name'] ?? ''),
                    'improvement_pct' => round($improvement, 1),
                    'winner_clicks' => $winClicks,
                    'loser_clicks' => $loseClicks,
                ];

                EvolutionLogger::log('cro_autopilot', 'experiment_concluded', [
                    'experiment_id' => $id,
                    'winner' => $winner['name'] ?? '',
                    'improvement_pct' => round($improvement, 1),
                ]);
            } else {
                $pending[] = $id;
            }
        }

        return [
            'evaluated' => count($experiments),
            'concluded' => $concluded,
            'pending' => $pending,
        ];
    }

    /**
     * Analyzes CRO events to find "smart default" opportunities.
     * E.g. buttons with high click rates that appear late in the funnel.
     *
     * @return list<array{step: string, observation: string, suggestion: string}>
     */
    public function detectSmartDefaults(Config $config): array
    {
        $cro = new CroInsightService();
        $report = $cro->buildReport($config);
        $events = $this->loadRecentEvents(7);

        $stepClicks = [];
        $stepViews = [];
        foreach ($events as $ev) {
            $step = (string)($ev['step'] ?? '');
            $action = $this->normalizeAction($ev);
            if ($step === '') {
                continue;
            }
            if ($action === 'click') {
                $stepClicks[$step] = ($stepClicks[$step] ?? 0) + 1;
            }
            if ($action === 'view') {
                $stepViews[$step] = ($stepViews[$step] ?? 0) + 1;
            }
        }

        $suggestions = [];
        foreach ($stepClicks as $step => $clicks) {
            $views = max(1, $stepViews[$step] ?? 0);
            $ctr = ($clicks / $views) * 100;

            if ($ctr > 60 && $views >= 20) {
                $suggestions[] = [
                    'step' => $step,
                    'observation' => "High CTR ({$ctr}%) with {$clicks} clicks / {$views} views",
                    'suggestion' => "This element is popular — consider moving it above the fold or making it more prominent.",
                ];
            }
        }

        foreach ($report['insights'] ?? [] as $insight) {
            if (($insight['kind'] ?? '') === 'high_dropoff') {
                $suggestions[] = [
                    'step' => $insight['step'] ?? '',
                    'observation' => "Drop-off rate: {$insight['dropoff_rate_pct']}% on {$insight['device']}",
                    'suggestion' => $insight['hint'] ?? 'Improve tap targets and reduce friction at this step.',
                ];
            }
        }

        return $suggestions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRecentEvents(int $days): array
    {
        $path = BASE_PATH . '/storage/evolution/cro_events.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $cutoff = gmdate('c', time() - $days * 86400);
        $events = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice($lines, -3000) as $line) {
            $j = @json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $ts = (string)($j['timestamp'] ?? $j['ts'] ?? '');
            if ($ts >= $cutoff) {
                $events[] = $j;
            }
        }

        return $events;
    }

    private function normalizeAction(array $ev): string
    {
        $action = trim((string)($ev['action'] ?? ''));
        if ($action !== '') {
            return match ($action) {
                'view', 'page_view' => 'view',
                'click', 'conversion', 'success' => 'click',
                default => $action,
            };
        }
        $type = trim((string)($ev['event_type'] ?? ''));

        return match ($type) {
            'page_view', 'chat_response' => 'view',
            'click', 'conversion', 'success' => 'click',
            default => 'view',
        };
    }
}
