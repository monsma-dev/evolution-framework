<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Placeholder for Docker-isolated patch trials (no network, no real .env). Wire Node/worker later.
 */
final class EvolutionSandboxService
{
    /**
     * @return array{ok: bool, skipped: bool, detail?: string}
     */
    public static function gatePatch(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $s = is_array($evo) ? ($evo['security_sandbox'] ?? []) : [];
        if (!is_array($s) || !filter_var($s['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'skipped' => true, 'detail' => 'security_sandbox disabled'];
        }

        return ['ok' => true, 'skipped' => true, 'detail' => 'sandbox execution not implemented — enable after worker hook'];
    }
}
