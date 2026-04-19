<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Visual memory: after successful ui_autofix, stores before/after thumbnails (GD) + Hall of Fame entry.
 */
final class VisualTimelineService
{
    public const THUMB_DIR = 'storage/evolution/visual_thumbs';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param 'twig'|'css' $kind
     */
    public function recordAutofix(string $kind, string $target, ?string $beforeContent, string $afterContent): void
    {
        $cfg = $this->container->get('config');
        $vm = $cfg->get('evolution.visual_memory', []);
        if (!is_array($vm) || !filter_var($vm['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $dir = BASE_PATH . '/' . self::THUMB_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $id = bin2hex(random_bytes(8));
        $beforePath = $dir . '/' . $id . '_before.png';
        $afterPath = $dir . '/' . $id . '_after.png';

        $beforeText = $beforeContent !== null ? mb_substr($beforeContent, 0, 800) : '(none)';
        $afterText = mb_substr($afterContent, 0, 800);

        self::renderPlaceholderPng($beforePath, 'BEFORE', $beforeText);
        self::renderPlaceholderPng($afterPath, 'AFTER', $afterText);

        $relBefore = self::THUMB_DIR . '/' . basename($beforePath);
        $relAfter = self::THUMB_DIR . '/' . basename($afterPath);

        $hof = new EvolutionHallOfFameService($this->container);
        $hof->recordMilestone(
            'UI autofix: ' . $kind . ' ' . $target,
            'visual_timeline',
            [
                'kind' => $kind,
                'target' => $target,
                'thumb_before' => $relBefore,
                'thumb_after' => $relAfter,
            ]
        );

        $line = json_encode([
            'ts' => gmdate('c'),
            'kind' => $kind,
            'target' => $target,
            'thumb_before' => $relBefore,
            'thumb_after' => $relAfter,
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(BASE_PATH . '/data/evolution/visual_timeline.jsonl', $line, FILE_APPEND | LOCK_EX);

        EvolutionLogger::log('visual_memory', 'recorded', ['target' => $target, 'kind' => $kind]);
    }

    /**
     * Last N entries from visual_timeline.jsonl (newest last in returned list).
     *
     * @return list<array<string, mixed>>
     */
    public static function readRecentEntries(int $limit = 12): array
    {
        $path = BASE_PATH . '/data/evolution/visual_timeline.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $slice = array_slice($lines, -max(1, $limit));
        $out = [];
        foreach ($slice as $line) {
            $j = json_decode((string) $line, true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }

        return $out;
    }

    private static function renderPlaceholderPng(string $absPath, string $label, string $snippet): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            @file_put_contents($absPath . '.txt', $label . "\n\n" . $snippet);

            return;
        }
        $w = 320;
        $h = 200;
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            return;
        }
        $bg = imagecolorallocate($im, 245, 245, 250);
        $fg = imagecolorallocate($im, 40, 40, 55);
        $muted = imagecolorallocate($im, 120, 120, 130);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        imagestring($im, 5, 10, 10, $label, $fg);
        $lines = explode("\n", wordwrap(str_replace(["\r", "\n"], ' ', $snippet), 48, "\n", true));
        $y = 40;
        foreach (array_slice($lines, 0, 6) as $ln) {
            imagestring($im, 3, 10, $y, mb_substr($ln, 0, 60), $muted);
            $y += 18;
        }
        imagepng($im, $absPath);
        imagedestroy($im);
    }
}
