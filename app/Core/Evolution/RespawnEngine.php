<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Time-anchors (ZIP of critical paths) before auto-apply + death log + prompt tail.
 */
final class RespawnEngine
{
    /**
     * Pre-apply time-anchor (default): ZIP van kritieke paden vóór auto-apply.
     *
     * @return array{ok: bool, path?: string, hash?: string, files?: int, error?: string}
     */
    public static function createAnchor(Config $config, string $note = 'Pre-apply snapshot'): array
    {
        $paths = self::collectPreApplyRelativePaths($config);

        return self::writeAnchorZip($config, $paths, $note);
    }

    /**
     * Handmatige anchor met expliciete relatieve bestandspaden.
     *
     * @param list<string> $relativePaths
     *
     * @return array{ok: bool, path?: string, hash?: string, files?: int, error?: string}
     */
    public static function createZipForPaths(Config $config, array $relativePaths, ?string $note = null): array
    {
        return self::writeAnchorZip($config, $relativePaths, $note ?? 'manual_anchor');
    }

    /**
     * @return list<string>
     */
    public static function collectPreApplyRelativePaths(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $r = is_array($evo) ? ($evo['respawn'] ?? []) : [];
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);

        $roots = $r['anchor_include_paths'] ?? null;
        if (!is_array($roots) || $roots === []) {
            $snap = is_array($evo) ? ($evo['snapshot'] ?? []) : [];
            $roots = is_array($snap['include_paths'] ?? null) ? $snap['include_paths'] : ['src/app', 'storage/patches', 'storage/evolution'];
        }

        $out = [];
        foreach ($roots as $rel) {
            $rel = trim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $abs = $base . '/' . $rel;
            if (is_file($abs)) {
                $out[] = $rel;
            } elseif (is_dir($abs)) {
                foreach (self::listFilesUnderDir($abs, $base) as $f) {
                    $out[] = $f;
                }
            }
        }

        $evoJson = 'config/evolution.json';
        if (is_file($base . '/' . $evoJson) && !in_array($evoJson, $out, true)) {
            $out[] = $evoJson;
        }

