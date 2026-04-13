<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Big Pull cache: full repo structure index + class abstracts stored under storage/evolution/vault/.
 * Invalidates when tracked file mtimes change or {@see markFigmaStructureDirty()} runs (webhook).
 */
final class EvolutionVaultService
{
    public const VAULT_DIR = 'storage/evolution/vault';

    public const STRUCTURE_MAP = 'storage/evolution/vault/structure_map.json';

    public const ABSTRACTS_MAP = 'storage/evolution/vault/abstracts_map.json';

    public const FIGMA_DIRTY_FLAG = 'storage/evolution/vault/figma_structure_dirty.flag';

    /**
     * @return array{ok: bool, error?: string, files?: int, abstracts?: int}
     */
    public static function rebuildVault(Config $config, bool $abstracts = true): array
    {
        if (!self::isEnabled($config)) {
            return ['ok' => false, 'error' => 'context_vault disabled'];
        }
        $dir = BASE_PATH . '/' . self::VAULT_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $roots = self::scanRoots($config);
        $baseNorm = rtrim(str_replace('\\', '/', BASE_PATH), '/');
        $files = [];
        foreach ($roots as $root) {
            $absRoot = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $root);
            if (!is_dir($absRoot)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!str_ends_with(strtolower($f->getFilename()), '.php')) {
                    continue;
                }
                $full = $f->getPathname();
                $fullNorm = str_replace('\\', '/', $full);
                $rel = ltrim(str_replace($baseNorm . '/', '', $fullNorm), '/');
                if ($rel === '' || str_contains($rel, '..')) {
                    continue;
                }
                $files[$rel] = [
                    'mtime' => (int) @filemtime($full),
                    'size' => (int) @filesize($full),
                ];
            }
        }

        ksort($files);

        $figmaMeta = self::readFigmaSidecarMeta();
        $structure = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'scan_roots' => $roots,
            'file_count' => count($files),
            'files' => $files,
            'figma' => $figmaMeta,
        ];
        $mapPath = BASE_PATH . '/' . self::STRUCTURE_MAP;
        @file_put_contents(
            $mapPath,
            json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );

        @unlink(BASE_PATH . '/' . self::FIGMA_DIRTY_FLAG);

        $abstractCount = 0;
        if ($abstracts) {
            $abstractCount = self::rebuildAbstractsMap($config, array_keys($files));
        }

        EvolutionLogger::log('context_vault', 'rebuild', ['files' => count($files), 'abstracts' => $abstractCount]);

        return ['ok' => true, 'files' => count($files), 'abstracts' => $abstractCount];
    }

    public static function markFigmaStructureDirty(): void
    {
        $p = BASE_PATH . '/' . self::FIGMA_DIRTY_FLAG;
        $dir = dirname($p);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($p, gmdate('c') . "\n", LOCK_EX);
    }

    public static function isEnabled(Config $config): bool
    {
        $cv = $config->get('evolution.context_vault', []);

        return is_array($cv) && filter_var($cv['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /** Totale grootte van storage/evolution/vault (gedeeld geheugen / abstracts). */
    public static function approxVaultBytes(): int
    {
        if (!defined('BASE_PATH')) {
            return 0;
        }
        $dir = BASE_PATH . '/' . self::VAULT_DIR;
        if (!is_dir($dir)) {
            return 0;
        }
        $total = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $total += (int) $f->getSize();
                }
            }
        } catch (\Throwable) {
            return 0;
        }

        return $total;
    }

    /**
     * True when vault missing, figma flag present, or any tracked file mtime/size drifted.
     */
    public static function needsRescan(Config $config): bool
    {
        if (!self::isEnabled($config)) {
            return false;
        }
        if (is_file(BASE_PATH . '/' . self::FIGMA_DIRTY_FLAG)) {
            return true;
        }
        $path = BASE_PATH . '/' . self::STRUCTURE_MAP;
        if (!is_file($path)) {
            return true;
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j) || !isset($j['files']) || !is_array($j['files'])) {
            return true;
        }
        foreach ($j['files'] as $rel => $meta) {
            if (!is_string($rel) || !is_array($meta)) {
                continue;
            }
            $full = BASE_PATH . '/' . $rel;
            if (!is_file($full)) {
                return true;
            }
            if ((int) @filemtime($full) !== (int) ($meta['mtime'] ?? 0)) {
                return true;
            }
            if ((int) @filesize($full) !== (int) ($meta['size'] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stage 1 prompt block: file index + short abstracts + rescan hint.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function promptBudgetAwareStage1(Config $config, array $messages): string
    {
        if (!self::isEnabled($config)) {
            return '';
        }
        $needs = self::needsRescan($config);
        $path = BASE_PATH . '/' . self::STRUCTURE_MAP;
        if (!is_file($path)) {
            return self::vaultMissingBlock($needs);
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j) || !isset($j['files']) || !is_array($j['files'])) {
            return self::vaultMissingBlock(true);
        }
        $files = $j['files'];
        $keys = array_keys($files);
        sort($keys);
        $sample = array_slice($keys, 0, 35);
        $lines = [
            "\n\nCONTEXT_VAULT_STAGE_1 (metadata + abstracts only — do not assume full sources unless Stage 2 pull):",
            '- Indexed PHP files: ' . count($keys) . ' (generated_at: ' . (string) ($j['generated_at'] ?? '?') . ').',
            '- Stale vs disk: ' . ($needs ? 'YES — run `php ai_bridge.php evolution:vault-rebuild` or accept drift.' : 'no drift detected for indexed mtimes.'),
            '- Sample paths:',
        ];
        foreach ($sample as $p) {
            $lines[] = '    • ' . $p;
        }
        if (count($keys) > count($sample)) {
            $lines[] = '    • … +' . (count($keys) - count($sample)) . ' more paths in ' . self::STRUCTURE_MAP;
        }

        $absPath = BASE_PATH . '/' . self::ABSTRACTS_MAP;
        if (is_file($absPath)) {
            $ar = @file_get_contents($absPath);
            $am = is_string($ar) ? json_decode($ar, true) : null;
            if (is_array($am) && isset($am['abstracts']) && is_array($am['abstracts'])) {
                $lines[] = '- Class abstracts (pseudo-code outlines):';
                $n = 0;
                foreach ($am['abstracts'] as $rel => $blob) {
                    $n++;
                    if ($n > 18) {
                        $lines[] = '    … truncating abstracts list in prompt (see ' . self::ABSTRACTS_MAP . ')';
                        break;
                    }
                    if (!is_string($rel) || !is_array($blob)) {
                        continue;
                    }
                    $ab = (string) ($blob['abstract'] ?? '');
                    $lines[] = '    ▸ ' . $rel;
                    foreach (array_slice(explode("\n", $ab), 0, 8) as $al) {
                        $lines[] = '      ' . $al;
                    }
                }
            }
        } else {
            $lines[] = '- Abstracts map missing — run evolution:vault-rebuild to populate ' . self::ABSTRACTS_MAP . '.';
        }

        $lines[] = 'BUDGET_AWARE_LOADER_STAGES:';
        $lines[] = '  1) Use this index + abstracts to reason;';
        $lines[] = '  2) Name exact relative paths when you need full file bodies (targeted pull / human or tooling);';
        $lines[] = '  3) Only then emit full_file_php / full_template patches (execution).';

        $lines[] = StrategyLibraryService::promptFlashback($config, $messages);

        return implode("\n", $lines);
    }

    private static function vaultMissingBlock(bool $needs): string
    {
        return "\n\nCONTEXT_VAULT: index not found or invalid. Run `php ai_bridge.php evolution:vault-rebuild` to create "
            . self::STRUCTURE_MAP . '. Stale signal: ' . ($needs ? 'yes' : 'no') . '.';
    }

    /**
     * @return list<string>
     */
    private static function scanRoots(Config $config): array
    {
        $cv = $config->get('evolution.context_vault', []);
        $roots = ['src/app'];
        if (is_array($cv) && isset($cv['structure_scan_roots']) && is_array($cv['structure_scan_roots'])) {
            $roots = [];
            foreach ($cv['structure_scan_roots'] as $r) {
                $r = trim(str_replace('\\', '/', (string) $r), '/');
                if ($r !== '') {
                    $roots[] = $r;
                }
            }
            if ($roots === []) {
                $roots = ['src/app'];
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param list<string> $paths
     */
    private static function rebuildAbstractsMap(Config $config, array $paths): int
    {
        $cv = $config->get('evolution.context_vault', []);
        $maxFiles = 400;
        if (is_array($cv) && isset($cv['abstract_max_files'])) {
            $maxFiles = max(50, min(5000, (int) $cv['abstract_max_files']));
        }

        $priority = [];
        if (is_array($cv) && isset($cv['abstract_priority_substrings']) && is_array($cv['abstract_priority_substrings'])) {
            foreach ($cv['abstract_priority_substrings'] as $s) {
                $priority[] = mb_strtolower((string) $s);
            }
        } else {
            $priority = ['/core/evolution/', '/domain/web/', '/core/'];
        }

        usort($paths, static function (string $a, string $b) use ($priority): int {
            $score = static function (string $p) use ($priority): int {
                $l = mb_strtolower($p);
                $s = 0;
                foreach ($priority as $sub) {
                    if ($sub !== '' && str_contains($l, $sub)) {
                        $s += 10;
                    }
                }

                return $s;
            };

            return $score($b) <=> $score($a);
        });

        $paths = array_slice($paths, 0, $maxFiles);
        $abstracts = [];
        foreach ($paths as $rel) {
            $r = EvolutionAbstractorService::abstractFile($config, $rel);
            if (($r['ok'] ?? false) && isset($r['abstract'])) {
                $abstracts[$rel] = [
                    'abstract' => (string) $r['abstract'],
                    'mtime' => (int) @filemtime(BASE_PATH . '/' . $rel),
                ];
            }
        }

        $out = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'abstract_max_files' => $maxFiles,
            'abstracts' => $abstracts,
        ];
        $ap = BASE_PATH . '/' . self::ABSTRACTS_MAP;
        @file_put_contents(
            $ap,
            json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );

        return count($abstracts);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readFigmaSidecarMeta(): array
    {
        $p = BASE_PATH . '/' . EvolutionFigmaService::LAST_FILE_META;
        if (!is_file($p)) {
            return ['last_file_meta' => null];
        }
        $raw = @file_get_contents($p);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'last_file_meta' => is_array($j) ? $j : null,
            'meta_mtime' => (int) @filemtime($p),
        ];
    }
}
