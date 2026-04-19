<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Future: XHProf / Excimer / SPX profiles for slow pages → top functions for Architect.
 */
final class EvolutionProfilerBridge
{
    /**
     * @return array{ok: bool, error?: string, hint?: string}
     */
    public static function status(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $tb = is_array($evo) ? ($evo['toolbox'] ?? []) : [];
        $on = is_array($tb) && filter_var($tb['profiler_enabled'] ?? false, FILTER_VALIDATE_BOOL);

        return [
            'ok' => true,
            'hint' => $on
                ? 'Profiler hook placeholders — install xhprof or use SPX and point evolution.toolbox.profiler_dump_dir.'
                : 'Enable evolution.toolbox.profiler_enabled when a profiler extension is available.',
        ];
    }
}
