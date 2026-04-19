<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Live Twig/CSS overrides under storage (shadow frontend).
 */
final class FrontendEvolutionService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, path?: string, error?: string}
     */
    public function writeTwigOverride(string $relativeTemplate, string $contents, int $actorUserId): array
    {
        $relativeTemplate = $this->normalizeTemplatePath($relativeTemplate);
        if ($relativeTemplate === null) {
            return ['ok' => false, 'error' => 'Invalid template path'];
        }

        $root = BASE_PATH . '/data/evolution/twig_overrides';
        $full = $root . '/' . $relativeTemplate;
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot create twig override directory'];
        }

        if (@file_put_contents($full, $contents) === false) {
            return ['ok' => false, 'error' => 'Cannot write twig override'];
        }

        EvolutionLogger::log('frontend', 'twig_override', [
            'template' => $relativeTemplate,
            'actor_user_id' => $actorUserId,
        ]);

        $cfg = $this->container->get('config');
        (new CodeDnaRegistry())->record($cfg, [
            'kind' => 'twig_shadow',
            'template' => $relativeTemplate,
            'path' => $full,
            'model' => 'ux',
        ]);

        return ['ok' => true, 'path' => $full];
    }

    /**
     * Appends CSS to the architect overrides file (loaded globally when present).
     *
     * @return array{ok: bool, path?: string, error?: string}
     */
    public function appendCss(string $cssBlock, int $actorUserId): array
    {
        $path = $this->cssFilePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot create css directory'];
        }

        $hdr = "\n/* Architect override " . gmdate('c') . " user={$actorUserId} */\n";
        $prev = is_file($path) ? (string)@file_get_contents($path) : '';
        $next = rtrim($prev) . $hdr . $cssBlock . "\n";
        if (@file_put_contents($path, $next) === false) {
            return ['ok' => false, 'error' => 'Cannot write CSS override'];
        }

        EvolutionLogger::log('frontend', 'css_append', ['actor_user_id' => $actorUserId]);

        $cfg = $this->container->get('config');
        (new CodeDnaRegistry())->record($cfg, [
            'kind' => 'css_shadow',
            'path' => $path,
            'model' => 'ux',
        ]);

        return ['ok' => true, 'path' => $path];
    }

    /**
     * Replace entire CSS override file (use with care).
     *
     * @return array{ok: bool, path?: string, error?: string}
     */
    public function replaceCss(string $fullCss, int $actorUserId): array
    {
        $path = $this->cssFilePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot create css directory'];
        }
        if (@file_put_contents($path, $fullCss) === false) {
            return ['ok' => false, 'error' => 'Cannot write CSS override'];
        }
        EvolutionLogger::log('frontend', 'css_replace', ['actor_user_id' => $actorUserId]);

        $cfg = $this->container->get('config');
        (new CodeDnaRegistry())->record($cfg, [
            'kind' => 'css_shadow',
            'path' => $path,
            'model' => 'ux',
        ]);

        return ['ok' => true, 'path' => $path];
    }

    public function cssPublicHref(): ?string
    {
        $path = $this->cssFilePath();
        if (!is_file($path)) {
            return null;
        }
        $config = $this->container->get('config');
        $base = rtrim((string)$config->get('site.url', ''), '/');
        $rel = $this->cssPublicRelativePath();

        return $base . '/' . $rel;
    }

    public function cssFilePath(): string
    {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        $rel = 'data/evolution/architect-overrides.css';
        if (is_array($evo)) {
            $fp = $evo['frontend_patches'] ?? [];
            if (is_array($fp)) {
                $r = trim((string)($fp['css_file'] ?? ''));
                if ($r !== '') {
                    $rel = $r;
                }
            }
        }
        if (str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rel) === 1) {
            return $rel;
        }

        return BASE_PATH . '/' . ltrim($rel, '/');
    }

    private function cssPublicRelativePath(): string
    {
        $full = $this->cssFilePath();
        $base = BASE_PATH . '/web/';
        if (str_starts_with($full, $base)) {
            return str_replace('\\', '/', substr($full, strlen($base)));
        }

        return 'storage/evolution/architect-overrides.css';
    }

    /**
     * Tailwind v4 design-token overrides (CSS variables). Loaded after main.css / architect overrides.
     */
    public function themeTokensPublicHref(): ?string
    {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        if (is_array($evo)) {
            $tt = $evo['theme_tokens'] ?? [];
            if (is_array($tt) && !filter_var($tt['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
                return null;
            }
        }

        $path = $this->themeTokensFilePath();
        if (!is_file($path)) {
            return null;
        }
        $base = rtrim((string) $config->get('site.url', ''), '/');
        $rel = $this->themeTokensPublicRelativePath();

        return $base . '/' . $rel;
    }

    public function themeTokensFilePath(): string
    {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        $rel = 'data/evolution/theme_overrides.css';
        if (is_array($evo)) {
            $tt = $evo['theme_tokens'] ?? [];
            if (is_array($tt)) {
                $r = trim((string) ($tt['css_file'] ?? ''));
                if ($r !== '') {
                    $rel = $r;
                }
            }
        }
        if (str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rel) === 1) {
            return $rel;
        }

        return BASE_PATH . '/' . ltrim($rel, '/');
    }

    /**
     * @return array{ok: bool, path?: string, error?: string}
     */
    public function appendThemeTokensCss(string $cssBlock, int $actorUserId): array
    {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        if (is_array($evo)) {
            $tt = $evo['theme_tokens'] ?? [];
            if (is_array($tt) && !filter_var($tt['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
                return ['ok' => false, 'error' => 'theme_tokens disabled'];
            }
        }

        $path = $this->themeTokensFilePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot create theme_tokens css directory'];
        }

        $hdr = "\n/* theme_tokens " . gmdate('c') . " user={$actorUserId} */\n";
        $prev = is_file($path) ? (string) @file_get_contents($path) : '';
        $next = rtrim($prev) . $hdr . $cssBlock . "\n";
        if (@file_put_contents($path, $next) === false) {
            return ['ok' => false, 'error' => 'Cannot write theme_overrides.css'];
        }

        EvolutionLogger::log('frontend', 'theme_tokens_append', ['actor_user_id' => $actorUserId]);

        (new CodeDnaRegistry())->record($config, [
            'kind' => 'theme_tokens_shadow',
            'path' => $path,
            'model' => 'ux',
        ]);

        return ['ok' => true, 'path' => $path];
    }

    private function themeTokensPublicRelativePath(): string
    {
        $full = $this->themeTokensFilePath();
        $base = BASE_PATH . '/web/';
        if (str_starts_with($full, $base)) {
            return str_replace('\\', '/', substr($full, strlen($base)));
        }

        return 'storage/evolution/theme_overrides.css';
    }

    /**
     * Current architect-overrides.css contents (for VisualTimeline before/after).
     */
    public function readCurrentCss(): string
    {
        $path = $this->cssFilePath();

        return is_file($path) ? (string) @file_get_contents($path) : '';
    }

    /**
     * Existing shadow Twig file contents, or null if none.
     */
    public function existingTwigOverrideContent(string $relativeTemplate): ?string
    {
        $relativeTemplate = $this->normalizeTemplatePath($relativeTemplate);
        if ($relativeTemplate === null) {
            return null;
        }
        $full = BASE_PATH . '/data/evolution/twig_overrides/' . $relativeTemplate;
        if (!is_file($full)) {
            return null;
        }
        $raw = @file_get_contents($full);

        return is_string($raw) ? $raw : null;
    }

    private function normalizeTemplatePath(string $path): ?string
    {
        $p = str_replace('\\', '/', trim($path));
        $p = ltrim($p, '/');
        if ($p === '' || str_contains($p, '..')) {
            return null;
        }
        if (!str_ends_with($p, '.twig')) {
            $p .= '.twig';
        }

        return $p;
    }
}
