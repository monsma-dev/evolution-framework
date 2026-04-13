<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Aggregates reasoning.json sidecars with guard snapshots for dashboard charts.
 */
final class EvolutionTimelineService
{
    /**
     * @return array{ok: bool, points: list<array<string, mixed>>}
     */
    public function collect(Config $config, int $days = 30): array
    {
        $evo = $config->get('evolution', []);
        $rel = is_array($evo) ? (string)($evo['patches_path'] ?? 'storage/patches') : 'storage/patches';
        $root = $rel !== '' && (str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rel) === 1)
            ? $rel
            : BASE_PATH . '/' . ltrim($rel, '/\\');

        $cutoff = time() - max(1, $days) * 86400;
        $points = [];

        if (!is_dir($root)) {
            return ['ok' => true, 'points' => []];
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getFilename() === '' || !str_ends_with($file->getFilename(), '.reasoning.json')) {
                continue;
            }
            $raw = @file_get_contents($file->getPathname());
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $j = json_decode($raw, true);
            if (!is_array($j)) {
                continue;
            }
            $atStr = (string)($j['generated_at'] ?? '');
            $at = strtotime($atStr);
            if ($at !== false && $at < $cutoff) {
                continue;
            }

            $fqcn = (string)($j['fqcn'] ?? '');
            if ($fqcn === '' || !str_starts_with($fqcn, 'App\\')) {
                continue;
            }

            $guard = PatchExecutionTimer::readGuardSnapshot($fqcn);
            $actual = null;
            $rolledBack = false;
            if (is_array($guard)) {
                $rolledBack = !empty($guard['rolled_back']);
                if (!$rolledBack && isset($guard['last_step_ms'])) {
                    $actual = (float)$guard['last_step_ms'];
                }
            }

            $expected = $j['expected_gain_ms'] ?? null;
            $expected = is_numeric($expected) ? (float)$expected : null;
            $baseline = $j['original_baseline_ms'] ?? null;
            $baseline = is_numeric($baseline) ? (float)$baseline : null;

            $points[] = [
                'at' => $atStr !== '' ? $atStr : gmdate('c'),
                'fqcn' => $fqcn,
                'expected_gain_ms' => $expected,
                'original_baseline_ms' => $baseline,
                'actual_step_ms' => $actual,
                'rolled_back' => $rolledBack,
            ];
        }

        usort($points, static function (array $a, array $b): int {
            return strcmp((string)($a['at'] ?? ''), (string)($b['at'] ?? ''));
        });

        return ['ok' => true, 'points' => $points];
    }
}
