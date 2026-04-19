<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Bundles markdown from docs/ (and optional extra roots) for Architect / consensus prompts.
 */
final class LocalDocScanner
{
    /**
     * @return string Concatenated snippets with path headers (may be empty)
     */
    public static function compile(Config $config): string
    {
        $arch = $config->get('evolution.architect', []);
        if (!is_array($arch)) {
            $arch = [];
        }
        $ld = $arch['local_docs'] ?? [];
        if (!is_array($ld) || !filter_var($ld['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $maxTotal = max(0, min(200000, (int)($ld['max_total_chars'] ?? 32000)));
        if ($maxTotal === 0) {
            return '';
        }
        $maxPerFile = max(500, min(100000, (int)($ld['max_file_chars'] ?? 12000)));

        $base = defined('BASE_PATH') ? (string)BASE_PATH : dirname(__DIR__, 3);
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return '';
        }

        $roots = $ld['scan_roots'] ?? ['docs'];
        if (!is_array($roots)) {
            $roots = ['docs'];
        }
        $roots = array_values(array_filter(array_map(static fn ($r) => trim((string)$r), $roots), static fn ($r) => $r !== ''));

        $extra = $ld['extra_files'] ?? [];
        if (!is_array($extra)) {
            $extra = [];
        }
        $extra = array_values(array_filter(array_map(static fn ($r) => trim((string)$r), $extra), static fn ($r) => $r !== ''));

        $exclude = $ld['exclude_substrings'] ?? ['/vendor/', '/node_modules/', '/.git/'];
        if (!is_array($exclude)) {
            $exclude = ['/vendor/', '/node_modules/', '/.git/'];
        }

        $files = self::collectMarkdownFiles($baseReal, $roots, $extra, $exclude);
        if ($files === []) {
            return '';
        }

        $out = "\n\n=== LOCAL DOCUMENTATION (project markdown; cite paths when relevant) ===\n";
        $used = strlen($out);

        foreach ($files as $rel => $abs) {
            if ($used >= $maxTotal) {
                break;
            }
            $raw = @file_get_contents($abs);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            if (function_exists('mb_substr')) {
                $chunk = mb_substr($raw, 0, $maxPerFile);
            } else {
                $chunk = substr($raw, 0, $maxPerFile);
            }
            $block = "--- {$rel} ---\n" . $chunk . "\n\n";
            $remain = $maxTotal - $used;
            if (strlen($block) > $remain) {
                $block = substr($block, 0, $remain) . "…\n";
            }
            $out .= $block;
            $used += strlen($block);
        }

        return $out;
    }

    /**
     * @param list<string> $roots Relative directories under base
     * @param list<string> $extraFiles Relative file paths under base
     * @param list<string> $excludeSubstrings Skip paths containing any of these
     *
     * @return array<string, string> relative path => absolute path
     */
    private static function collectMarkdownFiles(
        string $baseReal,
        array $roots,
        array $extraFiles,
        array $excludeSubstrings
    ): array {
        $collected = [];

        foreach ($extraFiles as $rel) {
            $full = $baseReal . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            $safe = self::safeFileUnderBase($baseReal, $full);
            if ($safe === null || !is_file($safe) || !str_ends_with(strtolower($safe), '.md')) {
                continue;
            }
            if (self::shouldExclude($rel, $excludeSubstrings)) {
                continue;
            }
            $collected[$rel] = $safe;
        }

        foreach ($roots as $root) {
            $dir = $baseReal . DIRECTORY_SEPARATOR . trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $path = $fileInfo->getPathname();
                if (!str_ends_with(strtolower($path), '.md')) {
                    continue;
                }
                $safe = self::safeFileUnderBase($baseReal, $path);
                if ($safe === null) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($safe, strlen($baseReal) + 1));
                if (self::shouldExclude($rel, $excludeSubstrings)) {
                    continue;
                }
                $collected[$rel] = $safe;
            }
        }

        ksort($collected, SORT_STRING);

        return $collected;
    }

    private static function safeFileUnderBase(string $baseReal, string $candidate): ?string
    {
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $baseNorm = str_replace('\\', '/', $baseReal);
        $fileNorm = str_replace('\\', '/', $real);
        if (!str_starts_with($fileNorm, rtrim($baseNorm, '/') . '/') && $fileNorm !== $baseNorm) {
            return null;
        }

        return $real;
    }

    /**
     * @param list<string> $excludeSubstrings
     */
    private static function shouldExclude(string $relativePath, array $excludeSubstrings): bool
    {
        $p = str_replace('\\', '/', $relativePath);
        foreach ($excludeSubstrings as $sub) {
            $s = (string)$sub;
            if ($s !== '' && str_contains($p, $s)) {
                return true;
            }
        }

        return false;
    }
}
