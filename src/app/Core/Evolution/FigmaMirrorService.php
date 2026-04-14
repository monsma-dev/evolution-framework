<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;
use App\Support\Session\Session;

/**
 * Visual Copilot: na Figma-pull/webhook wordt CSS naar een mirror-bestand geschreven;
 * admin (optioneel publiek) laadt die via een no-cache stylesheet-URL — live preview zonder deploy.
 */
final class FigmaMirrorService
{
    public const MIRROR_CSS = 'storage/evolution/figma/mirror_inject.css';

    public static function afterSuccessfulPull(Container $container, array $pullResult): void
    {
        if (!($pullResult['ok'] ?? false)) {
            return;
        }
        $cfg = $container->get('config');
        $fb = $cfg->get('evolution.figma_bridge', []);
        if (!is_array($fb) || !filter_var($fb['mirror_enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return;
        }
        $css = (string) ($pullResult['suggested_css_append'] ?? '');
        if (trim($css) === '') {
            return;
        }

        $dir = dirname(BASE_PATH . '/' . self::MIRROR_CSS);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $banner = '/* FigmaMirror live — ' . gmdate('c') . " */\n";
        @file_put_contents(BASE_PATH . '/' . self::MIRROR_CSS, $banner . $css . "\n");
        EvolutionLogger::log('figma_mirror', 'injected', ['bytes' => strlen($css)]);
    }

    public static function clearMirrorFile(): void
    {
        $p = BASE_PATH . '/' . self::MIRROR_CSS;
        if (is_file($p)) {
            @unlink($p);
        }
        EvolutionLogger::log('figma_mirror', 'cleared', []);
    }

    /**
     * Stylesheet href for Twig (admin / optional public preview).
     */
    public static function resolveStylesheetHref(Container $container, string $template): ?string
    {
        $cfg = $container->get('config');
        if (!EvolutionFigmaService::isEnabled($cfg)) {
            return null;
        }
        $fb = $cfg->get('evolution.figma_bridge', []);
        if (!is_array($fb)) {
            return null;
        }

        $path = BASE_PATH . '/' . self::MIRROR_CSS;
        if (!is_file($path) || (int) @filesize($path) < 8) {
            return null;
        }

        $mirrorOn = filter_var($fb['mirror_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $preview = Session::get('figma_mirror_preview') === true
            || Session::get('figma_mirror_preview') === 1
            || Session::get('figma_mirror_preview') === '1';

        $adminTpl = str_contains($template, '/admin/');
        $publicTpl = str_contains($template, '/marketplace/') || str_contains($template, '/layouts/marketplace');

        if ($adminTpl && ($mirrorOn || $preview)) {
            return self::buildPublicUrl($cfg, $path);
        }
        if ($publicTpl
            && filter_var($fb['mirror_public'] ?? false, FILTER_VALIDATE_BOOL)
            && (Session::get('figma_mirror_public') === true || Session::get('figma_mirror_public') === '1')) {
            return self::buildPublicUrl($cfg, $path);
        }

        return null;
    }

    private static function buildPublicUrl(Config $config, string $mirrorAbsPath): string
    {
        $base = rtrim((string) $config->get('site.url', ''), '/');
        $v = is_file($mirrorAbsPath) ? (int) @filemtime($mirrorAbsPath) : time();

        return $base . '/api/v1/evolution/figma-mirror.css?v=' . $v;
    }
}
