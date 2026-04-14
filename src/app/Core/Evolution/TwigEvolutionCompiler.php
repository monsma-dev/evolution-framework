<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Heuristic static-segment extraction for Twig: caches stable HTML fragments in APCu (JIT-friendly).
 * Full Twig parse/runtime is avoided here — use overnight Node/PHP job for render when needed.
 */
final class TwigEvolutionCompiler
{
    private const APC_PREFIX = 'twig_evolution:';

    /**
     * Split template source on dynamic Twig markers; cache segments without {{ or {% as pure strings.
     *
     * @return array{segments_cached: int, keys: list<string>}
     */
    public function cacheStaticSegments(Config $config, string $relativeTwigPath): array
    {
        $tc = $config->get('evolution.twig_jit_compiler', []);
        if (!is_array($tc) || !filter_var($tc['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['segments_cached' => 0, 'keys' => []];
        }

        $rel = ltrim(str_replace('\\', '/', $relativeTwigPath), '/');
        $full = BASE_PATH . '/src/resources/views/' . $rel;
        if (!is_file($full)) {
            $full = BASE_PATH . '/' . $rel;
        }
        if (!is_file($full)) {
            return ['segments_cached' => 0, 'keys' => []];
        }

        $src = (string) file_get_contents($full);
        $parts = preg_split('/(\{\{|\{%)/', $src, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return ['segments_cached' => 0, 'keys' => []];
        }

        $ttl = max(60, min(86400 * 7, (int) ($tc['apcu_ttl_seconds'] ?? 3600)));
        $keys = [];
        $n = 0;
        $buf = '';
        foreach ($parts as $i => $chunk) {
            if ($chunk === '{{' || $chunk === '{%') {
                if ($buf !== '' && !str_contains($buf, '{{') && !str_contains($buf, '{%')) {
                    $hash = hash('sha256', $rel . '|' . $buf);
                    $key = self::APC_PREFIX . $hash;
                    if (function_exists('apcu_store')) {
                        apcu_store($key, $buf, $ttl);
                        $keys[] = $key;
                        $n++;
                    }
                }
                $buf = '';
                continue;
            }
            $buf .= $chunk;
        }

        $dir = BASE_PATH . '/storage/evolution/twig_jit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $rel) . '.meta.json',
            json_encode(['segments_cached' => $n, 'keys' => $keys, 'ts' => gmdate('c')], JSON_PRETTY_PRINT) . "\n"
        );

        EvolutionLogger::log('twig_jit', 'segments', ['template' => $rel, 'segments' => $n]);

        return ['segments_cached' => $n, 'keys' => $keys];
    }

    public static function getSegment(string $apcuKey): ?string
    {
        if (!function_exists('apcu_fetch')) {
            return null;
        }

        $v = apcu_fetch($apcuKey, $ok);

        return $ok && is_string($v) ? $v : null;
    }
}
