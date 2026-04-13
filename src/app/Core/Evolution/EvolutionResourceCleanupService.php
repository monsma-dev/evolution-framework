<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Prunes oversized/aged log files and JSON cache blobs; optional Redis memory hint (no FLUSH in prod by default).
 */
final class EvolutionResourceCleanupService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, pruned_files?: int, freed_bytes?: int, redis_hint?: string, error?: string}
     */
    public function run(): array
    {
        $cfg = $this->container->get('config');
        $rc = $cfg->get('evolution.resource_cleanup', []);
        if (!is_array($rc) || !filter_var($rc['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'resource_cleanup disabled'];
        }
        $maxDays = max(1, (int) ($rc['log_max_age_days'] ?? 30));
        $maxBytesPerFile = max(1_000_000, (int) ($rc['log_max_bytes_per_file'] ?? 50_000_000));
        $cutoff = time() - $maxDays * 86400;

        $dirs = $rc['log_roots'] ?? ['storage/logs', 'public/storage/logs'];
        if (!is_array($dirs)) {
            $dirs = ['storage/logs'];
        }

        $pruned = 0;
        $freed = 0;
        foreach ($dirs as $rel) {
            $rel = trim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $abs = BASE_PATH . '/' . $rel;
            if (!is_dir($abs)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $path = $f->getPathname();
                $mtime = @filemtime($path) ?: 0;
                $sz = filesize($path) ?: 0;
                $old = $mtime < $cutoff;
                $fat = $sz > $maxBytesPerFile;
                if (!$old && !$fat) {
                    continue;
                }
                if (@unlink($path)) {
                    $pruned++;
                    $freed += $sz;
                }
            }
        }

        $cacheAll = $cfg->get('cache', []);
        $jsonCache = is_array($cacheAll) ? ($cacheAll['json']['path'] ?? null) : null;
        if (is_string($jsonCache) && $jsonCache !== '' && !str_starts_with($jsonCache, '/') && !preg_match('/^[A-Za-z]:/', $jsonCache)) {
            $jsonAbs = BASE_PATH . '/' . ltrim($jsonCache, '/');
            if (is_dir($jsonAbs) && filter_var($rc['prune_json_cache'] ?? true, FILTER_VALIDATE_BOOL)) {
                $this->pruneJsonCacheDir($jsonAbs, $cutoff, $pruned, $freed);
            }
        }

        $redisHint = '';
        if (filter_var($rc['redis_memory_hint'] ?? true, FILTER_VALIDATE_BOOL)) {
            $redisHint = $this->redisMemoryHint($cfg);
        }

        EvolutionLogger::log('resource_cleanup', 'run', ['pruned' => $pruned, 'freed' => $freed]);

        return ['ok' => true, 'pruned_files' => $pruned, 'freed_bytes' => $freed, 'redis_hint' => $redisHint];
    }

    /**
     * @param-out int $pruned
     * @param-out int $freed
     */
    private function pruneJsonCacheDir(string $dir, int $cutoff, int &$pruned, int &$freed): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'json') {
                continue;
            }
            $path = $f->getPathname();
            if ((@filemtime($path) ?: 0) >= $cutoff) {
                continue;
            }
            $sz = filesize($path) ?: 0;
            if (@unlink($path)) {
                $pruned++;
                $freed += $sz;
            }
        }
    }

    private function redisMemoryHint(Config $cfg): string
    {
        $cache = $cfg->get('cache', []);
        $redis = is_array($cache) ? ($cache['redis'] ?? []) : [];
        if (!is_array($redis)) {
            return '';
        }
        $host = (string) ($redis['host'] ?? '127.0.0.1');
        $port = (int) ($redis['port'] ?? 6379);
        if (!function_exists('fsockopen')) {
            return 'Redis: fsockopen unavailable — skip memory probe.';
        }
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if (!is_resource($fp)) {
            return 'Redis: not reachable for INFO (' . $errstr . ').';
        }
        fwrite($fp, "INFO memory\r\nQUIT\r\n");
        $resp = '';
        while (!feof($fp)) {
            $resp .= (string) fread($fp, 4096);
        }
        fclose($fp);
        if ($resp === '') {
            return 'Redis: empty INFO response.';
        }
        if (preg_match('/used_memory_human:([^\r\n]+)/', $resp, $m)) {
            return 'Redis memory (used_memory_human): ' . trim($m[1]) . ' — tune TTL/eviction if PHP memory pressure.';
        }

        return 'Redis: INFO memory not parsed.';
    }
}
