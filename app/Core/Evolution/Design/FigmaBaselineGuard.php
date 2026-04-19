<?php

declare(strict_types=1);

namespace App\Core\Evolution\Design;

use App\Core\Config;

/**
 * Validates Figma URLs against evolution.figma baseline (HTML-to-Figma single source of truth).
 */
final class FigmaBaselineGuard
{
    /**
     * Normalize node id to "123:456" form.
     */
    public static function normalizeNodeId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/(\d+)[:\-](\d+)/', $id, $m)) {
            return $m[1] . ':' . $m[2];
        }

        return $id;
    }

    /**
     * @return array{baseline_url: string, baseline_node_id: string, strict_baseline: bool}
     */
    public static function baselineFromConfig(Config $config): array
    {
        $url = trim((string) $config->get('evolution.figma.baseline_url', ''));
        $node = self::normalizeNodeId(trim((string) $config->get('evolution.figma.baseline_node_id', '')));
        $strict = filter_var($config->get('evolution.figma.strict_baseline', false), FILTER_VALIDATE_BOOL);

        return [
            'baseline_url'      => $url,
            'baseline_node_id'  => $node,
            'strict_baseline'   => $strict,
        ];
    }

    /**
     * When a baseline URL is configured, the scanned URL must target the same Figma file.
     */
    public static function validateFileMatchesBaseline(Config $config, string $figmaUrl): ?string
    {
        $b = self::baselineFromConfig($config);
        if ($b['baseline_url'] === '') {
            return null;
        }
        $want = FigmaUrlParser::parseFileKeyAndNodeId($b['baseline_url']);
        $got  = FigmaUrlParser::parseFileKeyAndNodeId($figmaUrl);
        if ($want === null || $got === null) {
            return 'Could not parse Figma URL for baseline file validation.';
        }
        if ($want['file_key'] !== $got['file_key']) {
            return 'Figma file must match evolution.figma.baseline_url / FIGMA_BASELINE_URL (baseline workflow).';
        }

        return null;
    }

    /**
     * Report whether the selected node id matches the configured baseline root (for scan output).
     *
     * @return array{baseline_node_id: string, selected_node_id: string, matches_baseline_root: bool}
     */
    public static function compareNodeToBaseline(Config $config, string $figmaUrl): array
    {
        $b = self::baselineFromConfig($config);
        $parsed = FigmaUrlParser::parseFileKeyAndNodeId($figmaUrl);

        $selected = $parsed !== null ? self::normalizeNodeId($parsed['node_id']) : '';

        return [
            'baseline_node_id'       => $b['baseline_node_id'],
            'selected_node_id'       => $selected,
            'matches_baseline_root'  => $b['baseline_node_id'] !== ''
                && $selected !== ''
                && $selected === $b['baseline_node_id'],
        ];
    }

    /**
     * Strict mode: refuse codegen on the baseline root — only new/changed child layers should be translated.
     */
    public static function validateStrictNotBaselineRoot(Config $config, string $figmaUrl): ?string
    {
        $b = self::baselineFromConfig($config);
        if (!$b['strict_baseline']) {
            return null;
        }
        if ($b['baseline_node_id'] === '') {
            return 'Strict baseline mode requires evolution.figma.baseline_node_id or FIGMA_BASELINE_NODE_ID.';
        }
        $parsed = FigmaUrlParser::parseFileKeyAndNodeId($figmaUrl);
        if ($parsed === null) {
            return 'Could not parse Figma URL.';
        }
        $req = self::normalizeNodeId($parsed['node_id']);
        if ($req === '') {
            return 'Strict baseline: Figma URL must include node-id=…';
        }
        if ($req === $b['baseline_node_id']) {
            return 'Strict baseline mode: do not generate code for the baseline root frame. Select a child node (new layer) under the baseline and use that URL.';
        }

        return null;
    }

    /**
     * Extra instructions for DesignAgent LLM when a baseline is configured.
     */
    public static function designAgentPromptSuffix(Config $config, string $figmaUrl): string
    {
        $lines = [];
        $cmp = self::compareNodeToBaseline($config, $figmaUrl);
        if ($cmp['baseline_node_id'] !== '') {
            $sel = $cmp['selected_node_id'] !== '' ? $cmp['selected_node_id'] : '(missing in URL)';
            $lines[] = 'Baseline root node_id (config): ' . $cmp['baseline_node_id'] . '. Selected node_id: ' . $sel . '.';
            if ($cmp['matches_baseline_root']) {
                $lines[] = 'You are on the baseline root; preserve imported structure; only adjust what the prompt requires.';
            } else {
                $lines[] = 'Selected node differs from baseline root — translate only this subtree (diff workflow).';
            }
        }
        $strict = self::strictPromptAddendum($config);
        if ($strict !== '') {
            $lines[] = $strict;
        }

        return $lines === [] ? '' : "\n\n" . implode("\n", $lines);
    }

    /**
     * User prompt addendum for strict baseline (diff-only, no overwriting imported components).
     */
    public static function strictPromptAddendum(Config $config): string
    {
        $b = self::baselineFromConfig($config);
        if (!$b['strict_baseline']) {
            return '';
        }

        return <<<'TXT'

STRICT BASELINE (Figma-First):
- Do NOT replace or rewrite Twig for layers that already exist in the baseline import.
- Only emit markup for NEW nodes / layers in this selection; treat existing baseline components as read-only reference.
- Reuse Tailwind utility classes consistent with the baseline import (same design tokens / class vocabulary).
TXT;
    }

    /**
     * Short system addendum for evolve:figma-build JSON codegen.
     */
    public static function strictSystemAddendum(Config $config): string
    {
        $b = self::baselineFromConfig($config);
        if (!$b['strict_baseline']) {
            return '';
        }

        return "\n\nSTRICT BASELINE: Output Twig/controller only for NEW or CHANGED elements implied by this node; do not overwrite existing baseline components. Use baseline Tailwind classes for new elements.";
    }
}
