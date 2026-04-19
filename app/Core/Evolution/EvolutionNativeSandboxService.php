<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Isolation boundary for native (Rust/C++) compile + test. Never touches live PHP runtime until promoted.
 *
 * Default: workspace under storage/evolution/native_sandbox — optional Docker image runs cargo test.
 */
final class EvolutionNativeSandboxService
{
    public const DEFAULT_WORKSPACE = 'storage/evolution/native_sandbox';

    /**
     * Workspace-only OR explicit trusted crate root under BASE_PATH (e.g. src/rust/flash_crash_monitor).
     * Docker still bind-mounts only the resolved directory; this does not grant network escape.
     *
     * @return array{ok: bool, detail?: string}
     */
    public static function assertCratePathAllowed(Config $config, string $cratePathAbsolute): array
    {
        $ws = self::assertCratePathContainedInWorkspace($config, $cratePathAbsolute);
        if ($ws['ok'] ?? false) {
            return $ws;
        }

        $nc = $config->get('evolution.native_compiler', []);
        $trusted = is_array($nc) ? ($nc['trusted_rust_crates'] ?? []) : [];
        if (!is_array($trusted) || $trusted === []) {
            return $ws;
        }

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return ['ok' => false, 'detail' => 'BASE_PATH unresolved'];
        }
        $baseNorm = str_replace('\\', '/', $baseReal);

        $resolved = realpath($cratePathAbsolute);
        if ($resolved === false || str_contains($resolved, '..')) {
            return ['ok' => false, 'detail' => 'invalid crate path'];
        }
        $candNorm = str_replace('\\', '/', $resolved);
        if (!str_starts_with($candNorm, rtrim($baseNorm, '/') . '/')) {
            return ['ok' => false, 'detail' => 'crate outside project root'];
        }

        foreach ($trusted as $rel) {
            $rel = trim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $expected = $baseNorm . '/' . $rel;
            $expectedReal = realpath($expected);
            if ($expectedReal === false) {
                continue;
            }
            $expNorm = str_replace('\\', '/', $expectedReal);
            if ($candNorm === $expNorm || str_starts_with($candNorm, rtrim($expNorm, '/') . '/')) {
                return ['ok' => true, 'detail' => 'trusted_crate_root'];
            }
        }

        return $ws;
    }

    /**
     * Verifies $cratePathAbsolute resolves inside the configured native workspace (anti directory-traversal).
     *
     * @return array{ok: bool, detail?: string}
     */
    public static function assertCratePathContainedInWorkspace(Config $config, string $cratePathAbsolute): array
    {
        $ws = self::ensureWorkspace($config);
        if (!($ws['ok'] ?? false) || ($ws['path'] ?? '') === '') {
            return ['ok' => false, 'detail' => $ws['error'] ?? 'workspace'];
        }
        $root = realpath($ws['path']);
        if ($root === false) {
            return ['ok' => false, 'detail' => 'workspace path missing'];
        }
        $rootNorm = str_replace('\\', '/', $root);
        $resolved = realpath($cratePathAbsolute);
        if ($resolved !== false) {
            $candNorm = str_replace('\\', '/', $resolved);

            return [
                'ok' => $candNorm === $rootNorm || str_starts_with($candNorm, rtrim($rootNorm, '/') . '/'),
                'detail' => 'resolved path check',
            ];
        }

        // Not yet on disk: normalize against workspace prefix only (no .. escapes past root).
        $norm = str_replace('\\', '/', $cratePathAbsolute);
        if (str_contains($norm, '..')) {
            return ['ok' => false, 'detail' => 'path contains ..'];
        }
        $prefix = rtrim($rootNorm, '/') . '/';
        $ok = str_starts_with(strtolower($norm), strtolower($prefix));

        return ['ok' => $ok, 'detail' => 'prefix check (not yet materialized)'];
    }

    /**
     * @return array{ok: bool, skipped?: bool, detail?: string, stdout?: string}
     */
    public static function runCargoTest(Config $config, string $cratePathAbsolute): array
    {
        $nc = $config->get('evolution.native_compiler', []);
        if (!is_array($nc) || !filter_var($nc['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'skipped' => true, 'detail' => 'native_compiler disabled'];
        }

        $contained = self::assertCratePathAllowed($config, $cratePathAbsolute);
        if (!($contained['ok'] ?? false)) {
            EvolutionLogger::log('native_sandbox', 'path_rejected', ['path' => $cratePathAbsolute, 'detail' => $contained['detail'] ?? '']);

            return ['ok' => false, 'detail' => 'crate path outside native workspace'];
        }

        $cratePathAbsolute = realpath($cratePathAbsolute) ?: $cratePathAbsolute;
        if (!is_dir($cratePathAbsolute) || !is_file($cratePathAbsolute . '/Cargo.toml')) {
            return ['ok' => false, 'detail' => 'invalid crate path'];
        }

        $image = trim((string) ($nc['sandbox_docker_image'] ?? 'rust:1-bookworm'));
        if ($image === '') {
            $image = 'rust:1-bookworm';
        }

        $useDocker = filter_var($nc['sandbox_use_docker'] ?? true, FILTER_VALIDATE_BOOL);
        if (!$useDocker) {
            if (!self::commandExists('cargo')) {
                return ['ok' => false, 'detail' => 'cargo not in PATH and sandbox_use_docker=false'];
            }
            $cmd = 'cd ' . escapeshellarg($cratePathAbsolute) . ' && cargo test --no-fail-fast 2>&1';

            return self::execCapture($cmd);
        }

        if (!self::commandExists('docker')) {
            return ['ok' => false, 'detail' => 'docker not in PATH'];
        }

        $work = escapeshellarg($cratePathAbsolute);
        $extra = trim((string) ($nc['sandbox_docker_extra_args'] ?? '--user 1000:1000 --security-opt no-new-privileges=true'));
        if ($extra !== '') {
            $extra = ' ' . $extra;
        }
        $shell = sprintf(
            'docker run --rm%s -v %s:/crate -w /crate %s cargo test --no-fail-fast 2>&1',
            $extra,
            $work,
            escapeshellarg($image)
        );

        return self::execCapture($shell);
    }

    /**
     * @return array{ok: bool, detail?: string, stdout?: string}
     */
    private static function execCapture(string $shell): array
    {
        $out = [];
        $code = 0;
        @exec($shell, $out, $code);
        $stdout = implode("\n", $out);

        return [
            'ok' => $code === 0,
            'stdout' => $stdout,
            'detail' => $code === 0 ? 'cargo_ok' : 'cargo_exit_' . $code,
        ];
    }

    private static function commandExists(string $name): bool
    {
        $out = [];
        $code = 0;
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('where ' . escapeshellarg($name) . ' 2>nul', $out, $code);
        } else {
            @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $code);
        }

        return $code === 0;
    }

    /**
     * Safe workspace root for crates (creates dirs).
     *
     * @return array{ok: bool, path?: string, error?: string}
     */
    public static function ensureWorkspace(Config $config): array
    {
        $nc = $config->get('evolution.native_compiler', []);
        $rel = is_array($nc) ? trim((string) ($nc['workspace'] ?? self::DEFAULT_WORKSPACE)) : self::DEFAULT_WORKSPACE;
        if ($rel === '' || str_contains($rel, '..')) {
            return ['ok' => false, 'error' => 'invalid workspace'];
        }
        $path = BASE_PATH . '/' . ltrim($rel, '/');
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            return ['ok' => false, 'error' => 'cannot mkdir workspace'];
        }

        return ['ok' => true, 'path' => $path];
    }
}
