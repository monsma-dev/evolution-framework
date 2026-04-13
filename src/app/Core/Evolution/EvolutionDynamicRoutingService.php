<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Merges AI-proposed routes into storage/evolution/dynamic_routes.json (Router JSON format).
 */
final class EvolutionDynamicRoutingService
{
    public const ROUTES_FILE = 'storage/evolution/dynamic_routes.json';

    /**
     * @param list<array{method?: string, path: string, controller: string, action: string, middleware?: list<string>|string}> $newRoutes
     * @return array{ok: bool, error?: string, routes_count?: int}
     */
    public static function mergeRoutes(Container $container, array $newRoutes): array
    {
        $cfg = $container->get('config');
        $dr = $cfg->get('evolution.dynamic_routing', []);
        if (!is_array($dr) || !filter_var($dr['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'dynamic_routing disabled'];
        }

        $path = BASE_PATH . '/' . self::ROUTES_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $flat = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $j = is_string($raw) ? @json_decode($raw, true) : null;
            if (is_array($j) && isset($j['routes']) && is_array($j['routes'])) {
                $flat = $j['routes'];
            }
        }

        foreach ($newRoutes as $r) {
            if (!is_array($r)) {
                continue;
            }
            $method = strtoupper(trim((string) ($r['method'] ?? 'GET')));
            $p = trim((string) ($r['path'] ?? ''));
            $controller = trim((string) ($r['controller'] ?? ''));
            $action = trim((string) ($r['action'] ?? ''));
            if ($p === '' || $controller === '' || $action === '') {
                continue;
            }

            $pol = EvolutionRoutePolicy::assertPathAllowed($p, $cfg);
            if (!$pol['ok']) {
                return ['ok' => false, 'error' => $pol['error'] ?? 'path policy'];
            }

            $pCanon = EvolutionRoutePolicy::canonicalPath($p);
            $col = EvolutionRoutePolicy::assertNoCollision($method, $pCanon, $flat, $cfg);
            if (!$col['ok']) {
                return ['ok' => false, 'error' => $col['error'] ?? 'collision'];
            }

            $entry = [
                'method' => $method,
                'path' => $pCanon,
                'controller' => $controller,
                'action' => $action,
            ];
            $mw = $r['middleware'] ?? [];
            if (is_string($mw) && $mw !== '') {
                $mw = [$mw];
            }
            if (is_array($mw) && $mw !== []) {
                $entry['middleware'] = $mw;
            }

            $flat[] = $entry;
        }

        $out = ['routes' => $flat];
        if (@file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            return ['ok' => false, 'error' => 'Cannot write dynamic_routes.json'];
        }

        EvolutionLogger::log('dynamic_routing', 'routes_merged', ['count' => count($flat)]);

        return ['ok' => true, 'routes_count' => count($flat)];
    }
}
