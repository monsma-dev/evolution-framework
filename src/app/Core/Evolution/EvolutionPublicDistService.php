<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Optional symlinks under public/assets/dist/ for cache-friendly asset URLs (manual / AI-managed).
 */
final class EvolutionPublicDistService
{
    /**
     * Create symlink public/assets/dist/{name} -> target path under public/ (relative to public/).
     *
     * @return array{ok: bool, error?: string}
     */
    public static function ensureSymlink(string $linkName, string $targetRelativeToPublic): array
    {
        $cfg = null;
        try {
            $c = ($GLOBALS)['app_container'] ?? null;
            if (is_object($c) && method_exists($c, 'get')) {
                $cfg = $c->get('config');
            }
        } catch (\Throwable) {
        }
        $pd = $cfg?->get('evolution.public_dist', []);
        if (is_array($pd) && !filter_var($pd['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'public_dist disabled'];
        }
        if (!defined('BASE_PATH')) {
            return ['ok' => false, 'error' => 'BASE_PATH'];
        }
        $linkName = trim(str_replace(['\\', '..'], ['/', ''], $linkName), '/');
        $targetRelativeToPublic = trim(str_replace('\\', '/', $targetRelativeToPublic), '/');
        if ($linkName === '' || $targetRelativeToPublic === '' || str_contains($targetRelativeToPublic, '..')) {
            return ['ok' => false, 'error' => 'invalid names'];
        }

        $public = BASE_PATH . '/public';
        $dist = $public . '/assets/dist';
        if (!is_dir($dist) && !@mkdir($dist, 0755, true) && !is_dir($dist)) {
            return ['ok' => false, 'error' => 'cannot mkdir dist'];
        }
        $linkPath = $dist . '/' . $linkName;
        $targetAbs = $public . '/' . $targetRelativeToPublic;
        $targetReal = realpath($targetAbs);
        if ($targetReal === false || !is_file($targetReal)) {
            return ['ok' => false, 'error' => 'target file missing under public/'];
        }
        $publicReal = realpath($public);
        if ($publicReal === false || !str_starts_with($targetReal, $publicReal)) {
            return ['ok' => false, 'error' => 'target escapes public'];
        }

        if (is_link($linkPath)) {
            @unlink($linkPath);
        } elseif (is_file($linkPath)) {
            return ['ok' => false, 'error' => 'link path exists as file'];
        }

        $ok = @symlink($targetReal, $linkPath);
        if (!$ok) {
            return ['ok' => false, 'error' => 'symlink() failed (OS permissions?)'];
        }
        EvolutionLogger::log('public_dist', 'symlink', ['link' => $linkName, 'target' => $targetRelativeToPublic]);

        return ['ok' => true];
    }
}
