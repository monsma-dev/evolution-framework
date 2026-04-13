<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use ZipArchive;

/**
 * Eternal snapshot: atomic ZIP backup before risky operations + optional undead recovery.
 */
final class EvolutionSnapshotService
{
    private const DEFAULT_REL_PATHS = ['src', 'storage/evolution'];
    private const MARKER_UNDEAD = 'storage/evolution/undead_patch_marker.json';

    /**
     * @return array<string, mixed>
     */
    public static function cfg(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $s = is_array($evo) ? ($evo['snapshot'] ?? []) : [];

        return is_array($s) ? $s : [];
    }

    /**
     * @return array{ok: bool, path?: string, bytes?: int, error?: string, trigger?: string}
     */
    public static function create(Config $config, string $trigger): array
    {
        $s = self::cfg($config);
        if (!filter_var($s['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'snapshot disabled'];
        }
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'PHP zip extension (ZipArchive) required'];
        }

        $dir = BASE_PATH . '/storage/evolution/snapshots';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'cannot create snapshots dir'];
        }

        self::pruneOldArchives($dir, (int)($s['max_archives'] ?? 8));

        $name = 'snapshot-' . gmdate('Ymd-His') . '-' . preg_replace('/[^a-z0-9_-]+/i', '_', $trigger) . '.zip';
        $full = $dir . '/' . $name;

        $zip = new ZipArchive();
        if ($zip->open($full, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'cannot open zip'];
        }

        $manifest = [
            'created_at' => gmdate('c'),
            'trigger' => $trigger,
            'base_path' => BASE_PATH,
            'paths' => [],
        ];

        $paths = $s['include_paths'] ?? self::DEFAULT_REL_PATHS;
        if (!is_array($paths)) {
            $paths = self::DEFAULT_REL_PATHS;
        }

        foreach ($paths as $rel) {
            $rel = trim((string)$rel, '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $abs = BASE_PATH . '/' . $rel;
            if (is_dir($abs)) {
                self::addDirectoryToZip($zip, $abs, $rel, $manifest);
            } elseif (is_file($abs)) {
                $zip->addFile($abs, $rel);
                $manifest['paths'][] = $rel;
            }
        }

        $jsonGlob = trim((string)($s['evolution_json_glob'] ?? 'storage/evolution/*.json'));
        if ($jsonGlob !== '') {
            foreach (glob(BASE_PATH . '/' . ltrim(str_replace('*', '*', $jsonGlob), '/')) ?: [] as $jf) {
                if (is_file($jf)) {
                    $zr = ltrim(str_replace('\\', '/', substr($jf, strlen(BASE_PATH))), '/');
                    $zip->addFile($jf, $zr);
                    $manifest['paths'][] = $zr;
                }
            }
        }

        if (filter_var($s['include_dot_env_example'] ?? true, FILTER_VALIDATE_BOOL)) {
            $envEx = BASE_PATH . '/.env.example';
            if (is_file($envEx)) {
                $zip->addFile($envEx, '.env.example');
                $manifest['paths'][] = '.env.example';
            }
        }

        $zip->addFromString('MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();

        $bytes = is_file($full) ? (int)filesize($full) : 0;
        @file_put_contents($dir . '/LATEST.txt', $name . "\n", LOCK_EX);

        EvolutionLogger::log('snapshot', 'created', ['file' => $name, 'bytes' => $bytes, 'trigger' => $trigger]);

        return ['ok' => true, 'path' => $name, 'bytes' => $bytes, 'trigger' => $trigger];
    }

    /**
     * @return array{ok: bool, restored?: list<string>, error?: string}
     */
    public static function restoreLatest(Config $config): array
    {
        $dir = BASE_PATH . '/storage/evolution/snapshots';
        $latest = $dir . '/LATEST.txt';
        if (!is_file($latest)) {
            return ['ok' => false, 'error' => 'no LATEST.txt'];
        }
        $name = trim((string)@file_get_contents($latest));
        if ($name === '' || !is_file($dir . '/' . basename($name))) {
            return ['ok' => false, 'error' => 'latest archive missing'];
        }

        return self::restoreNamed($config, basename($name));
    }

    /**
     * @return array{ok: bool, restored?: list<string>, error?: string}
     */
    public static function restoreNamed(Config $config, string $zipBasename): array
    {
        $s = self::cfg($config);
        if (!filter_var($s['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'snapshot disabled'];
        }
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'PHP zip extension required'];
        }
        $zipBasename = basename($zipBasename);
        if (!str_ends_with($zipBasename, '.zip')) {
            return ['ok' => false, 'error' => 'invalid archive name'];
        }
        $full = BASE_PATH . '/storage/evolution/snapshots/' . $zipBasename;
        if (!is_file($full)) {
            return ['ok' => false, 'error' => 'archive not found'];
        }

        $zip = new ZipArchive();
        if ($zip->open($full) !== true) {
            return ['ok' => false, 'error' => 'cannot open zip'];
        }

        $restored = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $entry = (string)($stat['name'] ?? '');
            if ($entry === '' || str_ends_with($entry, '/')) {
                continue;
            }
            if ($entry === 'MANIFEST.json') {
                continue;
            }
            if (str_contains($entry, '..')) {
                continue;
            }
            $target = BASE_PATH . '/' . $entry;
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $zip->close();

                return ['ok' => false, 'error' => 'mkdir failed: ' . $dir];
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) {
                continue;
            }
            if (@file_put_contents($target, $content) !== false) {
                $restored[] = $entry;
            }
        }
        $zip->close();

        EvolutionLogger::log('snapshot', 'restored', ['count' => count($restored), 'archive' => $zipBasename]);

        return ['ok' => true, 'restored' => $restored];
    }

    /**
     * Marker voor undead-check (cron): na patch wordt tijd gezet; health moet binnen venster slagen.
     *
     * @param array<string, mixed>|null $meta
     */
    public static function markPatchCompleted(?array $meta = null): void
    {
        $path = BASE_PATH . '/' . self::MARKER_UNDEAD;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $payload = [
            'ts' => time(),
            'iso' => gmdate('c'),
            'meta' => is_array($meta) ? $meta : [],
        ];
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * CLI/cron: als health-check faalt na patch + wachttijd, restore latest snapshot.
     *
     * @return array<string, mixed>
     */
    public static function undeadRecoverIfNeeded(Config $config): array
    {
        $s = self::cfg($config);
        if (!filter_var($s['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'skipped' => 'disabled'];
        }
        if (!filter_var($s['undead_enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'skipped' => 'undead disabled'];
        }

        $marker = BASE_PATH . '/' . self::MARKER_UNDEAD;
        if (!is_file($marker)) {
            return ['ok' => true, 'skipped' => 'no patch marker'];
        }
        $raw = @file_get_contents($marker);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        $ts = is_array($j) ? (int)($j['ts'] ?? 0) : 0;
        $wait = max(5, min(120, (int)($s['undead_grace_seconds'] ?? 10)));
        if ($ts <= 0 || (time() - $ts) < $wait) {
            return ['ok' => true, 'skipped' => 'still in grace window'];
        }

        $healthUrl = trim((string)$s['health_check_url'] ?? '');
        if ($healthUrl === '') {
            $base = rtrim((string)$config->get('site.url', ''), '/');
            $healthUrl = $base !== '' ? $base . '/api/v1/health' : '';
        }
        if ($healthUrl === '') {
            return ['ok' => false, 'error' => 'configure evolution.snapshot.health_check_url or site.url'];
        }

        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $body = @file_get_contents($healthUrl, false, $ctx);
        $ok = is_string($body) && $body !== '';
        if ($ok) {
            @unlink($marker);

            return ['ok' => true, 'skipped' => 'health ok', 'url' => $healthUrl];
        }

        EvolutionFlightRecorder::capture($config, 'undead_pre_restore', [
            'health_url' => $healthUrl,
            'marker_age_seconds' => time() - $ts,
        ]);

        $r = self::restoreLatest($config);
        @unlink($marker);

        return array_merge(['ok' => $r['ok'] ?? false, 'undead' => true, 'health_failed' => true, 'url' => $healthUrl], $r);
    }

    private static function pruneOldArchives(string $dir, int $keep): void
    {
        $keep = max(1, min(50, $keep));
        $files = glob($dir . '/snapshot-*.zip') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }

    private static function addDirectoryToZip(ZipArchive $zip, string $absBase, string $zipPrefix, array &$manifest): void
    {
        $absNorm = rtrim(str_replace('\\', '/', $absBase), '/');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absBase, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $fullN = str_replace('\\', '/', $file->getPathname());
            $inner = ltrim(substr($fullN, strlen($absNorm)), '/');
            $rel = $zipPrefix . '/' . $inner;
            $zip->addFile($file->getPathname(), $rel);
            $manifest['paths'][] = $rel;
        }
    }
}
