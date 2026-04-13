<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Agentic UI (dashboard on demand): lichte telemetrie welke admin-routes vaak bezocht worden;
 * output is bedoeld als input voor Architect/Ghost ("maak een widget voor …").
 */
final class AgenticDashboardService
{
    public const HITS_LOG = 'storage/evolution/agentic_ui/admin_route_hits.jsonl';

    public static function isEnabled(Config $config): bool
    {
        $au = $config->get('evolution.agentic_ui', []);

        return is_array($au) && filter_var($au['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    public static function recordPath(Container $container, string $path): void
    {
        if (!self::isEnabled($container->get('config'))) {
            return;
        }
        $p = trim($path);
        if ($p === '' || !str_starts_with($p, '/admin')) {
            return;
        }
        $dir = dirname(BASE_PATH . '/' . self::HITS_LOG);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = json_encode([
            'ts' => gmdate('c'),
            'path' => mb_substr($p, 0, 512),
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(BASE_PATH . '/' . self::HITS_LOG, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array{path: string, hits: int}>
     */
    public static function topPaths(Container $container, int $limit = 12): array
    {
        $path = BASE_PATH . '/' . self::HITS_LOG;
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $counts = [];
        foreach ($lines as $ln) {
            $j = json_decode((string) $ln, true);
            if (!is_array($j) || !isset($j['path'])) {
                continue;
            }
            $pp = (string) $j['path'];
            $counts[$pp] = ($counts[$pp] ?? 0) + 1;
        }
        arsort($counts);
        $out = [];
        foreach ($counts as $p => $n) {
            $out[] = ['path' => $p, 'hits' => $n];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Korte tekst voor Ghost/Architect prompts.
     */
    public static function promptSection(Container $container): string
    {
        if (!self::isEnabled($container->get('config'))) {
            return '';
        }
        $top = self::topPaths($container, 8);
        if ($top === []) {
            return '';
        }
        $lines = ['AGENTIC_UI_SIGNALS (admin route frequency — design a focused widget if useful):'];
        foreach ($top as $row) {
            $lines[] = '  - ' . $row['path'] . ' → ' . $row['hits'] . ' hits';
        }
        $lines[] = 'If a route dominates (logs, queries), propose a small admin dashboard card (Twig + CSS) as ui_autofix or medium.';

        return "\n" . implode("\n", $lines) . "\n";
    }
}
