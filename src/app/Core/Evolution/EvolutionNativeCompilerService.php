<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Orchestrates Rust/C++ native bridges: sandbox compile, dual-execution gate, staged ini (never live by default).
 *
 * Model policy (credits): use evolution.native_compiler.cheap_model for codegen drafts;
 * evolution.native_compiler.safety_model for final review of generated unsafe blocks (Architect wiring TBD).
 */
final class EvolutionNativeCompilerService
{
    public const PENDING_INI_DIR = 'storage/evolution/native_pending';

    public const HOF_FLAG = 'storage/evolution/.native_speed_hof_done';

    public const NATIVE_STAGING_DIR = 'storage/evolution/native_staging';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Run cargo test in sandbox for a crate under workspace/crates/{id}.
     *
     * @return array{ok: bool, error?: string, cargo?: array<string, mixed>}
     */
    public function compileAndTestCrate(string $crateId): array
    {
        $cfg = $this->container->get('config');
        $ws = EvolutionNativeSandboxService::ensureWorkspace($cfg);
        if (!($ws['ok'] ?? false)) {
            return ['ok' => false, 'error' => $ws['error'] ?? 'workspace'];
        }

        $crateId = preg_replace('/[^a-z0-9_-]/i', '', $crateId) ?? '';
        if ($crateId === '') {
            return ['ok' => false, 'error' => 'invalid crate id'];
        }

        $path = ($ws['path'] ?? '') . '/crates/' . $crateId;
        if (!is_dir($path)) {
            return ['ok' => false, 'error' => 'crate not found: ' . $crateId];
        }

        $cargo = EvolutionNativeSandboxService::runCargoTest($cfg, $path);

        EvolutionLogger::log('native_compiler', 'cargo_test', [
            'crate' => $crateId,
            'ok' => $cargo['ok'] ?? false,
        ]);

        return [
            'ok' => (bool) ($cargo['ok'] ?? false),
            'cargo' => $cargo,
        ];
    }

    /**
     * Same as compileAndTestCrate but retries cargo up to compile_max_retries (default 5), then aborts with Hall of Fame entry.
     *
     * @return array{ok: bool, error?: string, cargo?: array<string, mixed>, attempts?: int, aborted?: bool}
     */
    public function compileAndTestCrateWithRetries(string $crateId): array
    {
        $cfg = $this->container->get('config');
        $nc = $cfg->get('evolution.native_compiler', []);
        $max = is_array($nc) ? (int) ($nc['compile_max_retries'] ?? 5) : 5;
        $max = max(1, min(20, $max));

        $last = null;
        for ($i = 0; $i < $max; $i++) {
            $last = $this->compileAndTestCrate($crateId);
            if ($last['ok'] ?? false) {
                return array_merge($last, ['attempts' => $i + 1]);
            }
            EvolutionLogger::log('native_compiler', 'cargo_retry', ['crate' => $crateId, 'attempt' => $i + 1]);
        }

        $this->recordCompileAbortHallOfFame($crateId, $last);

        return array_merge($last ?? ['ok' => false], [
            'attempts' => $max,
            'aborted' => true,
            'error' => 'compile_max_retries exceeded',
        ]);
    }

    /**
     * Promote a built .so/.dll from a temp path into place using same-directory rename (atomic on POSIX when dest is final path).
     * Live PHP should only load the new path after cargo test + dual gate pass (caller responsibility).
     *
     * @return array{ok: bool, path?: string, error?: string}
     */
    public function promoteNativeArtifact(string $tempAbsolutePath, string $destAbsolutePath): array
    {
        $tempAbsolutePath = realpath($tempAbsolutePath) ?: $tempAbsolutePath;
        if (!is_file($tempAbsolutePath)) {
            return ['ok' => false, 'error' => 'temp artifact missing'];
        }
        $dir = dirname($destAbsolutePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'cannot mkdir dest'];
        }
        $rand = bin2hex(random_bytes(6));
        $staging = $dir . '/.' . basename($destAbsolutePath) . '.staging.' . $rand;
        if (!@copy($tempAbsolutePath, $staging)) {
            return ['ok' => false, 'error' => 'staging copy failed'];
        }
        if (!@rename($staging, $destAbsolutePath)) {
            @unlink($staging);

            return ['ok' => false, 'error' => 'atomic rename failed'];
        }

        EvolutionLogger::log('native_compiler', 'artifact_promoted', ['dest' => $destAbsolutePath]);

