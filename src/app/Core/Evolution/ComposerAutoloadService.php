<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Safe wrapper for `composer dump-autoload` after PSR-4 path moves (structural refactor).
 */
final class ComposerAutoloadService
{
    /**
     * @return array{ok: bool, output?: string, error?: string}
     */
    public static function dumpAutoload(Config $config): array
    {
        $evo = $config->get('evolution.composer_autoload', []);
        if (is_array($evo) && !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'composer_autoload disabled in config'];
        }
        if (!defined('BASE_PATH')) {
            return ['ok' => false, 'error' => 'BASE_PATH undefined'];
        }
        $base = BASE_PATH;
        if (!is_file($base . '/composer.json')) {
            return ['ok' => false, 'error' => 'composer.json not found at project root'];
        }

        $bin = trim((string) (is_array($evo) ? ($evo['composer_binary'] ?? 'composer') : 'composer'));
        if ($bin === '') {
            $bin = 'composer';
        }

        $timeout = 120;
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmd = escapeshellarg($bin) . ' dump-autoload -o';
        $proc = proc_open($cmd, $descriptor, $pipes, $base, null, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], $timeout);
        stream_set_timeout($pipes[2], $timeout);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $combined = trim((string) $out . "\n" . (string) $err);

        if ($code !== 0) {
            EvolutionLogger::log('composer_autoload', 'failed', ['code' => $code, 'output' => $combined]);

            return ['ok' => false, 'error' => 'composer exit ' . $code, 'output' => $combined];
        }
        EvolutionLogger::log('composer_autoload', 'ok', []);

        return ['ok' => true, 'output' => $combined];
    }
}
