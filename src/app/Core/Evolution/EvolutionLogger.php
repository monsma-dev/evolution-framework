<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Append-only audit log for Architect / self-heal actions (evolution.log).
 */
final class EvolutionLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $channel, string $message, array $context = []): void
    {
        $path = self::logPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $line = json_encode([
            'ts' => gmdate('c'),
            'channel' => $channel,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    public static function logPath(): string
    {
        $rel = 'storage/evolution/evolution.log';
        if (isset(($GLOBALS)['app_container'])) {
            try {
                $c = ($GLOBALS)['app_container'];
                if (is_object($c) && method_exists($c, 'get')) {
                    $cfg = $c->get('config');
                    $p = $cfg->get('evolution.evolution_log', '');
                    if (is_string($p) && $p !== '') {
                        $rel = $p;
                    }
                }
            } catch (\Throwable) {
            }
        }

        if (str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rel) === 1) {
            return $rel;
        }

        return BASE_PATH . '/' . ltrim($rel, '/');
    }
}
