<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Recursive symbol / string scout for impact analysis (Squire — "Runner").
 */
final class EvolutionContextScout
{
    /**
     * @param list<string> $relativeRoots
     *
     * @return array{matches: list<array{file: string, line?: int}>, ms: float, engine: string}
     */
    public static function findSymbol(Config $config, string $needle, array $relativeRoots = []): array
    {
        $t0 = microtime(true);
        $base = defined('BASE_PATH') ? BASE_PATH : '';
        if ($base === '' || $needle === '') {
            return ['matches' => [], 'ms' => 0.0, 'engine' => 'noop'];
        }

        $roots = $relativeRoots !== [] ? $relativeRoots : ['src/app', 'src/config', 'docs'];
        $rg = self::whichRg();
        if ($rg !== null) {
            $matches = self::rgSearch($rg, $base, $roots, $needle);

            return [
                'matches' => $matches,
                'ms' => round((microtime(true) - $t0) * 1000, 3),
                'engine' => 'ripgrep',
            ];
        }

        $matches = self::phpFallbackScan($base, $roots, $needle);

        return [
            'matches' => $matches,
            'ms' => round((microtime(true) - $t0) * 1000, 3),
            'engine' => 'php_fallback',
        ];
    }

    private static function whichRg(): ?string
    {
        $candidates = ['rg', 'rg.exe'];
        foreach ($candidates as $b) {
            $out = [];
            $code = 0;
            if (PHP_OS_FAMILY === 'Windows') {
                @exec('where ' . escapeshellarg($b) . ' 2>nul', $out, $code);
            } else {
                @exec('command -v ' . escapeshellarg($b) . ' 2>/dev/null', $out, $code);
            }
            if ($code === 0 && isset($out[0]) && is_string($out[0]) && $out[0] !== '') {
                return trim($out[0]);
            }
        }

        return null;
    }

    /**
     * @param list<string> $roots
     *
     * @return list<array{file: string, line?: int}>
     */
    private static function rgSearch(string $rgBinary, string $base, array $roots, string $needle): array
    {
        $matches = [];
        foreach ($roots as $rel) {
            $rel = trim(str_replace('\\', '/', $rel), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $path = $base . '/' . $rel;
            if (!is_dir($path)) {
                continue;
            }
            $cmd = escapeshellarg($rgBinary)
                . ' --line-number --no-heading --color never '
                . '--glob !vendor --glob !node_modules --glob !.git '
                . escapeshellarg($needle) . ' ' . escapeshellarg($path) . ' 2>&1';
            $out = [];
            @exec($cmd, $out);
            foreach ($out as $line) {
                if (!is_string($line) || $line === '') {
                    continue;
                }
                if (preg_match('/^(.+):(\d+):/', $line, $m)) {
                    $matches[] = ['file' => $m[1], 'line' => (int) $m[2]];
                } else {
                    $matches[] = ['file' => $line];
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $roots
     *
     * @return list<array{file: string, line?: int}>
     */
    private static function phpFallbackScan(string $base, array $roots, string $needle): array
    {
        $out = [];
        foreach ($roots as $rel) {
            $rel = trim(str_replace('\\', '/', $rel), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $dir = $base . '/' . $rel;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $p = $file->getPathname();
                if (str_contains($p, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['php', 'twig', 'json', 'md', 'yml', 'yaml', 'ini', 'css', 'js'], true)) {
                    continue;
                }
                $c = @file_get_contents($p);
                if (!is_string($c) || !str_contains($c, $needle)) {
                    continue;
                }
                $line = 1;
                foreach (explode("\n", $c) as $i => $ln) {
                    if (str_contains($ln, $needle)) {
                        $line = $i + 1;
                        break;
                    }
                }
                $out[] = ['file' => $p, 'line' => $line];
            }
        }

        return $out;
    }
}
