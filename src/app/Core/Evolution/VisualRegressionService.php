<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Visual Regression Detection: captures screenshots before and after UI patches,
 * compares them using pixel-level analysis, and triggers Guard Dog rollback
 * if the visual change exceeds the acceptable threshold.
 *
 * Uses the existing VisualCaptureService (Playwright/Node) for screenshots
 * and GD for pixel comparison (no external diff tools needed).
 */
final class VisualRegressionService
{
    private const SNAPSHOT_DIR = 'storage/evolution/visual_regression';
    private const MAX_CHANGE_PCT_DEFAULT = 40.0;
    private const MAX_CHANGE_PCT_STRICT = 5.0;

    public function __construct(private readonly Container $container)
    {
    }

    private function maxChangePct(): float
    {
        try {
            $cfg = $this->container->get('config');
            $evo = $cfg->get('evolution', []);
            $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
            $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];
            if (is_array($aa) && filter_var($aa['ui_strict_mode'] ?? false, FILTER_VALIDATE_BOOL)) {
                return max(1, (float)($aa['ui_strict_max_change_pct'] ?? self::MAX_CHANGE_PCT_STRICT));
            }
        } catch (\Throwable) {
        }

        return self::MAX_CHANGE_PCT_DEFAULT;
    }

    /**
     * Capture a "before" screenshot for comparison after UI patch.
     *
     * @return array{ok: bool, path?: string, error?: string}
     */
    public function captureBefore(string $pageUrl, int $width = 1280, int $height = 900): array
    {
        $dir = BASE_PATH . '/' . self::SNAPSHOT_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $capture = new VisualCaptureService($this->container);
        $result = $capture->captureAbsoluteUrl($pageUrl, $width, $height, 1200);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Capture failed'];
        }

        $beforePath = $dir . '/before-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.png';
        if (isset($result['path']) && is_file($result['path'])) {
            @copy($result['path'], $beforePath);
        } elseif (isset($result['base64'])) {
            @file_put_contents($beforePath, base64_decode($result['base64']));
        }

        if (!is_file($beforePath)) {
            return ['ok' => false, 'error' => 'Cannot save before screenshot'];
        }

        return ['ok' => true, 'path' => $beforePath];
    }

    /**
     * Capture an "after" screenshot and compare with the before snapshot.
     *
     * @return array{ok: bool, change_pct: float, regression: bool, before_path?: string, after_path?: string, error?: string}
     */
    public function captureAfterAndCompare(string $pageUrl, string $beforePath, int $width = 1280, int $height = 900): array
    {
        if (!is_file($beforePath)) {
            return ['ok' => false, 'change_pct' => 0, 'regression' => false, 'error' => 'Before screenshot not found'];
        }

        $capture = new VisualCaptureService($this->container);
        $result = $capture->captureAbsoluteUrl($pageUrl, $width, $height, 1200);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'change_pct' => 0, 'regression' => false, 'error' => $result['error'] ?? 'After capture failed'];
        }

        $dir = BASE_PATH . '/' . self::SNAPSHOT_DIR;
        $afterPath = $dir . '/after-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.png';
        if (isset($result['path']) && is_file($result['path'])) {
            @copy($result['path'], $afterPath);
        } elseif (isset($result['base64'])) {
            @file_put_contents($afterPath, base64_decode($result['base64']));
        }

        if (!is_file($afterPath)) {
            return ['ok' => false, 'change_pct' => 0, 'regression' => false, 'error' => 'Cannot save after screenshot'];
        }

        $changePct = $this->compareImages($beforePath, $afterPath);
        $threshold = $this->maxChangePct();
        $regression = $changePct > $threshold;

        EvolutionLogger::log('visual_regression', $regression ? 'regression_detected' : 'ok', [
            'url' => $pageUrl,
            'change_pct' => $changePct,
            'threshold' => $threshold,
            'before' => basename($beforePath),
            'after' => basename($afterPath),
        ]);

        return [
            'ok' => true,
            'change_pct' => $changePct,
            'regression' => $regression,
            'before_path' => $beforePath,
            'after_path' => $afterPath,
        ];
    }

    /**
     * Full flow: capture before, apply callback, capture after, compare.
     * If regression detected, the callback result can be used to rollback.
     *
     * @param callable $applyFn Function that applies the UI patch (returns void or result)
     * @return array{ok: bool, change_pct: float, regression: bool, apply_result: mixed}
     */
    public function testWithRegression(string $pageUrl, callable $applyFn, int $width = 1280, int $height = 900): array
    {
        $before = $this->captureBefore($pageUrl, $width, $height);
        if (!($before['ok'] ?? false)) {
            $applyResult = $applyFn();

            return ['ok' => false, 'change_pct' => 0, 'regression' => false, 'apply_result' => $applyResult, 'error' => 'Before capture failed: ' . ($before['error'] ?? '')];
        }

        $applyResult = $applyFn();

        usleep(500000);

        $comparison = $this->captureAfterAndCompare($pageUrl, $before['path'], $width, $height);

        return [
            'ok' => $comparison['ok'] ?? false,
            'change_pct' => $comparison['change_pct'] ?? 0,
            'regression' => $comparison['regression'] ?? false,
            'apply_result' => $applyResult,
            'before_path' => $before['path'] ?? null,
            'after_path' => $comparison['after_path'] ?? null,
        ];
    }

    /**
     * Compare two PNG images using GD pixel sampling.
     * Returns percentage of pixels that differ significantly.
     */
    private function compareImages(string $path1, string $path2): float
    {
        if (!function_exists('imagecreatefrompng')) {
            return 0;
        }

        $img1 = @imagecreatefrompng($path1);
        $img2 = @imagecreatefrompng($path2);

        if ($img1 === false || $img2 === false) {
            if ($img1 !== false) {
                imagedestroy($img1);
            }
            if ($img2 !== false) {
                imagedestroy($img2);
            }

            return 0;
        }

        $w1 = imagesx($img1);
        $h1 = imagesy($img1);
        $w2 = imagesx($img2);
        $h2 = imagesy($img2);

        $w = min($w1, $w2);
        $h = min($h1, $h2);

        if ($w === 0 || $h === 0) {
            imagedestroy($img1);
            imagedestroy($img2);

            return 100;
        }

        $sampleStep = max(1, (int)sqrt(($w * $h) / 10000));
        $totalSamples = 0;
        $diffSamples = 0;
        $threshold = 30;

        for ($y = 0; $y < $h; $y += $sampleStep) {
            for ($x = 0; $x < $w; $x += $sampleStep) {
                $c1 = imagecolorat($img1, $x, $y);
                $c2 = imagecolorat($img2, $x, $y);

                $r1 = ($c1 >> 16) & 0xFF;
                $g1 = ($c1 >> 8) & 0xFF;
                $b1 = $c1 & 0xFF;

                $r2 = ($c2 >> 16) & 0xFF;
                $g2 = ($c2 >> 8) & 0xFF;
                $b2 = $c2 & 0xFF;

                $diff = abs($r1 - $r2) + abs($g1 - $g2) + abs($b1 - $b2);
                $totalSamples++;
                if ($diff > $threshold) {
                    $diffSamples++;
                }
            }
        }

        imagedestroy($img1);
        imagedestroy($img2);

        if ($totalSamples === 0) {
            return 0;
        }

        if (abs($w1 - $w2) > 10 || abs($h1 - $h2) > 50) {
            return min(100, round(($diffSamples / $totalSamples) * 100, 1) + 15);
        }

        return round(($diffSamples / $totalSamples) * 100, 1);
    }
}
