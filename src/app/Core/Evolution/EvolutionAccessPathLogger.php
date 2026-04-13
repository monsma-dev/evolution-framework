<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Request;

/**
 * Sampled HTTP path log for EvolutionArchivistService (staleness by route traffic).
 */
final class EvolutionAccessPathLogger
{
    private const FILE = 'storage/evolution/access_paths.jsonl';
    private const MAX_BYTES = 8_388_608;

    public static function maybeLog(Config $config, Request $request): void
    {
        $arch = $config->get('evolution.archivist', []);
        if (!is_array($arch) || !filter_var($arch['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return;
        }
        if (!filter_var($arch['log_http_paths'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }
        $sample = (int) ($arch['access_log_sample'] ?? 20);
        $sample = max(1, min(500, $sample));
        if (random_int(1, $sample) !== 1) {
            return;
        }
        if (!defined('BASE_PATH')) {
            return;
        }
        $path = BASE_PATH . '/' . self::FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        if (is_file($path) && filesize($path) > self::MAX_BYTES) {
            @rename($path, $path . '.' . gmdate('Ymd_His') . '.bak');
        }
        $line = json_encode([
            'ts' => time(),
            'path' => substr($request->path, 0, 2048),
            'method' => $request->method,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
