<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Deletes old AI artifacts: HotSwap versioned backups, shadow deploy files, rotated .bak logs.
 */
final class EvolutionShredderService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, deleted_files?: int, freed_bytes?: int, error?: string}
     */
    public function run(): array
    {
        $cfg = $this->container->get('config');
        $sh = $cfg->get('evolution.shredder', []);
        if (!is_array($sh) || !filter_var($sh['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'shredder disabled'];
        }
        $maxDays = max(1, (int) ($sh['max_age_days'] ?? 14));
        $cutoff = time() - $maxDays * 86400;

        $dirs = [
            BASE_PATH . '/data/evolution/versioned_backups',
            BASE_PATH . '/data/evolution/shadow_deploys',
        ];
        $extra = $sh['extra_dirs'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $rel) {
                $rel = trim((string) $rel);
                if ($rel !== '' && !str_contains($rel, '..')) {
                    $dirs[] = BASE_PATH . '/' . ltrim($rel, '/');
                }
            }
        }

        $deleted = 0;
        $freed = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $mtime = @filemtime($path) ?: 0;
                if ($mtime >= $cutoff) {
                    continue;
                }
                $sz = filesize($path) ?: 0;
                if (@unlink($path)) {
                    $deleted++;
                    $freed += $sz;
                }
            }
        }

        $bakGlob = BASE_PATH . '/data/evolution/*.bak';
        foreach (glob($bakGlob) ?: [] as $f) {
            if (!is_string($f) || !is_file($f)) {
                continue;
            }
            $mtime = @filemtime($f) ?: 0;
            if ($mtime >= $cutoff) {
                continue;
            }
            $sz = filesize($f) ?: 0;
            if (@unlink($f)) {
                $deleted++;
                $freed += $sz;
            }
        }

        EvolutionLogger::log('shredder', 'run', ['deleted' => $deleted, 'freed_bytes' => $freed]);

        return ['ok' => true, 'deleted_files' => $deleted, 'freed_bytes' => $freed];
    }
}
