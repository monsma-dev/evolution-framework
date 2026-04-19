<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Conversion insights from append-only CRO events + evolution log hints.
 */
final class CroInsightService
{
    /**
     * @return array{ok: bool, insights: list<array<string, mixed>>, summary: string, autotune?: array<string, mixed>}
     */
    public function buildReport(Config $config): array
    {
        $path = BASE_PATH . '/data/evolution/cro_events.jsonl';
        $events = [];
        if (is_file($path)) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach (array_slice($lines, -2000) as $line) {
                    $j = json_decode((string)$line, true);
                    if (is_array($j)) {
                        $events[] = $j;
                    }
                }
            }
        }

        $byStep = [];
        foreach ($events as $ev) {
            $step = (string)($ev['step'] ?? 'unknown');
            $action = self::normalizeAction($ev);
            $mobile = filter_var($ev['mobile'] ?? false, FILTER_VALIDATE_BOOL);
            $key = $step . '|' . ($mobile ? 'm' : 'd');
            if (!isset($byStep[$key])) {
                $byStep[$key] = ['views' => 0, 'clicks' => 0, 'drops' => 0];
            }
            if ($action === 'view') {
                $byStep[$key]['views']++;
            } elseif ($action === 'click') {
                $byStep[$key]['clicks']++;
            } elseif ($action === 'drop') {
                $byStep[$key]['drops']++;
            }
        }

        $insights = [];
        foreach ($byStep as $key => $agg) {
            $views = max(1, (int)$agg['views']);
            $drops = (int)$agg['drops'];
            $rate = round(100 * $drops / $views, 1);
            if ($rate >= 15) {
                [$step, $device] = array_pad(explode('|', $key, 2), 2, '');
                $insights[] = [
                    'kind' => 'high_dropoff',
                    'step' => $step,
                    'device' => $device === 'm' ? 'mobile' : 'desktop',
                    'dropoff_rate_pct' => $rate,
                    'hint' => 'Consider larger tap targets and spacing on small screens for this step.',
                ];
            }
        }

        $eventCount = count($events);
        $summary = $insights === []
            ? ($eventCount > 0
                ? "CRO: {$eventCount} events geladen, geen stappen met drop-off >= 15%."
                : 'Not enough CRO data yet. Append JSON lines to storage/evolution/cro_events.jsonl from your funnels.')
            : 'Detected steps with notable drop-off; review hints below.';

        $autotune = $config->get('ai.autotune', []);
        if (!is_array($autotune)) {
            $autotune = [];
        }

        return [
            'ok' => true,
            'insights' => $insights,
            'summary' => $summary,
            'event_count' => $eventCount,
            'autotune' => $autotune,
        ];
    }

    public function isHealthy(): bool
    {
        $path = BASE_PATH . '/data/evolution/cro_events.jsonl';

        return is_file($path) && filesize($path) > 10;
    }

    /**
     * Normalizes event_type / action to view|click|drop.
     *
     * @param array<string, mixed> $ev
     */
    private static function normalizeAction(array $ev): string
    {
        $action = trim((string)($ev['action'] ?? ''));
        if ($action !== '') {
            return match ($action) {
                'view', 'page_view' => 'view',
                'click', 'conversion', 'success', 'chat_start', 'chat_message', 'retry' => 'click',
                'drop', 'abandon', 'error' => 'drop',
                default => 'view',
            };
        }

        $type = trim((string)($ev['event_type'] ?? ''));

        return match ($type) {
            'page_view', 'chat_response' => 'view',
            'click', 'conversion', 'success', 'chat_start', 'chat_message', 'retry' => 'click',
            'abandon', 'error', 'drop' => 'drop',
            default => 'view',
        };
    }
}
