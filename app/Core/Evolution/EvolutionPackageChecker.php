<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Boodschappenmand — Composer (and optional Cargo) outdated suggestions; does not install.
 */
final class EvolutionPackageChecker
{
    /**
     * @return array{ok: bool, stdout: string, stderr: string, packages?: list<array<string, mixed>>}
     */
    public static function composerOutdatedDirect(): array
    {
        if (!is_file(BASE_PATH . '/composer.json')) {
            return ['ok' => false, 'stdout' => '', 'stderr' => 'composer.json missing'];
        }

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $phar = BASE_PATH . '/composer.phar';
        $cmdLine = '';
        if (is_file($phar)) {
            $cmdLine = escapeshellarg($php) . ' ' . escapeshellarg($phar) . ' outdated --direct --format=json --no-ansi 2>&1';
        } elseif (is_file(BASE_PATH . '/composer.cmd')) {
            $cmdLine = 'cmd /c ' . escapeshellarg(BASE_PATH . '/composer.cmd') . ' outdated --direct --format=json --no-ansi 2>&1';
        } else {
            $cmdLine = 'composer outdated --direct --format=json --no-ansi 2>&1';
        }

        $out = [];
        $code = 0;
        $prev = @getcwd();
        @chdir(BASE_PATH);
        @exec($cmdLine, $out, $code);
        if (is_string($prev)) {
            @chdir($prev);
        }
        $raw = implode("\n", $out);

        return self::parseComposerJson($raw, $code);
    }

    /**
     * @return array{ok: bool, stdout: string, stderr: string, packages?: list<array<string, mixed>>}
     */
    private static function parseComposerJson(string $raw, int $code): array
    {
        $j = json_decode($raw, true);
        $list = [];
        if (is_array($j)) {
            if (array_is_list($j)) {
                foreach ($j as $row) {
                    if (is_array($row) && isset($row['name'])) {
                        $list[] = $row;
                    }
                }
            } else {
                foreach ($j['installed'] ?? [] as $row) {
                    if (is_array($row) && isset($row['name'])) {
                        $list[] = $row;
                    }
                }
            }
        }

        return [
            'ok' => $code === 0 || $list !== [],
            'stdout' => $raw,
            'stderr' => $code !== 0 && $list === [] ? 'composer outdated may have failed' : '',
            'packages' => $list,
        ];
    }

    /**
     * @return array{ok: bool, note: string, stdout?: string}
     */
    public static function cargoOutdatedHint(string $relativeCargoTomlDir = 'storage/evolution/native_sandbox'): array
    {
        $dir = BASE_PATH . '/' . trim(str_replace('\\', '/', $relativeCargoTomlDir), '/');
        if (!is_file($dir . '/Cargo.toml')) {
            return ['ok' => true, 'note' => 'No Cargo.toml at ' . $relativeCargoTomlDir . ' — skip Rust crate scan.'];
        }

        $out = [];
        $code = 0;
        @exec('cargo outdated --format json 2>&1', $out, $code);

        return [
            'ok' => $code === 0,
            'note' => 'cargo outdated (advisory only; Architect approves upgrades)',
            'stdout' => implode("\n", array_slice($out, 0, 40)),
        ];
    }
}
