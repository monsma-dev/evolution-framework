<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Polijst-beitel: PHP syntax check (php -l) on allowed paths — no guessing on brackets/semicolons.
 */
final class EvolutionStyleEnforcer
{
    /**
     * @param list<string> $relativePhpFiles paths under BASE_PATH
     * @return array{ok: bool, files: list<array{path: string, ok: bool, message: string}>}
     */
    public static function lintFiles(array $relativePhpFiles): array
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $out = [];
        $allOk = true;
        foreach ($relativePhpFiles as $rel) {
            if (!is_string($rel) || str_contains($rel, '..')) {
                continue;
            }
            $full = BASE_PATH . '/' . ltrim(str_replace('\\', '/', $rel), '/');
            if (!is_file($full)) {
                $out[] = ['path' => $rel, 'ok' => false, 'message' => 'missing'];
                $allOk = false;

                continue;
            }
            $cmd = [$php, '-l', $full];
            $des = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $des, $pipes, BASE_PATH, null, ['bypass_shell' => true]);
            if (!is_resource($proc)) {
                $out[] = ['path' => $rel, 'ok' => false, 'message' => 'proc_open failed'];
                $allOk = false;

                continue;
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $msg = trim((string) $stdout . (string) $stderr);
            $ok = $code === 0;
            if (!$ok) {
                $allOk = false;
            }
            $out[] = ['path' => $rel, 'ok' => $ok, 'message' => mb_substr($msg, 0, 500)];
        }

        return ['ok' => $allOk, 'files' => $out];
    }

    /**
     * @return list<string>
     */
    public static function pathsFromMentorConfig(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $m = is_array($evo) ? ($evo['mentor'] ?? []) : [];
        $globs = $m['sanitize_paths_glob'] ?? ['src/app/Core/Evolution/*.php'];
        if (!is_array($globs)) {
            return [];
        }
        $files = [];
        foreach ($globs as $pattern) {
            if (!is_string($pattern) || str_contains($pattern, '..')) {
                continue;
            }
            $relPat = ltrim(str_replace('\\', '/', $pattern), '/');
            $base = BASE_PATH . '/' . $relPat;
            if (!str_contains($relPat, '*') && !str_contains($relPat, '?') && is_file($base) && str_ends_with(strtolower($base), '.php')) {
                $files[] = $relPat;

                continue;
            }
            foreach (glob($base) ?: [] as $f) {
                if (is_string($f) && is_file($f) && str_ends_with(strtolower($f), '.php')) {
                    $files[] = ltrim(str_replace('\\', '/', substr($f, strlen(BASE_PATH))), '/');
                }
            }
        }

        return array_values(array_unique($files));
    }
}