        foreach (['docker/php/99-framework.ini'] as $criticalIni) {
            if (is_file($base . '/' . $criticalIni) && !in_array($criticalIni, $out, true)) {
                $out[] = $criticalIni;
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @return list<string> relative paths
     */
    private static function listFilesUnderDir(string $absDir, string $basePath): array
    {
        $baseNorm = rtrim(str_replace('\\', '/', $basePath), '/');
        $relBase = strlen($baseNorm) + 1;
        $files = [];

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $file */
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $full = str_replace('\\', '/', $file->getPathname());
                if (str_contains($full, '/.git/') || str_contains($full, '/node_modules/')) {
                    continue;
                }
                $rel = ltrim(substr($full, $relBase), '/');
                if ($rel !== '' && !str_contains($rel, '..')) {
                    $files[] = $rel;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $files;
    }

    /**
     * @param list<string> $relativePaths
     *
     * @return array{ok: bool, path?: string, hash?: string, files?: int, error?: string}
     */
    private static function writeAnchorZip(Config $config, array $relativePaths, string $note): array
    {
        $evo = $config->get('evolution', []);
        $r = is_array($evo) ? ($evo['respawn'] ?? []) : [];
        if (!is_array($r) || !filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'respawn disabled'];
        }
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'ZipArchive not available'];
        }

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $dirRel = trim((string) ($r['anchor_dir'] ?? 'storage/evolution/respawn'), '/');
        $dir = $base . '/' . $dirRel;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'cannot create anchor dir'];
        }

        $hash = substr(hash('sha256', json_encode([$relativePaths, microtime(true), $note]) ?: ''), 0, 16);
        $zipPath = $dir . '/anchor_' . $hash . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'cannot open zip'];
        }

        $manifest = [
            'created_at' => gmdate('c'),
            'hash' => $hash,
            'note' => $note,
            'paths' => [],
        ];

        $added = 0;
        foreach ($relativePaths as $rel) {
            $rel = trim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $abs = $base . '/' . $rel;
            if (is_file($abs)) {
                $zip->addFile($abs, $rel);
                $manifest['paths'][] = $rel;
                $added++;
            }
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        $zip->close();

        EvolutionLogger::log('respawn', 'anchor_created', ['hash' => $hash, 'files' => $added, 'note' => $note]);

        return ['ok' => true, 'path' => $zipPath, 'hash' => $hash, 'files' => $added];
    }

    /**
     * @param array<string, mixed>|string $eventOrMessage
     */
    public static function recordDeath(Config $config, array|string $eventOrMessage): bool
    {
        if (is_string($eventOrMessage)) {
            return self::recordDeath($config, ['message' => $eventOrMessage]);
        }
        $event = $eventOrMessage;

        $evo = $config->get('evolution', []);
        $r = is_array($evo) ? ($evo['respawn'] ?? []) : [];
        if (!is_array($r) || !filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }
        $logRel = trim((string) ($r['death_log'] ?? 'storage/evolution/respawn/death_log.jsonl'), '/');
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $logPath = $base . '/' . $logRel;
        $logDir = dirname($logPath);
        if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            return false;
        }

        $event['respawned_at'] = $event['respawned_at'] ?? gmdate('c');
        $line = json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";

        return @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    public static function latestDeathSummaryForPrompt(Config $config, int $maxLines = 3): string
    {
        $evo = $config->get('evolution', []);
        $r = is_array($evo) ? ($evo['respawn'] ?? []) : [];
        if (!is_array($r) || !filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $logRel = trim((string) ($r['death_log'] ?? ''), '/');
        if ($logRel === '') {
            return '';
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $logPath = $base . '/' . $logRel;
        if (!is_file($logPath)) {
            return '';
        }
        $lines = @file($logPath, FILE_IGNORE_NEW_LINES) ?: [];
        $tail = array_slice($lines, -$maxLines);
        if ($tail === []) {
            return '';
        }
        $block = "\n\n## Respawn death log (recent)\n";
        foreach ($tail as $ln) {
            $block .= LogAnonymizerService::scrub((string) $ln, $config) . "\n";
        }

        return $block;
    }

    /**
     * Read a file from the most recently written anchor ZIP (golden state for Master comparative).
     */
    public static function readRelativeFromLatestAnchor(Config $config, string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }
        $evo = $config->get('evolution', []);
        $r = is_array($evo) ? ($evo['respawn'] ?? []) : [];
        $dirRel = trim((string) ($r['anchor_dir'] ?? 'storage/evolution/respawn'), '/');
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $dir = $base . '/' . $dirRel;
        if (!is_dir($dir)) {
            return null;
        }
        $files = glob($dir . '/anchor_*.zip') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, static fn (string $a, string $b): int => (int) (filemtime($b) <=> filemtime($a)));
        $zipPath = $files[0];
        if (!class_exists(ZipArchive::class)) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        $idx = $zip->locateName($relativePath);
        if ($idx === false) {
            $zip->close();

            return null;
        }
        $content = $zip->getFromIndex($idx);
        $zip->close();
        if ($content === false) {
            return null;
        }

        return (string) $content;
    }

    /**
     * Restore a single relative path from the most recent anchor ZIP (same ordering as readRelativeFromLatestAnchor).
     *
     * @return array{ok: bool, ms?: float, error?: string, bytes?: int}
     */
    public static function restoreRelativeFromLatestAnchor(Config $config, string $relativePath): array
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return ['ok' => false, 'error' => 'invalid relative path'];
        }
        $t0 = microtime(true);
        $content = self::readRelativeFromLatestAnchor($config, $relativePath);
        if ($content === null) {
            return ['ok' => false, 'error' => 'not found in latest anchor'];
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $target = $base . '/' . $relativePath;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'cannot mkdir'];
        }
        if (@file_put_contents($target, $content) === false) {
            return ['ok' => false, 'error' => 'write failed'];
        }

        return [
            'ok' => true,
            'ms' => round((microtime(true) - $t0) * 1000, 3),
            'bytes' => strlen($content),
        ];
    }
}
