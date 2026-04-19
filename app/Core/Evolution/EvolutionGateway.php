<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Evolution\EvolutionLicenseService;

/**
 * Evolution API Gateway — Multi-Tenant Licence Guard.
 *
 * SLEEPING BY DEFAULT.
 * Set  evolution.gateway.enabled = true  in evolution.json to activate.
 * Sovereign servers (EVOLUTION_SOVEREIGN=true) always bypass.
 *
 * ─── What this does ──────────────────────────────────────────────────────────
 *
 *  Before every "evolve:" command:
 *   1. If gateway is DISABLED → pass through unchanged (slapende modus)
 *   2. If Sovereign → pass through with audit log
 *   3. Validate licence via LicenseService (GitHub vault + offline cache)
 *   4. Check tier → command whitelist
 *   5. Check monthly quota (call count + token budget)
 *   6. Allow or deny with clear reason
 *
 *  After every command:
 *   7. TenantUsageTracker::record() logs cpu_ms + tokens + cost_usd
 *
 * ─── Tier → Command Mapping ──────────────────────────────────────────────────
 *
 *  starter:    evolve:neural, evolve:repair, evolve:heartbeat, evolve:observe,
 *              evolve:synthfactory
 *
 *  pro:        + evolve:redteam, evolve:reincarnate, evolve:economist,
 *                evolve:benchmark, evolve:forecast, evolve:ancestry,
 *                evolve:predict, evolve:figma, evolve:superjury
 *
 *  enterprise: all commands including evolve:provision, evolve:twin, evolve:neural
 *
 * ─── Quotas ──────────────────────────────────────────────────────────────────
 *
 *  starter:    100 calls/month,  50 000 tokens/month
 *  pro:       1 000 calls/month, 500 000 tokens/month
 *  enterprise:  unlimited (-1)
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *  In ai_bridge.php, around the evolve:* dispatch block:
 *
 *   $gw = EvolutionGateway::guard($command, $config);
 *   if (!$gw['ok']) { fwrite(STDERR, $gw['reason'] . "\n"); return 1; }
 *   $t0 = microtime(true);
 *   $exitCode = ...; // run command
 *   EvolutionGateway::trackUsage($command, $gw, $t0);
 *   return $exitCode;
 */
final class EvolutionGateway
{
    // ── Tier → command whitelist ──────────────────────────────────────────────

    /**
     * Commands that are always allowed regardless of license tier.
     * Includes housekeeping + the license command itself.
     */
    private const BYPASS_COMMANDS = [
        'evolve:license',
        'evolve:status',
        'evolve:usage',
        'evolve:docs',
        'evolve:heartbeat',
        'evolve:academy',
        'evolve:scout',
        'evolve:github-setup',
        'evolve:breed',
        'evolve:alpha-cycle',
    ];

    private const TIER_COMMANDS = [
        'free' => [
            'evolve:analyse',
            'evolve:status',
            'evolve:usage',
            'evolve:docs',
            'evolve:vector-build',
        ],
        'starter' => [
            'evolve:analyse', 'evolve:fix', 'evolve:test', 'evolve:review',
            'evolve:document', 'evolve:migrate', 'evolve:optimise', 'evolve:security',
            'evolve:refactor', 'evolve:dependencies', 'evolve:vector-build', 'evolve:docs',
            'evolve:status', 'evolve:skill', 'evolve:usage',
            // legacy names kept for backward compat
            'evolve:neural', 'evolve:repair', 'evolve:heartbeat', 'evolve:observe',
        ],
        'pro' => [
            '*_except_brain',  // special marker: all except brain exports
        ],
        'enterprise' => ['*'],  // all commands
    ];

    // ── Monthly quotas ────────────────────────────────────────────────────────

    private const TIER_QUOTAS = [
        'starter'    => ['calls' => 100,    'tokens' => 50_000],
        'pro'        => ['calls' => 1_000,  'tokens' => 500_000],
        'enterprise' => ['calls' => -1,     'tokens' => -1],
    ];

