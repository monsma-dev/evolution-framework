<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive;

/**
 * EmpireHaltGate — the "Dead Man's Switch".
 *
 * Three independent signals can put the entire autonomous stack into a
 * VETO state without redeploying code. The first one that answers truthy
 * wins; its identifier is reported as the halt source so operators can
 * see exactly what triggered the stop.
 *
 * Signals (checked in order, all independent):
 *   1. Environment variable EMPIRE_GLOBAL_HALT
 *        Truthy values: '1', 'true', 'yes', 'on' (case-insensitive).
 *        Preferred for EC2: set in systemd unit / docker env / .env-style
 *        file used by the PHP worker.
 *   2. Lock file  data/evolution/HALT.lock
 *        Any non-empty file at this path halts. First line is surfaced
 *        as the operator note (e.g. "maintenance 2026-04-17 by monsma").
 *        Use for instant SSH-side halts: `touch data/evolution/HALT.lock`
 *   3. Config key  app.empire.halt.enabled = true
 *        Lowest priority; useful for dev overrides via src/config/app.json.
 *
 * This class performs NO side effects. It only reads state. Integrate
 * from ReasoningEngine policies (RiskGatePolicy) and from any
 * trade-execution path that should respect the kill switch.
 */
final class EmpireHaltGate
{
    public const ENV_VAR       = 'EMPIRE_GLOBAL_HALT';
    public const LOCK_FILE_REL = 'data/evolution/HALT.lock';
    public const CONFIG_KEY    = 'app.empire.halt.enabled';

    /**
     * @param string|null          $basePath       Project root; defaults to BASE_PATH
     * @param array<string, mixed> $configSnapshot Optional resolved config for the CONFIG_KEY check
     */
    public function __construct(
        private readonly ?string $basePath = null,
        private readonly array $configSnapshot = []
    ) {
    }

    /**
     * @return array{
     *   active: bool,
     *   source: string|null,
     *   note: string|null,
     *   signals: array{env: bool, lock: bool, config: bool}
     * }
     */
    public function check(): array
    {
        $envOn    = $this->envIsTruthy();
        $lockOn   = $this->lockFileExists();
        $configOn = $this->configFlagEnabled();

        $source = null;
        $note   = null;
        if ($envOn) {
            $source = 'env:' . self::ENV_VAR;
            $note   = 'halted via environment variable';
        } elseif ($lockOn) {
            $source = 'lock_file:' . self::LOCK_FILE_REL;
            $note   = $this->readLockNote() ?: 'halted via lock file';
        } elseif ($configOn) {
            $source = 'config:' . self::CONFIG_KEY;
            $note   = 'halted via app.json config flag';
        }

        return [
            'active'  => $envOn || $lockOn || $configOn,
            'source'  => $source,
            'note'    => $note,
            'signals' => [
                'env'    => $envOn,
                'lock'   => $lockOn,
                'config' => $configOn,
            ],
        ];
    }

    /**
     * Convenience: just "are we halted?".
     */
    public function isActive(): bool
    {
        return $this->check()['active'];
    }

    private function envIsTruthy(): bool
    {
        $v = getenv(self::ENV_VAR);
        if ($v === false || $v === '') {
            return false;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    private function lockFileExists(): bool
    {
        return is_file($this->resolveLockPath());
    }

    private function readLockNote(): ?string
    {
        $path = $this->resolveLockPath();
        if (!is_file($path)) {
            return null;
        }
        $head = @file_get_contents($path, false, null, 0, 512);
        if (!is_string($head)) {
            return null;
        }
        $line = trim((string) strtok($head, "\n"));
        return $line !== '' ? $line : null;
    }

    private function configFlagEnabled(): bool
    {
        // Accept both flat 'app.empire.halt.enabled' keys and nested lookups.
        if (array_key_exists(self::CONFIG_KEY, $this->configSnapshot)) {
            return (bool) $this->configSnapshot[self::CONFIG_KEY];
        }
        $empire = $this->configSnapshot['empire'] ?? null;
        if (is_array($empire)
            && is_array($empire['halt'] ?? null)
            && array_key_exists('enabled', $empire['halt'])
        ) {
            return (bool) $empire['halt']['enabled'];
        }
        return false;
    }

    private function resolveLockPath(): string
    {
        $root = $this->basePath ?? (defined('BASE_PATH') ? BASE_PATH : (getcwd() ?: '.'));
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::LOCK_FILE_REL);
    }
}
