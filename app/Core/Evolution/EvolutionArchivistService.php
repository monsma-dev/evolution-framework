<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Moves cold Evolution artifacts (twig overrides, optional globs) into dated archives under storage/evolution/archive/.
 */
final class EvolutionArchivistService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, archived?: list<string>, skipped?: list<string>, error?: string}
     */
    public function runArchivePass(bool $dryRun = false): array
    {
        $cfg = $this->container->get('config');
        $arch = $cfg->get('evolution.archivist', []);
        if (!is_array($arch) || !filter_var($arch['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'archivist disabled'];
        }

        $staleDays = max(1, (int) ($arch['stale_days'] ?? 60));
        $cutoff = time() - ($staleDays * 86400);
        $roots = $arch['scan_roots'] ?? ['storage/evolution/twig_overrides'];
        if (!is_array($roots)) {
            $roots = ['storage/evolution/twig_overrides'];
        }

        $archived = [];
        $skipped = [];

        foreach ($roots as $relRoot) {
            $relRoot = trim(str_replace('\\', '/', (string) $relRoot), '/');
            if ($relRoot === '' || str_contains($relRoot, '..')) {
                continue;
            }
            $absRoot = BASE_PATH . '/' . $relRoot;
            if (!is_dir($absRoot)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }
                $path = $file->getPathname();
                if (!$this->isStale($path, $cutoff, $cfg)) {
                    $skipped[] = $path;

                    continue;
                }
                $rel = str_replace('\\', '/', $path);
                if (EvolutionIgnoreRegistry::matches(str_replace(BASE_PATH, '', $rel), $cfg)) {
                    $skipped[] = $path;

                    continue;
                }
                $dest = $this->archiveDestination($path);
                if ($dest === null) {
                    continue;
                }
                if ($dryRun) {
                    $archived[] = $path . ' -> ' . $dest;

                    continue;
                }
                $dir = dirname($dest);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    continue;
                }
                if (!@rename($path, $dest)) {
                    continue;
                }
                $this->appendManifest($path, $dest);
                $archived[] = $dest;
                EvolutionLogger::log('archivist', 'archived', ['from' => $path, 'to' => $dest]);
            }
        }

        SelfHealingManager::clearTwigCache();

        return ['ok' => true, 'archived' => $archived, 'skipped' => $skipped];
    }

    /**
     * Stale = old mtime and no recent CRO mention of this template (best-effort).
     */
    private function isStale(string $absoluteFile, int $cutoffTs, Config $config): bool
    {
        $mtime = @filemtime($absoluteFile) ?: 0;
        if ($mtime >= $cutoffTs) {
            return false;
        }
        if ($this->wasInCroRecently($absoluteFile, $config)) {
            return false;
        }

        return true;
    }

    private function wasInCroRecently(string $absoluteTwig, Config $config): bool
    {
        $arch = $config->get('evolution.archivist', []);
        $days = max(7, (int) ($arch['cro_recency_days'] ?? 30));
        $cutoff = gmdate('c', time() - $days * 86400);
        $rel = str_replace('\\', '/', $absoluteTwig);
        $base = str_replace('\\', '/', BASE_PATH);
        $short = ltrim(str_replace($base, '', $rel), '/');
        $baseName = pathinfo($short, PATHINFO_FILENAME);
        $croPath = BASE_PATH . '/data/evolution/cro_events.jsonl';
        if (!is_file($croPath)) {
            return false;
        }
        $lines = @file($croPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return false;
        }
        foreach (array_slice($lines, -8000) as $line) {
            $j = @json_decode((string) $line, true);
            if (!is_array($j)) {
                continue;
            }
            $ts = (string) ($j['timestamp'] ?? $j['ts'] ?? '');
            if ($ts === '' || $ts < $cutoff) {
                continue;
            }
            $tpl = (string) ($j['metadata']['template'] ?? '');
            $step = (string) ($j['step'] ?? '');
            if ($tpl !== '' && (str_contains($tpl, $short) || str_contains($tpl, $baseName))) {
                return true;
            }
            if ($step !== '' && (str_contains($step, $baseName) || str_contains($step, str_replace('.twig', '', $short)))) {
                return true;
            }
        }

        return false;
    }

    private function archiveDestination(string $absoluteSource): ?string
    {
        $base = realpath(BASE_PATH);
        $real = realpath($absoluteSource);
        if ($base === false || $real === false || !str_starts_with($real, $base)) {
            return null;
        }
        $rel = ltrim(str_replace('\\', '/', substr($real, strlen($base))), '/');
        $day = gmdate('Y-m-d');
        $destDir = BASE_PATH . '/data/evolution/archive/' . $day;
        $dest = $destDir . '/' . str_replace('/', '_', $rel);

        return $dest;
    }

    private function appendManifest(string $from, string $to): void
    {
        $path = BASE_PATH . '/data/evolution/archive/manifest.jsonl';
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $line = json_encode([
            'ts' => gmdate('c'),
            'from' => str_replace(BASE_PATH, '', $from),
            'to' => str_replace(BASE_PATH, '', $to),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($line)) {
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
