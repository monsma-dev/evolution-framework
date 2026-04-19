<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Renders responsive &lt;picture&gt; / &lt;img&gt; with lazy-loading for local public assets,
 * optional Cloudinary URL transformation, and WebP/AVIF when sibling files exist.
 */
final class EvolutionImageOptimizedHelper
{
    /**
     * @param array<string, string> $extraAttrs name => value (escaped by caller for non-simple values)
     */
    public static function render(
        Container $container,
        string $src,
        string $alt = '',
        string $className = '',
        array $extraAttrs = []
    ): string {
        $cfg = $container->get('config');
        if (!$cfg instanceof Config) {
            return self::fallbackImg($src, $alt, $className, $extraAttrs);
        }

        $evo = $cfg->get('evolution.image_delivery', []);
        if (is_array($evo) && !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return self::fallbackImg($src, $alt, $className, $extraAttrs);
        }

        $src = trim($src);
        if ($src === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $src) === 1) {
            return self::renderRemote($cfg, $src, $alt, $className, $extraAttrs, $evo);
        }

        $normalized = self::normalizeLocalPath($src);
        if ($normalized === null) {
            return '';
        }

        if (!self::isAllowedLocalPath($cfg, $normalized)) {
            return '';
        }

        $publicRoot = BASE_PATH . '/web';
        $abs = $publicRoot . $normalized;
        $absReal = realpath($abs);
        $rootReal = realpath($publicRoot);
        if ($rootReal === false || $absReal === false || !str_starts_with($absReal, $rootReal)) {
            return '';
        }

        $baseUrl = rtrim((string) $cfg->get('site.url', ''), '/');
        $pubUrl = $baseUrl . str_replace('\\', '/', $normalized);

        $ext = strtolower(pathinfo($absReal, PATHINFO_EXTENSION));
        $stem = pathinfo($absReal, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR . pathinfo($absReal, PATHINFO_FILENAME);
        $webp = $stem . '.webp';
        $avif = $stem . '.avif';
        $hasWebp = is_file($webp);
        $hasAvif = is_file($avif);

        $webpUrl = $hasWebp ? ($baseUrl . str_replace('\\', '/', substr($webp, strlen($publicRoot)))) : null;
        $avifUrl = $hasAvif ? ($baseUrl . str_replace('\\', '/', substr($avif, strlen($publicRoot)))) : null;

        $altEsc = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
        $classEsc = $className !== '' ? ' class="' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '"' : '';
        $extra = self::buildExtraAttrs($extraAttrs);

        if ($hasAvif || $hasWebp) {
            $parts = ['<picture>'];
            if ($hasAvif && $avifUrl !== null) {
                $parts[] = '<source type="image/avif" srcset="' . htmlspecialchars($avifUrl, ENT_QUOTES, 'UTF-8') . '">';
            }
            if ($hasWebp && $webpUrl !== null) {
                $parts[] = '<source type="image/webp" srcset="' . htmlspecialchars($webpUrl, ENT_QUOTES, 'UTF-8') . '">';
            }
            $parts[] = '<img src="' . htmlspecialchars($pubUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $altEsc . '"'
                . $classEsc
                . ' loading="lazy" decoding="async"' . $extra . '>';
            $parts[] = '</picture>';

            return implode('', $parts);
        }

        return '<img src="' . htmlspecialchars($pubUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $altEsc . '"'
            . $classEsc
            . ' loading="lazy" decoding="async"' . $extra . '>';
    }

    /**
     * @param array<string, mixed>|false $evo
     * @param array<string, string> $extraAttrs
     */
    private static function renderRemote(Config $cfg, string $url, string $alt, string $className, array $extraAttrs, array|false $evo): string
    {
        $evo = is_array($evo) ? $evo : [];
        $cloudName = trim((string) ($evo['cloudinary_cloud_name'] ?? ''));
        if ($cloudName !== '' && str_contains($url, 'res.cloudinary.com') && str_contains($url, '/upload/')) {
            if (!str_contains($url, '/upload/f_')) {
                $url = preg_replace('#/upload/#', '/upload/f_auto,q_auto,fl_progressive/', $url, 1) ?? $url;
            }
        }

        $altEsc = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
        $classEsc = $className !== '' ? ' class="' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '"' : '';
        $extra = self::buildExtraAttrs($extraAttrs);

        return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . $altEsc . '"'
            . $classEsc
            . ' loading="lazy" decoding="async"' . $extra . '>';
    }

    /**
     * @param array<string, string> $extraAttrs
     */
    private static function fallbackImg(string $src, string $alt, string $className, array $extraAttrs): string
    {
        $altEsc = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
        $classEsc = $className !== '' ? ' class="' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '"' : '';
        $extra = self::buildExtraAttrs($extraAttrs);

        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $altEsc . '"'
            . $classEsc
            . ' loading="lazy" decoding="async"' . $extra . '>';
    }

    /**
     * @param array<string, string> $extraAttrs
     */
    private static function buildExtraAttrs(array $extraAttrs): string
    {
        $out = '';
        foreach ($extraAttrs as $k => $v) {
            $k = trim((string) $k);
            if ($k === '' || preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.-]*$/', $k) !== 1) {
                continue;
            }
            $out .= ' ' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $out;
    }

    private static function normalizeLocalPath(string $src): ?string
    {
        $src = str_replace('\\', '/', trim($src));
        if (str_contains($src, '..')) {
            return null;
        }
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return null;
        }
        if (!str_starts_with($src, '/')) {
            $src = '/' . $src;
        }

        return $src;
    }

    private static function isAllowedLocalPath(Config $cfg, string $normalizedPath): bool
    {
        $evo = $cfg->get('evolution.image_delivery', []);
        $prefixes = is_array($evo) ? ($evo['allowed_path_prefixes'] ?? null) : null;
        if (!is_array($prefixes) || $prefixes === []) {
            $prefixes = ['/assets/', '/storage/'];
        }
        foreach ($prefixes as $p) {
            $p = (string) $p;
            if ($p !== '' && str_starts_with($normalizedPath, $p)) {
                return true;
            }
        }

        return false;
    }
}
