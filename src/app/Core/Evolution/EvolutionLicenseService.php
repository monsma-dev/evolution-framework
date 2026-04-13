<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Evolution License Service — the Closed-Source Monetisation Layer
 *
 * KEY FORMAT:  EVO-{TIER4}-{XXXX}-{XXXX}-{XXXX}
 * Examples:
 *   EVO-STR1-A1B2-C3D4-E5F6   ($9  Starter)
 *   EVO-PRO1-A1B2-C3D4-E5F6   ($49 Pro)
 *   EVO-ENT1-A1B2-C3D4-E5F6   ($199 Enterprise)
 *
 * Validation:
 *   HMAC-SHA256(signing_secret, "{tier}:{seat_id}:{expiry_ym}") → first 12 hex chars
 *   Key is permanently valid once activated on a machine (machine-bound optional).
 *
 * Tiers & Features:
 *   Starter  ($9)   — 15 core evolve:* skills, no agents, no Arena
 *   Pro      ($49)  — all 25 skills + 3 agents + Arena + Swarm Intelligence
 *   Enterprise($199)— everything + Brain exports + white-label SDK + custom agents
 */
final class EvolutionLicenseService
{
    private const LICENSE_FILE   = '/var/www/html/storage/evolution/license.json';
    private const REGISTRY_FILE  = '/var/www/html/storage/evolution/license_registry.jsonl';
    private const KEY_PREFIX     = 'EVO-';
    private const SIGNING_SECRET = 'evolution_sovereign_v1'; // overridden by config

    // ── Tier Definitions ─────────────────────────────────────────────────────

    public const TIER_FREE       = 'free';
    public const TIER_STARTER    = 'starter';
    public const TIER_PRO        = 'pro';
    public const TIER_ENTERPRISE = 'enterprise';

    private const TIER_MAP = [
        'STR' => self::TIER_STARTER,
        'PRO' => self::TIER_PRO,
        'ENT' => self::TIER_ENTERPRISE,
    ];

