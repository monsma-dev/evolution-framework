<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Finds CSS rules in architect-overrides.css whose selectors do not appear in Twig templates (lean pipeline).
 */
final class LeanAssetAuditService
{
    /**
     * @return array{ok: bool, orphan_selectors: list<string>, scanned_twigs: int, css_rules: int}
     */
    public function analyze(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $fe = is_array($evo) ? ($evo['frontend_patches'] ?? []) : [];
        $rel = is_array($fe) ? (string) ($fe['css_file'] ?? 'data/evolution/architect-overrides.css') : 'data/evolution/architect-overrides.css';
        $cssPath = BASE_PATH . '/' . ltrim($rel, '/');
        if (!is_file($cssPath)) {
            return ['ok' => true, 'orphan_selectors' => [], 'scanned_twigs' => 0, 'css_rules' => 0];
        }
        $css = (string) file_get_contents($cssPath);
        $selectors = $this->extractSelectors($css);
        $twigRoots = [BASE_PATH . '/resources/views'];
        $haystack = '';
        $twigCount = 0;
        foreach ($twigRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!$f->isFile() || !str_ends_with($f->getFilename(), '.twig')) {
                    continue;
                }
                $twigCount++;
                $haystack .= "\n" . (string) file_get_contents($f->getPathname());
            }
        }
        $haystack = strtolower($haystack);
        $orphans = [];
        foreach ($selectors as $sel) {
            $token = $this->selectorSearchToken($sel);
            if ($token === '') {
                continue;
            }
            if (!str_contains($haystack, strtolower($token))) {
                $orphans[] = $sel;
            }
        }
        $orphans = array_values(array_unique(array_slice($orphans, 0, 40)));

        return [
            'ok' => true,
            'orphan_selectors' => $orphans,
            'scanned_twigs' => $twigCount,
            'css_rules' => count($selectors),
        ];
    }

    public function promptSection(Config $config): string
    {
        $lean = $config->get('evolution.lean_assets', []);
        if (!is_array($lean) || !filter_var($lean['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $a = $this->analyze($config);
        if ($a['orphan_selectors'] === []) {
            return "\n\nLEAN_ASSETS: geen duidelijke orphan-selectors gevonden in architect-overrides vs Twig (of CSS leeg).";
        }
        $lines = ["\n\nLEAN_ASSETS (CSS-selectors mogelijk ongebruikt in Twig — overweeg purge):"];
        foreach (array_slice($a['orphan_selectors'], 0, 12) as $o) {
            $lines[] = '  - ' . $o;
        }
        $lines[] = 'Gescand: ' . $a['scanned_twigs'] . ' twig-bestanden, ' . $a['css_rules'] . ' CSS-regels geanalyseerd.';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function extractSelectors(string $css): array
    {
        $out = [];
        // strip comments
        $css = preg_replace('#/\*[\s\S]*?\*/#', '', $css) ?? $css;
        // split on } to approximate rules
        $parts = preg_split('/\}/', $css) ?: [];
        foreach ($parts as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || !str_contains($chunk, '{')) {
                continue;
            }
            [$selList] = explode('{', $chunk, 2);
            $selList = trim((string) $selList);
            foreach (preg_split('/\s*,\s*/', $selList) ?: [] as $one) {
                $one = trim($one);
                if ($one !== '' && strlen($one) < 120) {
                    $out[] = $one;
                }
            }
        }

        return $out;
    }

    private function selectorSearchToken(string $selector): string
    {
        $s = trim($selector);
        if (preg_match('/^\.([a-zA-Z0-9_-]+)/', $s, $m)) {
            return $m[1];
        }
        if (preg_match('/^#([a-zA-Z0-9_-]+)/', $s, $m)) {
            return $m[1];
        }

        return '';
    }
}
