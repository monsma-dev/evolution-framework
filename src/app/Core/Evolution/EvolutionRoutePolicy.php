<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Sandbox for AI-generated routes: allowed URL prefixes + reserved paths (no /login hijack).
 */
final class EvolutionRoutePolicy
{
    public static function canonicalPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return rtrim($path, '/') ?: '/';
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public static function assertPathAllowed(string $path, Config $config): array
    {
        $path = self::canonicalPath($path);
        if ($path === '/') {
            return ['ok' => false, 'error' => 'Refusing to register AI route on /'];
        }

        $dr = $config->get('evolution.dynamic_routing', []);
        if (!is_array($dr) || !filter_var($dr['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'dynamic_routing disabled'];
        }

        $allowed = $dr['allowed_route_prefixes'] ?? ['/lp/', '/tools/', '/info/', '/admin/tools/'];
        if (!is_array($allowed) || $allowed === []) {
            return ['ok' => false, 'error' => 'allowed_route_prefixes missing'];
        }

        $okPrefix = false;
        foreach ($allowed as $prefix) {
            $pref = rtrim(self::canonicalPath((string) $prefix), '/');
            if ($pref === '' || $pref === '/') {
                continue;
            }
            if ($path === $pref || str_starts_with($path, $pref . '/')) {
                $okPrefix = true;
                break;
            }
        }
        if (!$okPrefix) {
            return ['ok' => false, 'error' => 'Path must start with one of allowed_route_prefixes (sandbox): ' . implode(', ', $allowed)];
        }

        $reservedPrefixes = $dr['reserved_path_prefixes'] ?? ['/login', '/api/', '/webhook/', '/storage/', '/assets/'];
        if (is_array($reservedPrefixes)) {
            foreach ($reservedPrefixes as $r) {
                $rp = trim((string) $r);
                if ($rp === '') {
                    continue;
                }
                if ($rp[0] !== '/') {
                    $rp = '/' . $rp;
                }
                if (str_starts_with($path, $rp)) {
                    return ['ok' => false, 'error' => 'Path conflicts with reserved prefix: ' . $rp];
                }
            }
        }

        return ['ok' => true];
    }

    /**
     * @param list<array{method?: string, path?: string}> $existing
     * @return array{ok: bool, error?: string}
     */
    public static function assertNoCollision(string $method, string $path, array $existing, Config $config): array
    {
        $method = strtoupper(trim($method));
        $path = self::canonicalPath($path);
        foreach ($existing as $row) {
            if (!is_array($row)) {
                continue;
            }
            $m = strtoupper(trim((string) ($row['method'] ?? 'GET')));
            $p = self::canonicalPath((string) ($row['path'] ?? ''));
            if ($m === $method && $p === $path) {
                return ['ok' => false, 'error' => "Route already registered: {$method} {$path}"];
            }
        }

        foreach (self::staticExactBlocked($config) as $block) {
            if ($path === $block) {
                return ['ok' => false, 'error' => "Path blocked (reserved): {$path}"];
            }
        }

        foreach (self::staticPrefixBlocked($config) as $block) {
            if (str_starts_with($path, $block)) {
                return ['ok' => false, 'error' => "Path blocked (reserved prefix {$block}): {$path}"];
            }
        }

        return ['ok' => true];
    }

    /**
     * @return list<string>
     */
    private static function staticExactBlocked(Config $config): array
    {
        $dr = $config->get('evolution.dynamic_routing', []);
        $extra = is_array($dr) ? ($dr['collision_exact_paths'] ?? []) : [];

        $base = ['/login', '/register', '/admin'];
        if (is_array($extra)) {
            foreach ($extra as $e) {
                if (is_string($e) && $e !== '') {
                    $base[] = self::canonicalPath($e);
                }
            }
        }

        return array_values(array_unique($base));
    }

    /**
     * @return list<string>
     */
    private static function staticPrefixBlocked(Config $config): array
    {
        $dr = $config->get('evolution.dynamic_routing', []);
        $extra = is_array($dr) ? ($dr['collision_path_prefixes'] ?? []) : [];

        $base = ['/api/', '/webhook/', '/storage/', '/assets/'];
        if (is_array($extra)) {
            foreach ($extra as $e) {
                if (is_string($e) && $e !== '') {
                    $e = trim($e);
                    if ($e[0] !== '/') {
                        $e = '/' . $e;
                    }
                    $base[] = $e;
                }
            }
        }

        return array_values(array_unique($base));
    }
}
