<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * TraceRoute, ShadowDiff, Latency, Linter helpers — thin wrappers for the Architect "Squire".
 */
final class EvolutionSquireToolkit
{
    /**
     * @return array{matches: list<array{file: string, line?: int}>}
     */
    public static function traceRoute(Config $config, string $symbol): array
    {
        $r = EvolutionContextScout::findSymbol($config, $symbol);

        return ['matches' => $r['matches']];
    }

    /**
     * Copy $relativeRoots into a mirror dir under storage for dry-run diff (does not touch src/).
     *
     * @param list<string> $relativeRoots
     *
     * @return array{ok: bool, mirror?: string, error?: string}
     */
    public static function createMirrorDirectory(Config $config, array $relativeRoots, string $label = 'shadow'): array
    {
        $base = defined('BASE_PATH') ? BASE_PATH : '';
        if ($base === '') {
            return ['ok' => false, 'error' => 'BASE_PATH'];
        }
        $safe = preg_replace('/[^a-z0-9_-]+/i', '', $label) ?: 'shadow';
        $mirror = $base . '/storage/evolution/mirror_' . $safe . '_' . gmdate('Ymd-His');
        if (!@mkdir($mirror, 0755, true) && !is_dir($mirror)) {
            return ['ok' => false, 'error' => 'mkdir mirror'];
        }
        foreach ($relativeRoots as $rel) {
            $rel = trim(str_replace('\\', '/', $rel), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $src = $base . '/' . $rel;
            $dst = $mirror . '/' . $rel;
            if (is_file($src)) {
                $d = dirname($dst);
                if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) {
                    return ['ok' => false, 'error' => 'mkdir'];
                }
                if (!@copy($src, $dst)) {
                    return ['ok' => false, 'error' => 'copy ' . $rel];
                }
            } elseif (is_dir($src)) {
                self::mirrorTree($src, $dst);
            }
        }

        return ['ok' => true, 'mirror' => $mirror];
    }

    /**
     * @return array{ok: bool, ms: float}
     */
    public static function latencyMonitor(callable $fn): array
    {
        $t0 = microtime(true);
        $fn();
        $ms = (microtime(true) - $t0) * 1000;

        return ['ok' => true, 'ms' => round($ms, 4)];
    }

    /**
     * @return array{ok: bool, output?: string}
     */
    public static function linterDronePhp(string $pathToPhpFile): array
    {
        if (!is_file($pathToPhpFile)) {
            return ['ok' => false];
        }
        $out = [];
        $code = 0;
        $bin = PHP_OS_FAMILY === 'Windows' ? 'php' : PHP_BINARY;
        @exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($pathToPhpFile) . ' 2>&1', $out, $code);

        return ['ok' => $code === 0, 'output' => implode("\n", $out)];
    }

    private static function mirrorTree(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        /** @var \SplFileInfo $f */
        foreach ($it as $f) {
            $sub = $src . DIRECTORY_SEPARATOR;
            $rel = substr($f->getPathname(), strlen($sub));
            $target = $dst . DIRECTORY_SEPARATOR . $rel;
            if ($f->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                @copy($f->getPathname(), $target);
            }
        }
    }
}
