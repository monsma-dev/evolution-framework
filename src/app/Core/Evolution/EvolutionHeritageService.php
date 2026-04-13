<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Digital Heritage: visual timeline + Hall of Fame voor "time machine" dashboard.
 */
final class EvolutionHeritageService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, events: list<array<string, mixed>>, visual: list<array<string, mixed>>, hall: list<array<string, mixed>>, genesis?: array<string, mixed>}
     */
    public function collect(Config $config, int $limit = 40, bool $includeGenesis = false): array
    {
        $h = $config->get('evolution', []);
        $hh = is_array($h) ? ($h['heritage'] ?? []) : [];
        if (!is_array($hh) || !filter_var($hh['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'events' => [], 'visual' => [], 'hall' => []];
        }

        $limit = max(5, min(100, $limit));

        $hof = new EvolutionHallOfFameService($this->container);
        $events = $hof->getRecentTimeline($limit);
        $visual = VisualTimelineService::readRecentEntries($limit);

        $merged = [];
        foreach ($events as $e) {
            $merged[] = ['source' => 'hall_of_fame', 'data' => $e];
        }
        foreach ($visual as $v) {
            $merged[] = ['source' => 'visual', 'data' => $v];
        }
        usort($merged, static function (array $a, array $b): int {
            $ta = strtotime((string) ($a['data']['ts'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['data']['ts'] ?? '')) ?: 0;

            return $tb <=> $ta;
        });

        $out = [
            'ok' => true,
            'events' => array_slice($merged, 0, $limit),
            'visual' => $visual,
            'hall' => $events,
        ];
        if ($includeGenesis) {
            $g = EvolutionGenesisService::readCached();
            if (($g['ok'] ?? false) && isset($g['data'])) {
                $out['genesis'] = [
                    'generated_at' => $g['data']['generated_at'] ?? null,
                    'summary' => $g['data']['summary'] ?? '',
                    'git' => $g['data']['git'] ?? [],
                    'milestones' => $g['data']['milestones'] ?? [],
                ];
            } else {
                $out['genesis'] = ['stale' => true, 'hint' => 'Run php ai_bridge.php evolution:genesis-index'];
            }
        }

        return $out;
    }
}
