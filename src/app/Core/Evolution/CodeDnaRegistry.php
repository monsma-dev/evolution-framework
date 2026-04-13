<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Append-only registry of AI-originated code changes (Code-DNA tags).
 */
final class CodeDnaRegistry
{
    private function path(Config $config): string
    {
        $p = (string)$config->get('evolution.code_dna.registry_path', 'storage/evolution/code_dna.jsonl');
        if (str_starts_with($p, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $p) === 1) {
            return $p;
        }

        return BASE_PATH . '/' . ltrim($p, '/');
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function record(Config $config, array $entry): void
    {
        $entry['dna_id'] = $entry['dna_id'] ?? ('dna-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)));
        $entry['ts'] = $entry['ts'] ?? gmdate('c');
        $entry['origin'] = 'ai';

        $file = $this->path($config);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        EvolutionLogger::log('code_dna', 'record', ['dna_id' => $entry['dna_id'], 'kind' => $entry['kind'] ?? '']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSince(Config $config, int $daysBack = 30, ?string $kind = null): array
    {
        $file = $this->path($config);
        if (!is_file($file)) {
            return [];
        }

        $cutoff = time() - max(1, $daysBack) * 86400;
        $out = [];
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        foreach ($lines as $line) {
            $row = json_decode((string)$line, true);
            if (!is_array($row)) {
                continue;
            }
            $ts = strtotime((string)($row['ts'] ?? '')) ?: 0;
            if ($ts < $cutoff) {
                continue;
            }
            if ($kind !== null && ($row['kind'] ?? '') !== $kind) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Remove AI shadow artifacts; optionally filter by kind.
     *
     * @return array{ok: bool, removed_files: list<string>, error?: string}
     */
    public function resetToHuman(Config $config, string $scope = 'all'): array
    {
        $removed = [];

        try {
            if ($scope === 'all' || $scope === 'php') {
                $root = BASE_PATH . '/storage/patches';
                if (is_dir($root)) {
                    $this->deleteTreePhpOnly($root, $removed);
                }
            }
            if ($scope === 'all' || $scope === 'twig') {
                $tw = BASE_PATH . '/storage/evolution/twig_overrides';
                if (is_dir($tw)) {
                    $this->deleteTree($tw, $removed);
                    @mkdir($tw, 0755, true);
                }
            }
            if ($scope === 'all' || $scope === 'css') {
                $evo = $config->get('evolution', []);
                $rel = 'public/storage/evolution/architect-overrides.css';
                if (is_array($evo)) {
                    $fp = $evo['frontend_patches'] ?? [];
                    if (is_array($fp)) {
                        $r = trim((string)($fp['css_file'] ?? ''));
                        if ($r !== '') {
                            $rel = $r;
                        }
                    }
                }
                $cssPath = (str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rel) === 1)
                    ? $rel
                    : BASE_PATH . '/' . ltrim($rel, '/');
                if (is_file($cssPath)) {
                    @unlink($cssPath);
                    $removed[] = $cssPath;
                }
            }
            if ($scope === 'all' || $scope === 'dna') {
                $f = $this->path($config);
                if (is_file($f)) {
                    @unlink($f);
                    $removed[] = $f;
                }
            }

            EvolutionLogger::log('code_dna', 'reset_human', ['scope' => $scope, 'files' => count($removed)]);

            return ['ok' => true, 'removed_files' => $removed];
        } catch (\Throwable $e) {
            return ['ok' => false, 'removed_files' => $removed, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param list<string> $removed
     */
    private function deleteTree(string $dir, array &$removed): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            $p = $file->getPathname();
            if (basename($p) === '.gitignore') {
                continue;
            }
            if ($file->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
                $removed[] = $p;
            }
        }
    }

    /**
     * @param list<string> $removed
     */
    private function deleteTreePhpOnly(string $dir, array &$removed): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            $p = $file->getPathname();
            if (str_contains($p, DIRECTORY_SEPARATOR . '.meta' . DIRECTORY_SEPARATOR)) {
                if ($file->isFile()) {
                    @unlink($p);
                    $removed[] = $p;
                }
                continue;
            }
            if (basename($p) === '.gitignore') {
                continue;
            }
            if ($file->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
                $removed[] = $p;
            }
        }
    }
}
