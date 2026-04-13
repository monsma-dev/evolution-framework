<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Merges AI-proposed evolution_assets JSON into storage files with validation.
 */
final class EvolutionAssetConfigMergeService
{
    private const TWIG_FUNCTIONS_PATH = 'storage/evolution/twig_functions.json';
    private const PAGE_LIBRARIES_PATH = 'storage/evolution/page_libraries.json';

    /**
     * @param array<string, mixed> $patch partial { filters?, functions? }
     * @return array{ok: bool, error?: string}
     */
    public static function mergeTwigFunctions(Config $config, array $patch): array
    {
        if (!defined('BASE_PATH')) {
            return ['ok' => false, 'error' => 'BASE_PATH niet gedefinieerd'];
        }
        $base = self::readTwigFunctions();
        $incomingFilters = $patch['filters'] ?? null;
        if (is_array($incomingFilters)) {
            $base['filters'] = is_array($base['filters'] ?? null) ? $base['filters'] : [];
            foreach ($incomingFilters as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                $one = EvolutionTwigBridge::validateSpec(['filters' => [$k => $v], 'functions' => []]);
                if (!$one['ok']) {
                    continue;
                }
                $base['filters'][$k] = $v;
            }
        }
        $incomingFunctions = $patch['functions'] ?? null;
        if (is_array($incomingFunctions)) {
            $base['functions'] = is_array($base['functions'] ?? null) ? $base['functions'] : [];
            foreach ($incomingFunctions as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                $one = EvolutionTwigBridge::validateSpec(['filters' => [], 'functions' => [$k => $v]]);
                if (!$one['ok']) {
                    continue;
                }
                $base['functions'][$k] = $v;
            }
        }
        $base['version'] = max(1, (int) ($base['version'] ?? 1));

        $val = EvolutionTwigBridge::validateSpec($base);
        if (!$val['ok']) {
            return ['ok' => false, 'error' => $val['error'] ?? 'twig_functions validatie mislukt'];
        }

        $path = BASE_PATH . '/' . self::TWIG_FUNCTIONS_PATH;
        if (!self::writeJsonFile($path, $base)) {
            return ['ok' => false, 'error' => 'Schrijven twig_functions.json mislukt'];
        }
        EvolutionTwigBridge::bumpSpecCache();

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $patch { rules: list<array> }
     * @return array{ok: bool, error?: string}
     */
    public static function mergePageLibraries(Config $config, array $patch): array
    {
        if (!defined('BASE_PATH')) {
            return ['ok' => false, 'error' => 'BASE_PATH niet gedefinieerd'];
        }
        $base = self::readPageLibraries();
        $rules = $patch['rules'] ?? null;
        if ($rules === null) {
            return ['ok' => false, 'error' => 'page_libraries.rules ontbreekt'];
        }
        if (!is_array($rules)) {
            return ['ok' => false, 'error' => 'page_libraries.rules moet een array zijn'];
        }
        if ($rules === []) {
            return ['ok' => true];
        }

        $byPrefix = [];
        foreach ($base['rules'] ?? [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $p = trim((string) ($r['path_prefix'] ?? ''));
            if ($p !== '') {
                $byPrefix[$p] = $r;
            }
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $prefix = trim((string) ($rule['path_prefix'] ?? ''));
            if ($prefix === '') {
                continue;
            }
            $v = self::validateLibraryRule($rule, $config);
            if (!$v['ok']) {
                return ['ok' => false, 'error' => $v['error'] ?? 'page library rule ongeldig'];
            }
            $byPrefix[$prefix] = $rule;
        }

        $merged = [
            'version' => max(1, (int) ($base['version'] ?? 1)),
            'rules' => array_values($byPrefix),
        ];

        $path = BASE_PATH . '/' . self::PAGE_LIBRARIES_PATH;
        if (!self::writeJsonFile($path, $merged)) {
            return ['ok' => false, 'error' => 'Schrijven page_libraries.json mislukt'];
        }

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readTwigFunctions(): array
    {
        $path = BASE_PATH . '/' . self::TWIG_FUNCTIONS_PATH;
        if (!is_file($path)) {
            return ['version' => 1, 'filters' => [], 'functions' => []];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return ['version' => 1, 'filters' => [], 'functions' => []];
        }
        try {
            $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['version' => 1, 'filters' => [], 'functions' => []];
        }

        return is_array($d) ? $d : ['version' => 1, 'filters' => [], 'functions' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readPageLibraries(): array
    {
        $path = BASE_PATH . '/' . self::PAGE_LIBRARIES_PATH;
        if (!is_file($path)) {
            return ['version' => 1, 'rules' => []];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return ['version' => 1, 'rules' => []];
        }
        try {
            $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['version' => 1, 'rules' => []];
        }

        return is_array($d) ? $d : ['version' => 1, 'rules' => []];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeJsonFile(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }

        return @rename($tmp, $path);
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{ok: bool, error?: string}
     */
    private static function validateLibraryRule(array $rule, Config $config): array
    {
        foreach ($rule['styles'] ?? [] as $url) {
            if (!is_string($url) || !EvolutionCdnPolicy::isAllowedUrl($url, $config)) {
                return ['ok' => false, 'error' => 'Style URL niet toegestaan: ' . (string) $url];
            }
        }
        foreach ($rule['scripts'] ?? [] as $item) {
            $url = '';
            if (is_string($item)) {
                $url = $item;
            } elseif (is_array($item)) {
                $url = (string) ($item['url'] ?? '');
            }
            if ($url === '' || !EvolutionCdnPolicy::isAllowedUrl($url, $config)) {
                return ['ok' => false, 'error' => 'Script URL niet toegestaan: ' . $url];
            }
        }

        return ['ok' => true];
    }
}
