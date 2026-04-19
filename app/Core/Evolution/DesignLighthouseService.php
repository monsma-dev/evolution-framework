<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * UX Lab: na Figma-pull WCAG-contrast (AA) op geëxtraheerde kleuren; optioneel Lighthouse-CLI (later).
 * Geen headless Chrome verplicht — PHP-heuristiek als eerste gate vóór "Swap naar live".
 */
final class DesignLighthouseService
{
    public const LAST_SCAN = 'storage/evolution/design_lab/last_scan.json';

    public static function isEnabled(Config $config): bool
    {
        $dl = $config->get('evolution.design_lab', []);

        return is_array($dl) && filter_var($dl['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array{ok: bool, summary: string, issues: list<array{severity: string, code: string, message: string}>, contrast_samples: list<array<string, mixed>>}
     */
    public static function scanCssFile(Container $container, string $absolutePath): array
    {
        $cfg = $container->get('config');
        $minAa = 4.5;
        $dl = $cfg->get('evolution.design_lab', []);
        if (is_array($dl)) {
            $minAa = max(3.0, min(21.0, (float) ($dl['min_contrast_aa'] ?? 4.5)));
        }

        if (!is_file($absolutePath)) {
            return [
                'ok' => true,
                'summary' => 'Geen CSS-bestand; scan overgeslagen.',
                'issues' => [],
                'contrast_samples' => [],
            ];
        }

        $css = (string) @file_get_contents($absolutePath);
        $hexes = [];
        if (preg_match_all('/#([0-9a-fA-F]{3,8})\b/', $css, $m)) {
            foreach ($m[0] as $h) {
                $hexes[self::normalizeHex($h)] = true;
            }
        }
        $hexList = array_keys($hexes);
        $issues = [];
        $samples = [];

        $backgrounds = ['#ffffff', '#000000', '#f3f4f6'];
        foreach (array_slice($hexList, 0, 12) as $fg) {
            $best = 0.0;
            $bestBg = '#ffffff';
            foreach ($backgrounds as $bg) {
                $ratio = self::contrastRatio($fg, $bg);
                if ($ratio > $best) {
                    $best = $ratio;
                    $bestBg = $bg;
                }
            }
            $samples[] = ['fg' => $fg, 'best_bg' => $bestBg, 'best_ratio' => round($best, 2)];
            if ($best < $minAa && $best > 1.0) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'contrast_aa',
                    'message' => sprintf(
                        'Slechtste contrast voor %s is %.2f:1 op %s (WCAG AA ≥ %.1f:1 voor body UI-tekst).',
                        $fg,
                        $best,
                        $bestBg,
                        $minAa
                    ),
                ];
            }
        }

        $summary = $issues === []
            ? sprintf('Design Lab: %d kleuren gecontroleerd — geen duidelijke WCAG AA contrast-waarschuwingen (simpele samples).', count($hexList))
            : sprintf('Design Lab: %d contrast-waarschuwing(en) — review voor live swap.', count($issues));

        return [
            'ok' => true,
            'summary' => $summary,
            'issues' => $issues,
            'contrast_samples' => array_slice($samples, 0, 24),
        ];
    }

    public static function scanAfterPull(Container $container, string $absoluteCssPath): array
    {
        if (!self::isEnabled($container->get('config'))) {
            return [];
        }
        $result = self::scanCssFile($container, $absoluteCssPath);
        $dir = dirname(BASE_PATH . '/' . self::LAST_SCAN);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = array_merge($result, ['ts' => gmdate('c'), 'source' => $absoluteCssPath]);
        @file_put_contents(
            BASE_PATH . '/' . self::LAST_SCAN,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
        EvolutionLogger::log('design_lab', 'scan', ['issues' => count($result['issues'] ?? [])]);

        return $result;
    }

    private static function normalizeHex(string $hex): string
    {
        $hex = trim($hex);
        if ($hex[0] === '#') {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . strtolower($hex);
    }

    /**
     * @return array{0: float, 1: float, 2: float} 0–1 RGB
     */
    private static function hexToRgb(string $hex): array
    {
        $h = self::normalizeHex($hex);
        $h = ltrim($h, '#');
        $r = hexdec(substr($h, 0, 2)) / 255;
        $g = hexdec(substr($h, 2, 2)) / 255;
        $b = hexdec(substr($h, 4, 2)) / 255;

        return [$r, $g, $b];
    }

    private static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $f = static function (float $c): float {
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        $R = $f($r);
        $G = $f($g);
        $B = $f($b);

        return 0.2126 * $R + 0.7152 * $G + 0.0722 * $B;
    }

    private static function contrastRatio(string $hex1, string $hex2): float
    {
        $l1 = self::relativeLuminance($hex1);
        $l2 = self::relativeLuminance($hex2);
        $L1 = max($l1, $l2);
        $L2 = min($l1, $l2);

        return ($L1 + 0.05) / ($L2 + 0.05);
    }
}
