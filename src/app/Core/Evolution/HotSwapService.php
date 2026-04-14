<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Zero-downtime safety net: after a shadow patch swap, keeps a versioned backup and
 * rolls back on fatal error in the same request (shutdown handler) — faster than async Guard Dog.
 *
 * After moving/renaming PHP classes, run ComposerAutoloadService::dumpAutoload and HotSwapService::disarm()
 * so armed rollback state does not reference stale paths. Fatal errors are matched using realpath when possible.
 */
final class HotSwapService
{
    private const ARM_FILE = 'storage/evolution/hot_swap_arm.json';
    private const BACKUP_ROOT = 'storage/evolution/versioned_backups';

    private static bool $shutdownRegistered = false;

    public static function registerShutdownHandler(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            if (!defined('BASE_PATH')) {
                return;
            }
            $armPath = BASE_PATH . '/' . self::ARM_FILE;
            if (!is_file($armPath)) {
                return;
            }
            $raw = @file_get_contents($armPath);
            $arm = @json_decode($raw ?: '', true);
            if (!is_array($arm)) {
                @unlink($armPath);

                return;
            }

            $patchPath = (string) ($arm['patch_path'] ?? '');
            $backupPath = (string) ($arm['backup_path'] ?? '');
            $err = error_get_last();
            $fatal = $err !== null && in_array((int) ($err['type'] ?? 0), [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true);

            if (!$fatal) {
                @unlink($armPath);

                return;
            }

            $errFile = (string) ($err['file'] ?? '');
            $patchReal = $patchPath !== '' && is_file($patchPath) ? realpath($patchPath) : false;
            $errReal = $errFile !== '' && is_file($errFile) ? realpath($errFile) : false;
            $match = ($patchPath !== '' && $errFile !== ''
                    && ($errFile === $patchPath
                        || ($patchReal !== false && $errReal !== false && $patchReal === $errReal)))
                || str_contains($errFile, '/storage/patches/');
            if ($match && $backupPath !== '' && is_file($backupPath) && $patchPath !== '' && is_file($patchPath)) {
                @copy($backupPath, $patchPath);
                OpcacheIntelligenceService::invalidateFiles([$patchPath]);
                EvolutionLogger::log('hot_swap', 'rollback_fatal', [
                    'fqcn' => $arm['fqcn'] ?? '',
                    'message' => (string) ($err['message'] ?? ''),
                    'line' => (int) ($err['line'] ?? 0),
                ]);
            } else {
                EvolutionLogger::log('hot_swap', 'fatal_without_patch_match', [
                    'errfile' => $errFile,
                    'message' => (string) ($err['message'] ?? ''),
                ]);
            }
            @unlink($armPath);
        });
    }

    public static function isEnabled(?Config $config = null): bool
    {
        $cfg = $config ?? self::config();
        if ($cfg === null) {
            return false;
        }
        $hs = $cfg->get('evolution.hot_swap', []);

        return is_array($hs) && filter_var($hs['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Call after a successful patch write. Copies previous live file to versioned backup (if any).
     *
     * @return string|null path to backup file
     */
    public static function backupPreviousVersion(string $fqcn, string $patchPath): ?string
    {
        if (!is_file($patchPath)) {
            return null;
        }
        $dir = BASE_PATH . '/' . self::BACKUP_ROOT . '/' . hash('sha256', $fqcn);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $name = 'prev-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.php';
        $dest = $dir . '/' . $name;
        if (!@copy($patchPath, $dest)) {
            return null;
        }

        return $dest;
    }

    /**
     * Versioned backup for any project file (e.g. src/bootstrap/app.php) before EvolutionConfigService edits.
     *
     * @return non-falsy-string|null
     */
    public static function backupArbitraryFile(string $absPath): ?string
    {
        if (!is_file($absPath)) {
            return null;
        }
        $dir = BASE_PATH . '/' . self::BACKUP_ROOT . '/arbitrary';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $tag = str_replace(['/', '\\'], '_', substr($absPath, strlen(BASE_PATH) + 1));
        $name = 'prev-' . $tag . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.bak';
        $dest = $dir . '/' . $name;
        if (!@copy($absPath, $dest)) {
            return null;
        }

        return $dest;
    }

    /**
     * Arm automatic rollback for this request if a fatal occurs while loading the patch.
     */
    public static function arm(string $fqcn, string $patchPath, ?string $backupPath): void
    {
        if ($backupPath === null || !is_file($backupPath)) {
            return;
        }
        $payload = [
            'fqcn' => $fqcn,
            'patch_path' => $patchPath,
            'backup_path' => $backupPath,
            'armed_at' => gmdate('c'),
        ];
        $p = BASE_PATH . '/' . self::ARM_FILE;
        $d = dirname($p);
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
        @file_put_contents($p, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Clear armed rollback state (e.g. after structural moves or successful composer dump-autoload).
     */
    public static function disarm(): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        @unlink(BASE_PATH . '/' . self::ARM_FILE);
    }

    private static function config(): ?Config
    {
        try {
            $c = ($GLOBALS)['app_container'] ?? null;
            if (is_object($c) && method_exists($c, 'get')) {
                return $c->get('config');
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
