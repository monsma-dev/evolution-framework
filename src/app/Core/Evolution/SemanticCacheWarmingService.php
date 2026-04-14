<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Predictive cache warm: uses CRO / product signals to pre-touch hot keys (Redis/APCu when available).
 */
final class SemanticCacheWarmingService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, warmed: int, hints: list<string>}
     */
    public function warmTrending(Config $config): array
    {
        $sc = $config->get('evolution.semantic_cache_warming', []);
        if (!is_array($sc) || !filter_var($sc['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'warmed' => 0, 'hints' => ['semantic_cache_warming disabled']];
        }

        $path = BASE_PATH . '/storage/evolution/cro_events.jsonl';
        if (!is_file($path)) {
            return ['ok' => true, 'warmed' => 0, 'hints' => ['no cro_events.jsonl']];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $counts = [];
        foreach (array_slice($lines, -5000) as $line) {
            $j = @json_decode((string) $line, true);
            if (!is_array($j)) {
                continue;
            }
            foreach (['listing_id', 'product_id', 'entity_id'] as $k) {
                if (!empty($j[$k])) {
                    $id = (string) $j[$k];
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }
        arsort($counts);
        $top = array_slice(array_keys($counts), 0, (int) ($sc['max_entities'] ?? 10));

        $warmed = 0;
        $hints = [];
        try {
            $cache = $this->container->get('cache');
        } catch (\Throwable) {
            $cache = null;
        }

        foreach ($top as $id) {
            $key = 'warm:trending:entity:' . $id;
            if (is_object($cache) && method_exists($cache, 'set')) {
                try {
                    $cache->set($key, ['warmed_at' => gmdate('c'), 'id' => $id], (int) ($sc['ttl_seconds'] ?? 300));
                    $warmed++;
                } catch (\Throwable) {
                }
            } elseif (function_exists('apcu_store')) {
                apcu_store('semantic_warm:' . $key, 1, (int) ($sc['ttl_seconds'] ?? 300));
                $warmed++;
            }
            $hints[] = 'entity ' . $id;
        }

        EvolutionLogger::log('cache_warm', 'trending', ['warmed' => $warmed]);

        return ['ok' => true, 'warmed' => $warmed, 'hints' => $hints];
    }
}
