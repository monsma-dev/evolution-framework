<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Extra AI ignore patterns (beyond immune_paths), e.g. legacy integrations that always roll back.
 * File: storage/evolution/.evolution_ignore — one substring per line, # comments, empty lines skipped.
 */
final class EvolutionIgnoreRegistry
{
    private const FILE = 'storage/evolution/.evolution_ignore';

    /** @var list<string>|null */
    private static ?array $cache = null;

    /**
     * True if $target (FQCN or path) should be skipped by Ghost/auto-apply heuristics.
     */
    public static function matches(string $target, ?Config $config = null): bool
    {
        $target = strtolower(str_replace('\\', '/', trim($target)));
        if ($target === '') {
            return false;
        }
        $evo = $config?->get('evolution.evolution_ignore', []);
        if (is_array($evo) && !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        foreach (self::patterns() as $p) {
            if ($p !== '' && str_contains($target, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function patterns(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        if (!defined('BASE_PATH')) {
            self::$cache = [];

            return self::$cache;
        }
        $path = BASE_PATH . '/' . self::FILE;
        if (!is_file($path)) {
            self::$cache = [];

            return self::$cache;
        }
        $raw = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($raw)) {
            self::$cache = [];

            return self::$cache;
        }
        $out = [];
        foreach ($raw as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $out[] = strtolower(str_replace('\\', '/', $line));
        }
        self::$cache = $out;

        return self::$cache;
    }

    public static function bumpCache(): void
    {
        self::$cache = null;
    }

    /**
     * Append a pattern (idempotent — skips if already present).
     */
    public static function appendPattern(string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '' || str_contains($pattern, "\n")) {
            return false;
        }
        if (!defined('BASE_PATH')) {
            return false;
        }
        $path = BASE_PATH . '/' . self::FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $existing = self::patterns();
        $lower = strtolower(str_replace('\\', '/', $pattern));
        foreach ($existing as $e) {
            if ($e === $lower) {
                return true;
            }
        }
        $line = '# auto: too risky after repeated rollbacks ' . gmdate('c') . "\n" . $pattern . "\n";
        $ok = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false;
        if ($ok) {
            self::bumpCache();
            EvolutionLogger::log('evolution_ignore', 'append', ['pattern' => $pattern]);
        }

        return $ok;
    }
}
