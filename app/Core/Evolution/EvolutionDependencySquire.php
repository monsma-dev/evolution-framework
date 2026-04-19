<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Composer / PHP platform guard before injecting new code or packages (Squire).
 */
final class EvolutionDependencySquire
{
    /**
     * @return array{ok: bool, php_version: string, platform?: array<string, mixed>, errors: list<string>}
     */
    public static function auditProject(): array
    {
        $errors = [];
        $php = PHP_VERSION;
        $base = defined('BASE_PATH') ? BASE_PATH : '';
        $composerJson = $base . '/composer.json';
        $composerLock = $base . '/composer.lock';
        $platform = [];

        if (is_file($composerJson)) {
            $raw = @file_get_contents($composerJson);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($j)) {
                $platform = is_array($j['require'] ?? null) ? $j['require'] : [];
            } else {
                $errors[] = 'composer.json invalid JSON';
            }
        } else {
            $errors[] = 'composer.json missing';
        }

        if (is_file($composerLock)) {
            $raw = @file_get_contents($composerLock);
            $lock = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($lock)) {
                $errors[] = 'composer.lock invalid JSON';
            }
        } else {
            $errors[] = 'composer.lock missing';
        }

        $phpReq = (string) ($platform['php'] ?? '');
        if ($phpReq !== '' && !self::versionSatisfies($php, $phpReq)) {
            $errors[] = 'runtime PHP ' . $php . ' does not satisfy composer require php: ' . $phpReq;
        }

        return [
            'ok' => $errors === [],
            'php_version' => $php,
            'platform' => $platform,
            'errors' => $errors,
        ];
    }

    private static function versionSatisfies(string $have, string $constraint): bool
    {
        $c = trim($constraint);
        if (preg_match('/^\^(\d+)\.(\d+)/', $c, $m)) {
            return version_compare($have, $m[1] . '.' . $m[2], '>=');
        }
        if (preg_match('/^>=\s*(\d+\.\d+\.\d+|\d+\.\d+)/', $c, $m)) {
            return version_compare($have, $m[1], '>=');
        }

        return true;
    }
}