    /** @var array<string, array<string, mixed>> */
    private const TIERS = [
        self::TIER_FREE => [
            'name'          => 'Free',
            'price_usd'     => 0,
            'skill_slots'   => 5,
            'skills'        => ['evolve:analyse', 'evolve:status', 'evolve:usage', 'evolve:docs', 'evolve:vector-build'],
            'agents'        => false,
            'arena'         => false,
            'swarm'         => false,
            'brain_exports' => false,
            'sdk'           => false,
            'white_label'   => false,
        ],
        self::TIER_STARTER => [
            'name'          => 'Starter',
            'price_usd'     => 9,
            'skill_slots'   => 15,
            'skills'        => [
                'evolve:analyse', 'evolve:fix', 'evolve:test', 'evolve:review',
                'evolve:document', 'evolve:migrate', 'evolve:optimise', 'evolve:security',
                'evolve:refactor', 'evolve:dependencies', 'evolve:vector-build', 'evolve:docs',
                'evolve:status', 'evolve:skill', 'evolve:usage',
            ],
            'agents'        => false,
            'arena'         => false,
            'swarm'         => false,
            'brain_exports' => false,
            'sdk'           => false,
            'white_label'   => false,
        ],
        self::TIER_PRO => [
            'name'          => 'Pro',
            'price_usd'     => 49,
            'skill_slots'   => 25,
            'skills'        => 'all',
            'agents'        => true,
            'arena'         => true,
            'swarm'         => true,
            'brain_exports' => false,
            'sdk'           => true,
            'white_label'   => false,
        ],
        self::TIER_ENTERPRISE => [
            'name'          => 'Enterprise',
            'price_usd'     => 199,
            'skill_slots'   => 25,
            'skills'        => 'all',
            'agents'        => true,
            'arena'         => true,
            'swarm'         => true,
            'brain_exports' => true,
            'sdk'           => true,
            'white_label'   => true,
        ],
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public function __construct(private readonly Config $config) {}

    /**
     * Generate one or more license keys.
     * Call this from the admin command or Stripe webhook to issue keys.
     *
     * @param array<string, mixed> $meta  Extra metadata stored in registry (e.g. stripe_session)
     * @return list<string>               List of generated key strings
     */
    public function generate(string $tier, int $count = 1, array $meta = []): array
    {
        $tier = strtolower($tier);
        if (!isset(self::TIERS[$tier]) || $tier === self::TIER_FREE) {
            throw new \InvalidArgumentException("Invalid tier: {$tier}. Use starter|pro|enterprise.");
        }

        $tierCode = array_search($tier, self::TIER_MAP, true);
        if ($tierCode === false) {
            throw new \InvalidArgumentException("No tier code for: {$tier}");
        }

        $keys = [];
        for ($i = 0; $i < max(1, $count); $i++) {
            $seatId   = strtoupper(bin2hex(random_bytes(3)));     // 6 hex chars
            $expiryYm = date('Ym', strtotime('+12 months'));
            $hmac     = $this->computeHmac($tier, $seatId, $expiryYm);
            $segments = str_split($hmac, 4);

            $key = sprintf('EVO-%s%d-%s-%s-%s',
                $tierCode, 1,
                $segments[0], $segments[1], $segments[2]
            );

            $this->registerKey($key, $tier, $seatId, $expiryYm, $meta);
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Generate a single key (convenience wrapper for CLI compat).
     * @return array{key: string, tier: string, seat_id: string, expiry_ym: string}
     * @deprecated Use generate($tier, 1) instead
     */
    public function generateOne(string $tier): array
    {
        $keys = $this->generate($tier, 1);
        return ['key' => $keys[0], 'tier' => $tier, 'seat_id' => '', 'expiry_ym' => ''];
    }

    /**
     * Activate a license key on this machine.
     * Writes to storage/evolution/license.json.
     *
     * @return array{ok: bool, tier?: string, name?: string, features?: array<string, mixed>, error?: string}
     */
    public function activate(string $key): array
    {
        $key = strtoupper(trim($key));

        $parsed = $this->parseKey($key);
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'Invalid key format. Expected: EVO-{TIER}-XXXX-XXXX-XXXX'];
        }

        // Validate against registry
        $registryResult = $this->validateAgainstRegistry($key);
        if (!$registryResult['ok']) {
            // Fallback: HMAC-only validation (for offline use)
            if (!$this->validateHmac($parsed['tier'], $parsed['seat_id'], $parsed['expiry_ym'], $parsed['hmac'])) {
                return ['ok' => false, 'error' => $registryResult['error'] ?? 'Key not found. Purchase at evolution-ai.dev'];
            }
        }

        $tier     = $parsed['tier'];
        $features = self::TIERS[$tier];

        // Write local license
        $license = [
            'key'          => $key,
            'tier'         => $tier,
            'name'         => $features['name'],
            'features'     => $features,
            'machine_id'   => $this->machineId(),
            'activated_at' => date('c'),
            'expiry_ym'    => $parsed['expiry_ym'],
        ];

        $dir = dirname(self::LICENSE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(self::LICENSE_FILE, json_encode($license, JSON_PRETTY_PRINT), LOCK_EX);

        // Mark as activated in registry
        $this->markActivated($key);

        return [
            'ok'       => true,
            'tier'     => $tier,
            'name'     => $features['name'],
            'features' => $features,
            'message'  => "🔓 {$features['name']} activated — Welcome to the Sovereign Network.",
        ];
    }

    /**
     * Get current license status for this installation.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        if (!is_file(self::LICENSE_FILE)) {
            return [
                'tier'     => self::TIER_FREE,
                'name'     => 'Free',
                'features' => self::TIERS[self::TIER_FREE],
                'active'   => false,
                'key'      => null,
            ];
        }

        $license = json_decode((string)file_get_contents(self::LICENSE_FILE), true);
        if (!is_array($license)) {
            return $this->status(); // will hit the no-file branch via cleared data
        }

        $tier = (string)($license['tier'] ?? self::TIER_FREE);
        if (!isset(self::TIERS[$tier])) {
            $tier = self::TIER_FREE;
        }

        return [
            'tier'         => $tier,
            'name'         => self::TIERS[$tier]['name'],
            'features'     => self::TIERS[$tier],
            'active'       => true,
            'key'          => $this->maskKey((string)($license['key'] ?? '')),
            'activated_at' => $license['activated_at'] ?? null,
        ];
    }

    /**
     * Check if a specific feature is available under the current license.
     */
    public function can(string $feature): bool
    {
        $status = $this->status();
        $features = (array)($status['features'] ?? []);

        if ($feature === 'arena')         return (bool)($features['arena'] ?? false);
        if ($feature === 'agents')        return (bool)($features['agents'] ?? false);
        if ($feature === 'swarm')         return (bool)($features['swarm'] ?? false);
        if ($feature === 'brain_exports') return (bool)($features['brain_exports'] ?? false);
        if ($feature === 'sdk')           return (bool)($features['sdk'] ?? false);
        if ($feature === 'white_label')   return (bool)($features['white_label'] ?? false);

        // Check skill access
        $skills = $features['skills'] ?? [];
        if ($skills === 'all') {
            return true;
        }
        return in_array($feature, (array)$skills, true);
    }

    /**
     * Detect tier from key format WITHOUT full validation.
     * Used for real-time UI feedback as user types.
     *
     * @return array{tier: string, name: string, price: int}|null
     */
    public function detectTier(string $key): ?array
    {
        $key = strtoupper(trim($key));
        if (!str_starts_with($key, 'EVO-')) {
            return null;
        }

        $parts = explode('-', $key);
        if (count($parts) < 2) {
            return null;
        }

        $tierCode = substr($parts[1], 0, 3);
        $tier     = self::TIER_MAP[$tierCode] ?? null;

        if ($tier === null || !isset(self::TIERS[$tier])) {
            return null;
        }

        $def = self::TIERS[$tier];
        return [
            'tier'  => $tier,
            'name'  => (string)$def['name'],
            'price' => (int)$def['price_usd'],
        ];
    }

    /** List all registered keys (admin only). @return list<array<string, mixed>> */
    public function listRegistry(): array
    {
        if (!is_file(self::REGISTRY_FILE)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string)file_get_contents(self::REGISTRY_FILE)));
        return array_values(array_filter(array_map(static function (string $l): ?array {
            $d = json_decode($l, true);
            return is_array($d) ? $d : null;
        }, $lines)));
    }