        return ['ok' => true, 'path' => $destAbsolutePath];
    }

    /**
     * Dual-execution gate: $phpFn vs $nativeFn must match for all iterations.
     *
     * @param callable(int): mixed $inputGenerator
     * @param callable(mixed): mixed $phpFn
     * @param callable(mixed): mixed $nativeFn
     *
     * @return array{ok: bool, guard: array<string, mixed>, hall_of_fame?: bool}
     */
    public function runDualExecutionGate(
        callable $inputGenerator,
        callable $phpFn,
        callable $nativeFn,
        ?callable $normalize = null,
        ?int $iterationOverride = null
    ): array {
        $cfg = $this->container->get('config');
        $nc = $cfg->get('evolution.native_compiler', []);
        $maxCap = is_array($nc) ? (int) ($nc['dual_execution_max_iterations'] ?? 1_000_000) : 1_000_000;
        $maxCap = max(100, min(1_000_000, $maxCap));
        $n = is_array($nc) ? (int) ($nc['dual_execution_iterations'] ?? 100) : 100;
        if ($iterationOverride !== null) {
            $n = $iterationOverride;
        }
        $n = max(1, min($maxCap, $n));

        $strictTypes = is_array($nc) && filter_var($nc['dual_execution_strict'] ?? true, FILTER_VALIDATE_BOOL);
        $guard = EvolutionDualExecutionGuard::compare(
            $n,
            $inputGenerator,
            $phpFn,
            $nativeFn,
            $normalize,
            [
                'hard_cap_iterations' => $maxCap,
                'strict_types' => $strictTypes,
                'track_memory' => true,
            ]
        );
        $ok = (bool) ($guard['ok'] ?? false);

        EvolutionLogger::log('native_compiler', 'dual_execution', [
            'ok' => $ok,
            'iterations' => $guard['iterations'] ?? 0,
            'mismatches' => $guard['mismatches'] ?? -1,
        ]);

        $hof = false;
        if ($ok) {
            $hof = $this->recordHallOfFameIfNeeded();
        }

        return ['ok' => $ok, 'guard' => $guard, 'hall_of_fame' => $hof];
    }

    /**
     * Write a staged extension ini snippet (admin must merge into docker/php/99-framework.ini or include).
     * Never overwrites live ini unless evolution.native_compiler.allow_live_ini_write is true (discouraged).
     *
     * @return array{ok: bool, path?: string, error?: string}
     */
    public function stageExtensionIni(string $extensionLine, string $label): array
    {
        $cfg = $this->container->get('config');
        $nc = $cfg->get('evolution.native_compiler', []);
        if (!is_array($nc) || !filter_var($nc['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'native_compiler disabled'];
        }

        $allow = filter_var($nc['allow_live_ini_write'] ?? false, FILTER_VALIDATE_BOOL);
        $dir = BASE_PATH . '/' . self::PENDING_INI_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'cannot create pending dir'];
        }

        $safe = preg_replace('/[^a-z0-9_-]/i', '', $label) ?: 'ext';
        $file = $dir . '/' . $safe . '_' . gmdate('Ymd-His') . '.ini';
        $hdr = '; Evolution native bridge — review before production' . "\n" . '; ' . gmdate('c') . "\n";
        if (@file_put_contents($file, $hdr . $extensionLine . "\n") === false) {
            return ['ok' => false, 'error' => 'cannot write ini snippet'];
        }

        if ($allow) {
            $live = BASE_PATH . '/docker/php/99-framework.ini';
            if (is_writable($live) || is_writable(dirname($live))) {
                @file_put_contents($live, "\n" . $extensionLine . "\n", FILE_APPEND | LOCK_EX);
            }
        }

        return ['ok' => true, 'path' => $file];
    }

    /**
     * @return array<string, mixed>
     */
    public static function configSummary(Config $config): array
    {
        $nc = $config->get('evolution.native_compiler', []);

        return is_array($nc) ? $nc : [];
    }

    private function recordHallOfFameIfNeeded(): bool
    {
        $cfg = $this->container->get('config');
        $nc = $cfg->get('evolution.native_compiler', []);
        if (!is_array($nc) || !filter_var($nc['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $flag = BASE_PATH . '/' . self::HOF_FLAG;
        if (is_file($flag)) {
            return false;
        }
        @file_put_contents($flag, gmdate('c') . "\n");
        (new EvolutionHallOfFameService($this->container))->recordMilestone(
            'Native Speed Reached: Hot logic is now running on the Metal.',
            'native',
            ['native_compiler' => true, 'dual_execution' => true]
        );

        return true;
    }

    /**
     * @param array<string, mixed>|null $lastCargo
     */
    private function recordCompileAbortHallOfFame(string $crateId, ?array $lastCargo): void
    {
        $cfg = $this->container->get('config');
        $nc = $cfg->get('evolution.native_compiler', []);
        if (!is_array($nc) || !filter_var($nc['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return;
        }

        (new EvolutionHallOfFameService($this->container))->recordMilestone(
            'Native compile abort: ' . $crateId . ' — max retries exhausted (see evolution.log).',
            'native_compile_abort',
            ['crate' => $crateId, 'last' => $lastCargo]
        );
    }
}
