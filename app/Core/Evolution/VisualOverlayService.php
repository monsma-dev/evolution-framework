<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Figma vs live pixel diff — builds on VisualCaptureService + stored Figma exports.
 * Full pixel-diff pipeline is optional; this holds config and future hooks.
 */
final class VisualOverlayService
{
    /**
     * @return array{ok: bool, note: string}
     */
    public static function describe(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $tb = is_array($evo) ? ($evo['toolbox'] ?? []) : [];
        $on = is_array($tb) && filter_var($tb['visual_diff_enabled'] ?? false, FILTER_VALIDATE_BOOL);

        return [
            'ok' => true,
            'note' => $on
                ? 'Visual diff: use design_lab + Playwright captures; compare with pixelmatch in tooling when enabled.'
                : 'Set evolution.toolbox.visual_diff_enabled and configure figma_bridge for overlay workflows.',
        ];
    }
}
