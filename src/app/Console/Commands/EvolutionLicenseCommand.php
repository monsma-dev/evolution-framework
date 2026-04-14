<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Config;
use App\Core\Evolution\EvolutionLicenseService;

/**
 * evolve:license — License Key Management
 *
 * Usage:
 *   php ai_bridge.php evolve:license generate --tier=pro
 *   php ai_bridge.php evolve:license generate --tier=starter --count=10
 *   php ai_bridge.php evolve:license list
 *   php ai_bridge.php evolve:license status
 *   php ai_bridge.php evolve:license revoke EVO-PRO1-XXXX-XXXX-XXXX
 */
final class EvolutionLicenseCommand
{
    public function __construct(private readonly Config $config) {}

    /** @param list<string> $args */
    public function execute(array $args = []): int
    {
        $sub = strtolower(trim((string)($args[0] ?? 'status')));

        return match ($sub) {
            'generate' => $this->generate($args),
            'list'     => $this->listKeys(),
            'status'   => $this->showStatus(),
            'revoke'   => $this->revoke($args),
            'activate' => $this->activateLocal($args),
            default    => $this->usage(),
        };
    }

    // ── Sub-commands ──────────────────────────────────────────────────────────

    /** @param list<string> $args */
    private function generate(array $args): int
    {
        $tier  = 'pro';
        $count = 1;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--tier=')) {
                $tier = substr($arg, 7);
            }
            if (str_starts_with($arg, '--count=')) {
                $count = max(1, min(100, (int)substr($arg, 8)));
            }
        }

        $svc = new EvolutionLicenseService($this->config);

        echo str_repeat('─', 65) . "\n";
        echo sprintf("  Generating %d × %s license key(s)\n", $count, strtoupper($tier));
        echo str_repeat('─', 65) . "\n";

        try {
            $keys = $svc->generate($tier, $count);
            $def  = EvolutionLicenseService::tierDefinitions()[$tier] ?? [];
            $exp  = date('Ym', strtotime('+12 months'));
            $expLabel = substr($exp, 0, 4) . '-' . substr($exp, 4, 2);
            foreach ($keys as $key) {
                echo sprintf(
                    "  %s  [%s · $%d/yr]  expires %s\n",
                    $key,
                    $def['name'] ?? $tier,
                    (int)($def['price_usd'] ?? 0),
                    $expLabel
                );
            }
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
            return 1;
        }

        echo str_repeat('─', 65) . "\n";
        echo "  Keys written to storage/evolution/license_registry.jsonl\n";
        echo "  Sell via: evolution-ai.dev/buy\n";

        return 0;
    }

    private function listKeys(): int
    {
        $svc  = new EvolutionLicenseService($this->config);
        $keys = $svc->listRegistry();

        if (empty($keys)) {
            echo "No keys in registry. Generate with: evolve:license generate --tier=pro\n";
            return 0;
        }

        echo str_repeat('─', 80) . "\n";
        echo sprintf("%-38s %-12s %-10s %-8s %s\n", 'KEY', 'TIER', 'EXPIRES', 'STATUS', 'ACTIVATED');
        echo str_repeat('─', 80) . "\n";

        foreach ($keys as $k) {
            $status = ($k['revoked'] ?? false) ? 'REVOKED'
                : (($k['activated'] ?? false) ? 'ACTIVE' : 'UNUSED');
            echo sprintf(
                "%-38s %-12s %-10s %-8s %s\n",
                (string)($k['key'] ?? ''),
                strtoupper((string)($k['tier'] ?? '')),
                (string)($k['expiry_ym'] ?? ''),
                $status,
                (string)($k['activated_at'] ?? '–')
            );
        }

        echo str_repeat('─', 80) . "\n";
        echo count($keys) . " key(s) total.\n";

        return 0;
    }

    private function showStatus(): int
    {
        $svc    = new EvolutionLicenseService($this->config);
        $status = $svc->status();

        echo "\n  Evolution License Status\n";
        echo str_repeat('─', 40) . "\n";
        echo "  Tier:       " . strtoupper($status['tier']) . " (" . $status['name'] . ")\n";
        echo "  Active:     " . ($status['active'] ? 'YES' : 'NO — free tier') . "\n";

        if ($status['key']) {
            echo "  Key:        " . $status['key'] . "\n";
        }
        if ($status['activated_at'] ?? null) {
            echo "  Activated:  " . $status['activated_at'] . "\n";
        }

        $features = (array)($status['features'] ?? []);
        echo "\n  Features:\n";
        foreach (['arena', 'agents', 'swarm', 'brain_exports', 'sdk', 'white_label'] as $f) {
            $on = (bool)($features[$f] ?? false);
            echo "  " . ($on ? '✓' : '○') . " " . str_pad($f, 14) . " " . ($on ? 'ENABLED' : 'locked') . "\n";
        }
        echo "  Skill slots: " . ($features['skill_slots'] ?? '?') . "\n";
        echo str_repeat('─', 40) . "\n";

        return 0;
    }

    /** @param list<string> $args */
    private function revoke(array $args): int
    {
        $key = trim((string)($args[1] ?? ''));
        if ($key === '') {
            echo "Usage: evolve:license revoke EVO-PRO1-XXXX-XXXX-XXXX\n";
            return 1;
        }

        $svc = new EvolutionLicenseService($this->config);
        if ($svc->revoke($key)) {
            echo "✓ Key revoked: {$key}\n";
            return 0;
        }

        echo "Key not found in registry: {$key}\n";
        return 1;
    }

    /** @param list<string> $args */
    private function activateLocal(array $args): int
    {
        $key = trim((string)($args[1] ?? ''));
        if ($key === '') {
            echo "Usage: evolve:license activate EVO-PRO1-XXXX-XXXX-XXXX\n";
            return 1;
        }

        $svc    = new EvolutionLicenseService($this->config);
        $result = $svc->activate($key);

        if (!$result['ok']) {
            echo "❌ Activation failed: " . ($result['error'] ?? 'unknown error') . "\n";
            return 1;
        }

        echo "\n" . ($result['message'] ?? '✓ Activated') . "\n";
        echo "  Tier:   " . strtoupper((string)($result['tier'] ?? '')) . "\n";
        echo "  Skills: " . ($result['features']['skill_slots'] ?? '?') . " slots unlocked\n";
        echo "  Arena:  " . (($result['features']['arena'] ?? false) ? 'ENABLED' : 'locked') . "\n";
        echo "  Agents: " . (($result['features']['agents'] ?? false) ? 'ENABLED' : 'locked') . "\n\n";

        return 0;
    }

    private function usage(): int
    {
        echo <<<USAGE

evolve:license — Evolution License Management

Commands:
  generate [--tier=pro|starter|enterprise] [--count=N]   Generate license keys
  list                                                     Show all registry keys
  status                                                   Show current activation
  revoke <key>                                             Revoke a key
  activate <key>                                           Activate locally (CLI)

Examples:
  php ai_bridge.php evolve:license generate --tier=pro
  php ai_bridge.php evolve:license generate --tier=starter --count=50
  php ai_bridge.php evolve:license status
  php ai_bridge.php evolve:license revoke EVO-PRO1-XXXX-XXXX-XXXX

USAGE;
        return 0;
    }
}
