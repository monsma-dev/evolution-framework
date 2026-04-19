<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Auto-Coder pipeline: sandbox test → optional Telegram provision proposal.
 *
 * Security model (cannot "escape" the host from Docker alone):
 * - Only paths allowed by EvolutionNativeSandboxService (workspace or trusted_rust_crates).
 * - Docker: non-root user, no-new-privileges, single-directory bind mount (no host /var/docker.sock mount in this tool).
 * - Production deploy remains behind EvolutionApprovalGateway + human approve.
 *
 * Residual risk: malicious Cargo.toml build.rs could try host side-effects; review trusted crates and disable
 * native_compiler on nodes that do not need it.
 */
final class EvolutionAutoCoderService
{
    public function __construct(private readonly Config $config) {}

    /**
     * Run cargo test in sandbox for a crate path (absolute or relative to BASE_PATH).
     *
     * @return array{ok: bool, stdout?: string, detail?: string}
     */
    public function runSandboxTests(string $cratePath): array
    {
        $abs = $this->normalizeCratePath($cratePath);
        if ($abs === null) {
            return ['ok' => false, 'detail' => 'invalid crate path'];
        }

        return EvolutionNativeSandboxService::runCargoTest($this->config, $abs);
    }

    /**
     * After green tests, register human approval + Telegram ping (if configured).
     *
     * @param array{summary?: string, estimated_gain?: string} $meta
     */
    public function proposeDeploy(
        string $title,
        string $description,
        string $approveCommand,
        array $meta = []
    ): string {
        $gw    = new EvolutionApprovalGateway($this->config);
        $cost  = 0.0;
        $extra = array_merge($meta, ['kind' => 'autocoder_green_build']);
        $id    = $gw->create($approveCommand, $description, $cost, $extra);

        $ac = $this->config->get('evolution.autocoder', []);
        if (is_array($ac) && filter_var($ac['notify_telegram_on_green'] ?? true, FILTER_VALIDATE_BOOL)) {
            $notify = new EvolutionNotifier($this->config);
            $gain   = (string)($meta['estimated_gain'] ?? 'n/a');
            $why    = (string)($meta['summary'] ?? $description);
            $notify->businessCase(
                $title,
                $why,
                '€0 incremental (code swap)',
                "Claimed: {$gain}",
                $id,
                $approveCommand
            );
        }

        return $id;
    }

    /**
     * Default trusted crate from config (e.g. flash_crash_monitor).
     */
    public function defaultCrateAbsolutePath(): ?string
    {
        $ac = $this->config->get('evolution.autocoder', []);
        $rel = is_array($ac) ? trim((string)($ac['default_crate_rel'] ?? 'src/rust/flash_crash_monitor')) : 'src/rust/flash_crash_monitor';
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $full = rtrim($base, '/\\') . '/' . str_replace('\\', '/', $rel);

        return is_dir($full) ? $full : null;
    }

    private function normalizeCratePath(string $cratePath): ?string
    {
        $cratePath = trim($cratePath);
        if ($cratePath === '') {
            return null;
        }
        if (str_contains($cratePath, '..')) {
            return null;
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        if (!str_starts_with($cratePath, '/') && !preg_match('#^[a-zA-Z]:\\\\#', $cratePath)) {
            $cratePath = rtrim($base, '/\\') . '/' . str_replace('\\', '/', $cratePath);
        }
        $real = realpath($cratePath);

        return ($real !== false && is_dir($real)) ? $real : null;
    }
}