    /**
     * Guard — call BEFORE executing a command.
     *
     * Returns:
     *   ok         bool   — whether execution is allowed
     *   reason     string — human-readable denial reason (empty when ok)
     *   tenant_id  string — hashed licence key (for usage tracking)
     *   tier       string — starter|pro|enterprise|sovereign|bypass
     *   gateway_active bool — false when sleeping mode
     *
     * @return array{ok: bool, reason: string, tenant_id: string, tier: string, gateway_active: bool}
     */
    public static function guard(string $command, Config $config): array
    {
        // ── Sleeping mode ─────────────────────────────────────────────────────
        $gwConfig = $config->get('evolution.gateway', []);
        $enabled  = filter_var(is_array($gwConfig) ? ($gwConfig['enabled'] ?? false) : false, FILTER_VALIDATE_BOOL);

        if (!$enabled) {
            return self::pass('bypass', 'gateway_disabled');
        }

        // ── Always-allowed commands (license management, status, etc.) ─────────
        $baseCmd = strtok($command, ' ') ?: $command;
        if (in_array($baseCmd, self::BYPASS_COMMANDS, true)) {
            return self::pass('bypass', 'always_allowed');
        }

        // ── Sovereign bypass (owner's server) ─────────────────────────────────
        if (LicenseService::isSovereign()) {
            EvolutionLogger::log('gateway', 'sovereign_bypass', ['command' => $command]);
            return self::pass('sovereign', 'sovereign', true);
        }

        // ── License check via EvolutionLicenseService ─────────────────────────
        $evoLicense = (new EvolutionLicenseService($config))->status();
        if ($evoLicense['active']) {
            $tier     = (string)($evoLicense['tier'] ?? 'free');
            $tenantId = hash('sha256', (string)($evoLicense['key'] ?? gethostname()));

            if (!self::commandAllowed($command, $tier)) {
                $upgrade = self::upgradeHint($command);
                return self::deny(
                    "Command '{$command}' requires a higher tier ({$upgrade}). "
                    . "Your tier: {$tier}. Run `evolve:license activate <key>` to upgrade.",
                    $tenantId,
                    $tier
                );
            }

            EvolutionLogger::log('gateway', 'evo_license_allowed', ['command' => $command, 'tier' => $tier]);
            return self::pass($tier, $tenantId, true);
        }

        // ── Fallback: legacy LicenseService validation ────────────────────────
        $licResult = LicenseService::check($config);
        if (!$licResult['ok']) {
            return self::deny(
                "No active license. Run `php ai_bridge.php evolve:license activate <key>` "
                . "or visit /evolution#unlock to activate. Free: /evolution/arena for demo."
            );
        }

        // ── Resolve tier ──────────────────────────────────────────────────────
        $tier     = self::resolveTier($config);
        $tenantId = self::tenantId($config);

        // ── Command whitelist check ───────────────────────────────────────────
        if (!self::commandAllowed($command, $tier)) {
            $upgrade = self::upgradeHint($command);
            return self::deny("Command '{$command}' requires tier: {$upgrade}. Your tier: {$tier}.", $tenantId, $tier);
        }

        // ── Quota check ───────────────────────────────────────────────────────
        $quota = self::TIER_QUOTAS[$tier] ?? self::TIER_QUOTAS['starter'];
        if ($quota['calls'] !== -1) {
            $usage = TenantUsageTracker::monthlyCallCount($tenantId);
            if ($usage >= $quota['calls']) {
                return self::deny(
                    "Monthly call quota exceeded ({$usage}/{$quota['calls']}). Upgrade to increase limit.",
                    $tenantId, $tier
                );
            }
        }

        EvolutionLogger::log('gateway', 'allowed', [
            'command'   => $command,
            'tenant'    => substr($tenantId, 0, 12),
            'tier'      => $tier,
        ]);

        return [
            'ok'             => true,
            'reason'         => '',
            'tenant_id'      => $tenantId,
            'tier'           => $tier,
            'gateway_active' => true,
        ];
    }

