<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Optional immutable flag files (e.g. chattr +i on Linux). Off by default — requires root and breaks hot deploys if misused.
 */
final class KernelIntegrityLock
{
    public static function isEnabled(Config $config): bool
    {
        $evo = $config->get('evolution', []);
        $k = is_array($evo) ? ($evo['kernel_integrity'] ?? []) : [];

        return is_array($k) && filter_var($k['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return list<string>
     */
    public static function configuredPaths(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $k = is_array($evo) ? ($evo['kernel_integrity'] ?? []) : [];
        $paths = is_array($k) ? ($k['immutable_paths'] ?? []) : [];

        return is_array($paths) ? array_values(array_filter(array_map('strval', $paths))) : [];
    }
}
