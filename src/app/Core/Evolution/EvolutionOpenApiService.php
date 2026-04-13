<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Emits a minimal OpenAPI 3 snapshot for AI-managed dynamic routes (storage/evolution/dynamic_routes.json).
 */
final class EvolutionOpenApiService
{
    public const OUTPUT = 'storage/evolution/openapi.json';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, paths?: int, error?: string}
     */
    public function rebuild(): array
    {
        $cfg = $this->container->get('config');
        $o = $cfg->get('evolution.openapi', []);
        if (!is_array($o) || !filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'openapi disabled'];
        }

        $path = BASE_PATH . '/' . EvolutionDynamicRoutingService::ROUTES_FILE;
        $routes = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $j = is_string($raw) ? @json_decode($raw, true) : null;
            if (is_array($j) && isset($j['routes']) && is_array($j['routes'])) {
                $routes = $j['routes'];
            }
        }

        $baseUrl = trim((string) ($o['servers_url'] ?? '/'));
        $doc = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Evolution dynamic routes',
                'version' => gmdate('Y-m-d'),
                'description' => 'Auto-generated from storage/evolution/dynamic_routes.json — virtual AI routes + controllers.',
            ],
            'servers' => [['url' => $baseUrl]],
            'paths' => [],
        ];

        foreach ($routes as $r) {
            if (!is_array($r)) {
                continue;
            }
            $method = strtolower((string) ($r['method'] ?? 'get'));
            $p = (string) ($r['path'] ?? '');
            $controller = (string) ($r['controller'] ?? '');
            $action = (string) ($r['action'] ?? '');
            if ($p === '') {
                continue;
            }
            if (!isset($doc['paths'][$p])) {
                $doc['paths'][$p] = [];
            }
            $doc['paths'][$p][$method] = [
                'summary' => $controller . '::' . $action,
                'operationId' => preg_replace('/[^a-zA-Z0-9_]+/', '_', $method . '_' . $p),
                'responses' => [
                    '200' => ['description' => 'OK'],
                ],
            ];
        }

        $outPath = BASE_PATH . '/' . self::OUTPUT;
        $dir = dirname($outPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($outPath, $json . "\n") === false) {
            return ['ok' => false, 'error' => 'cannot write openapi.json'];
        }
        EvolutionLogger::log('openapi', 'rebuilt', ['paths' => count($doc['paths'])]);

        return ['ok' => true, 'paths' => count($doc['paths'])];
    }
}
