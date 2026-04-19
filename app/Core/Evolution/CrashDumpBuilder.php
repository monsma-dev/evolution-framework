<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use Throwable;

/**
 * Builds a structured crash payload (stack, snippet, env summary).
 * DB query ring-buffer integration can be added later (Phase C/D).
 */
final class CrashDumpBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Throwable $e, ?Config $config = null): array
    {
        $file = $e->getFile();
        $line = $e->getLine();
        $snippet = self::readSnippet($file, $line);

        $env = [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
        ];
        if ($config !== null) {
            $env['app_env'] = (string)$config->get('env', 'unknown');
            $env['debug'] = (bool)$config->get('debug', false);
        }

        return [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $file,
            'line' => $line,
            'trace' => $e->getTraceAsString(),
            'snippet' => $snippet,
            'env' => $env,
            'recent_queries' => [],
        ];
    }

    /**
     * Persist dump to disk for async AI repair workflows.
     *
     * @return array{ok: bool, path?: string, error?: string}
     */
    public static function persist(Throwable $e, ?Config $config = null): array
    {
        $dump = self::build($e, $config);
        $dir = self::dumpsDir($config);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ['ok' => false, 'error' => 'Cannot create crash dump directory'];
            }
        }

        $name = 'crash-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $path = $dir . '/' . $name;
        $json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($path, $json) === false) {
            return ['ok' => false, 'error' => 'Write failed'];
        }

        EvolutionLogger::log('crash_dump', 'persisted', ['path' => $path]);

        return ['ok' => true, 'path' => $path];
    }

    private static function dumpsDir(?Config $config): string
    {
        $rel = 'storage/evolution/crash_dumps';
        if ($config !== null) {
            $p = $config->get('evolution.crash_dumps_dir', '');
            if (is_string($p) && $p !== '') {
                $rel = $p;
            }
        }
        if (str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rel) === 1) {
            return $rel;
        }

        return BASE_PATH . '/' . ltrim($rel, '/');
    }

    /**
     * @return list<string>
     */
    private static function readSnippet(string $file, int $line, int $radius = 5): array
    {
        if (!is_file($file) || $line < 1) {
            return [];
        }
        $lines = @file($file);
        if (!is_array($lines)) {
            return [];
        }
        $idx = $line - 1;
        $start = max(0, $idx - $radius);
        $end = min(count($lines), $idx + $radius + 1);
        $out = [];
        for ($i = $start; $i < $end; $i++) {
            $out[] = rtrim((string)($lines[$i] ?? ''), "\r\n");
        }

        return $out;
    }
}