    /** Revoke a key (marks it revoked in registry). */
    public function revoke(string $key): bool
    {
        if (!is_file(self::REGISTRY_FILE)) {
            return false;
        }
        $lines   = array_filter(explode("\n", (string)file_get_contents(self::REGISTRY_FILE)));
        $updated = [];
        $found   = false;
        foreach ($lines as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && strtoupper((string)($d['key'] ?? '')) === strtoupper($key)) {
                $d['revoked']    = true;
                $d['revoked_at'] = date('c');
                $found           = true;
            }
            $updated[] = json_encode($d);
        }
        if ($found) {
            file_put_contents(self::REGISTRY_FILE, implode("\n", $updated), LOCK_EX);
        }
        return $found;
    }

    /** Static tier definitions for controllers/views. @return array<string, array<string, mixed>> */
    public static function tierDefinitions(): array
    {
        return self::TIERS;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @return array{tier: string, tier_code: string, seat_id: string, expiry_ym: string, hmac: string}|null
     */
    private function parseKey(string $key): ?array
    {
        // EVO-PRO1-XXXX-XXXX-XXXX
        if (!preg_match('/^EVO-([A-Z]{3})\d-([A-F0-9]{4})-([A-F0-9]{4})-([A-F0-9]{4})$/', $key, $m)) {
            return null;
        }

        $tierCode = $m[1];
        $tier     = self::TIER_MAP[$tierCode] ?? null;
        if ($tier === null) {
            return null;
        }

        $hmacPart = $m[2] . $m[3] . $m[4]; // 12 hex chars

        return [
            'tier'      => $tier,
            'tier_code' => $tierCode,
            'seat_id'   => $m[2],  // first 4 chars used as seat_id placeholder
            'expiry_ym' => '',     // expiry embedded differently if needed
            'hmac'      => $hmacPart,
        ];
    }

    private function computeHmac(string $tier, string $seatId, string $expiryYm): string
    {
        $secret  = (string)($this->config->get('evolution.license.signing_secret') ?? self::SIGNING_SECRET);
        $payload = strtolower("{$tier}:{$seatId}:{$expiryYm}");
        return strtoupper(substr(hash_hmac('sha256', $payload, $secret), 0, 12));
    }

    private function validateHmac(string $tier, string $seatId, string $expiryYm, string $hmac): bool
    {
        // For offline validation, check HMAC against all plausible expiry windows
        $secret = (string)($this->config->get('evolution.license.signing_secret') ?? self::SIGNING_SECRET);
        // Check current + next 24 months
        for ($i = 0; $i <= 24; $i++) {
            $ym       = date('Ym', strtotime("+{$i} months"));
            $payload  = strtolower("{$tier}:{$seatId}:{$ym}");
            $expected = strtoupper(substr(hash_hmac('sha256', $payload, $secret), 0, 12));
            if (hash_equals($expected, strtoupper($hmac))) {
                return true;
            }
        }
        return false;
    }

    /** @return array{ok: bool, error?: string} */
    private function validateAgainstRegistry(string $key): array
    {
        if (!is_file(self::REGISTRY_FILE)) {
            return ['ok' => false, 'error' => 'No registry found (first key on this machine — HMAC fallback active)'];
        }

        foreach ($this->listRegistry() as $entry) {
            if (strtoupper((string)($entry['key'] ?? '')) === strtoupper($key)) {
                if ($entry['revoked'] ?? false) {
                    return ['ok' => false, 'error' => 'This license key has been revoked.'];
                }
                return ['ok' => true];
            }
        }

        return ['ok' => false, 'error' => 'Key not found in registry.'];
    }

    /** @param array<string, mixed> $meta */
    private function registerKey(string $key, string $tier, string $seatId, string $expiryYm, array $meta = []): void
    {
        $dir = dirname(self::REGISTRY_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $entry = array_merge([
            'key'          => $key,
            'tier'         => $tier,
            'seat_id'      => $seatId,
            'expiry_ym'    => $expiryYm,
            'revoked'      => false,
            'activated'    => false,
            'activated_at' => null,
            'created_at'   => date('c'),
        ], $meta);
        file_put_contents(self::REGISTRY_FILE, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function markActivated(string $key): void
    {
        if (!is_file(self::REGISTRY_FILE)) {
            return;
        }
        $lines   = array_filter(explode("\n", (string)file_get_contents(self::REGISTRY_FILE)));
        $updated = [];
        foreach ($lines as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && strtoupper((string)($d['key'] ?? '')) === strtoupper($key)) {
                $d['activated']    = true;
                $d['activated_at'] = date('c');
            }
            $updated[] = json_encode($d);
        }
        file_put_contents(self::REGISTRY_FILE, implode("\n", $updated) . "\n", LOCK_EX);
    }

    private function machineId(): string
    {
        return hash('sha256', (string)gethostname());
    }

    private function maskKey(string $key): string
    {
        if (strlen($key) < 10) {
            return $key;
        }
        // EVO-PRO1-XXXX-XXXX-XXXX  →  EVO-PRO1-****-****-XXXX
        $parts = explode('-', $key);
        if (count($parts) === 5) {
            $parts[2] = '****';
            $parts[3] = '****';
        }
        return implode('-', $parts);
    }
}