    /**
     * Track — call AFTER a command finishes to log resource consumption.
     *
     * @param array{ok: bool, reason: string, tenant_id: string, tier: string, gateway_active: bool} $guardResult
     */
    public static function trackUsage(string $command, array $guardResult, float $startMicrotime, int $tokensUsed = 0): void
    {
        if (!($guardResult['gateway_active'] ?? false)) {
            return;  // sleeping — nothing to log
        }

        $tenantId = (string)($guardResult['tenant_id'] ?? '');
        if ($tenantId === '' || $tenantId === 'sovereign' || $tenantId === 'bypass') {
            return;
        }

        $cpuMs   = round((microtime(true) - $startMicrotime) * 1000, 1);
        $costUsd = self::estimateCost($tokensUsed);

        TenantUsageTracker::record($tenantId, $command, $cpuMs, $tokensUsed, $costUsd);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function commandAllowed(string $command, string $tier): bool
    {
        $allowed = self::TIER_COMMANDS[$tier] ?? [];
        if (in_array('*', $allowed, true)) { return true; }
        // Pro: allow all except brain-export commands
        if (in_array('*_except_brain', $allowed, true)) {
            return !in_array($command, ['evolve:brain-export', 'evolve:brain-import', 'evolve:twin'], true);
        }
        return in_array($command, $allowed, true);
    }

    private static function upgradeHint(string $command): string
    {
        foreach (self::TIER_COMMANDS as $tier => $cmds) {
            if (in_array('*', $cmds, true) || in_array($command, $cmds, true)) {
                return $tier;
            }
        }
        return 'enterprise';
    }

    private static function resolveTier(Config $config): string
    {
        // First: read from live GitHub vault via licence key
        // The tier is embedded in the licence entry: $entry['tier']
        // LicenseService doesn't expose it directly, so we re-read the local cache.
        $cacheFile = self::cachePath($config);
        if (is_readable($cacheFile)) {
            $data = json_decode((string)file_get_contents($cacheFile), true);
            $tier = trim((string)($data['tier'] ?? ''));
            if ($tier !== '') { return $tier; }
        }

        // Fallback: evolution.json gateway.default_tier
        $gwConfig = $config->get('evolution.gateway', []);
        return (string)(is_array($gwConfig) ? ($gwConfig['default_tier'] ?? 'starter') : 'starter');
    }

    private static function tenantId(Config $config): string
    {
        $key = trim((string)(getenv('LICENSE_KEY') ?: ($config->get('evolution.license.key', '') ?? '')));
        return $key !== '' ? hash('sha256', $key) : 'anonymous';
    }

    private static function cachePath(Config $config): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return $base . '/data/framework/license_cache.json';
    }

    private static function estimateCost(int $tokens): float
    {
        // $0.0002 per 1000 tokens (gpt-4o-mini average)
        return round($tokens / 1_000_000 * 0.20, 6);
    }

    /**
     * @return array{ok: bool, reason: string, tenant_id: string, tier: string, gateway_active: bool}
     */
    private static function pass(string $tier, string $tenantId = '', bool $gatewayActive = false): array
    {
        return ['ok' => true, 'reason' => '', 'tenant_id' => $tenantId, 'tier' => $tier, 'gateway_active' => $gatewayActive];
    }

    /**
     * @return array{ok: bool, reason: string, tenant_id: string, tier: string, gateway_active: bool}
     */
    private static function deny(string $reason, string $tenantId = '', string $tier = ''): array
    {
        EvolutionLogger::log('gateway', 'denied', ['reason' => $reason, 'tenant' => substr($tenantId, 0, 12)]);
        return ['ok' => false, 'reason' => $reason, 'tenant_id' => $tenantId, 'tier' => $tier, 'gateway_active' => true];
    }
}
