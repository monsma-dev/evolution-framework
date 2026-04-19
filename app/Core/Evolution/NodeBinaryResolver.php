<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Resolves the node binary for exec() from PHP (FrankenPHP / php-fpm often have an empty PATH).
 * Bootstrap loads `.env` via putenv — NODE_BINARY there is visible to getenv().
 * Order: evolution.screenshot.node_binary → NODE_BINARY → common paths → PATH dirs → sh command -v → "node".
 */
final class NodeBinaryResolver
{
    /** @var list<string> */
    private const FALLBACK_PATHS = [
        '/usr/local/bin/node',
        '/usr/bin/node',
        '/bin/node',
        '/opt/nodejs/bin/node',
    ];

    /**
     * Single shell token for `exec()` / `proc_open()`, e.g. '/usr/bin/node' quoted.
     */
    public static function resolvedShellArg(Config $config): string
    {
        $resolved = self::resolvePath($config);

        return escapeshellarg($resolved);
    }

    public static function resolvePath(Config $config): string
    {
        $explicit = self::fromConfig($config);
        if ($explicit !== null) {
            return $explicit;
        }
        $env = getenv('NODE_BINARY');
        if (is_string($env)) {
            $env = trim($env);
            if ($env !== '' && self::isRunnable($env)) {
                return $env;
            }
        }
        foreach (self::FALLBACK_PATHS as $p) {
            if (self::isRunnable($p)) {
                return $p;
            }
        }
        $fromPath = self::findInPathEnv();
        if ($fromPath !== null) {
            return $fromPath;
        }
        $fromShell = self::whichViaSh();
        if ($fromShell !== null) {
            return $fromShell;
        }

        return 'node';
    }

    private static function fromConfig(Config $config): ?string
    {
        $evo = $config->get('evolution', []);
        if (!is_array($evo)) {
            return null;
        }
        $cap = $evo['screenshot'] ?? [];
        if (!is_array($cap)) {
            return null;
        }
        $bin = trim((string)($cap['node_binary'] ?? ''));
        if ($bin === '') {
            return null;
        }
        if (self::isRunnable($bin)) {
            return $bin;
        }

        return null;
    }

    private static function isRunnable(string $path): bool
    {
        return $path !== '' && @is_file($path) && @is_executable($path);
    }

    private static function findInPathEnv(): ?string
    {
        $pathEnv = getenv('PATH');
        $dirs = [];
        if (is_string($pathEnv) && $pathEnv !== '') {
            $sep = \DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
            foreach (explode($sep, $pathEnv) as $d) {
                $d = trim($d);
                if ($d !== '') {
                    $dirs[] = $d;
                }
            }
        }
        $dirs = array_merge($dirs, ['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin']);
        foreach ($dirs as $dir) {
            $candidate = rtrim(str_replace('\\', '/', $dir), '/') . '/node';
            if (self::isRunnable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Last resort: POSIX sh with an expanded PATH (php-fpm often omits /usr/bin).
     */
    private static function whichViaSh(): ?string
    {
        $pathList = getenv('PATH');
        $merged = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        if (is_string($pathList) && $pathList !== '') {
            $merged = $pathList . ':' . $merged;
        }
        $cmd = 'export PATH=' . escapeshellarg($merged) . '; command -v node 2>/dev/null';
        $out = [];
        $code = 1;
        @exec('sh -c ' . escapeshellarg($cmd), $out, $code);
        if ($code !== 0 || $out === []) {
            return null;
        }
        $line = trim((string)($out[0] ?? ''));
        if ($line === '' || !str_contains($line, 'node')) {
            return null;
        }
        if (self::isRunnable($line)) {
            return $line;
        }

        return null;
    }
}
