<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Shadow patch storage, validation, and OpenAI repair orchestration.
 */
final class SelfHealingManager
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Flush specific cache keys/prefixes after a patch (atomic, no flushAll).
     * Uses deleteByPrefix for pattern-based invalidation via Redis KEYS or APCu scan.
     *
     * @return list<string> actually flushed tags
     */
    public static function flushCacheTags(array $tags, Container $container): array
    {
        try {
            $cache = $container->get('cache');
        } catch (\Throwable) {
            return [];
        }
        $flushed = [];
        foreach ($tags as $tag) {
            $tag = trim((string)$tag);
            if ($tag === '') {
                continue;
            }
            if (method_exists($cache, 'deleteByPrefix')) {
                $cache->deleteByPrefix($tag);
            } elseif (method_exists($cache, 'delete')) {
                $cache->delete($tag);
            }
            $flushed[] = $tag;
        }
        if ($flushed !== []) {
            EvolutionLogger::log('cache', 'flush_after_patch', ['tags' => $flushed]);
        }

        return $flushed;
    }

    public static function clearTwigCache(): void
    {
        $dirs = [
            BASE_PATH . '/data/cache/twig',
            BASE_PATH . '/data/cache/twig',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir . '/*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    } elseif (is_dir($f)) {
                        self::rmdirRecursive($f);
                    }
                }
            }
        }
    }

    private static function rmdirRecursive(string $dir): void
    {
        $items = glob($dir . '/*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                self::rmdirRecursive($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }

    public static function purgePatch(string $fqcn): void
    {
        PatchExecutionTimer::forgetFqcn($fqcn);
        $path = self::patchFileForClass($fqcn);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
        $reasoning = self::reasoningJsonPathForFqcn($fqcn);
        if ($reasoning !== null && is_file($reasoning)) {
            @unlink($reasoning);
        }
    }

    /**
     * Absolute path to the shadow patch PHP file, or null if FQCN is not allowed.
     */
    public static function shadowPatchPhpPath(string $fqcn): ?string
    {
        return self::patchFileForClass($fqcn);
    }

    /**
     * Sidecar JSON next to the patch: Class.reasoning.json (same directory as Class.php).
     */
    public static function reasoningJsonPathForFqcn(string $fqcn): ?string
    {
        $php = self::patchFileForClass($fqcn);
        if ($php === null) {
            return null;
        }

        return preg_replace('/\.php$/', '.reasoning.json', $php);
    }

    private static function patchesRootStatic(): string
    {
        if (isset(($GLOBALS)['app_container'])) {
            try {
                $cfg = ($GLOBALS)['app_container']->get('config');
                $rel = (string)$cfg->get('evolution.patches_path', 'storage/patches');
                if ($rel !== '') {
                    if (str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rel) === 1) {
                        return $rel;
                    }

                    return BASE_PATH . '/' . ltrim($rel, '/');
                }
            } catch (\Throwable) {
            }
        }

        return BASE_PATH . '/data/patches';
    }

    /**
     * @return list<array{fqcn: string, path: string, bytes: int, mtime: int}>
     */
    public function listPatches(): array
    {
        $root = $this->patchesRoot();
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $full = $file->getPathname();
            if (str_contains($full, DIRECTORY_SEPARATOR . '.meta' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $rel = substr($full, strlen($root) + 1);
            $fqcn = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $rel);
            $out[] = [
                'fqcn' => $fqcn,
                'path' => $full,
                'bytes' => $file->getSize(),
                'mtime' => $file->getMTime(),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['fqcn'], $b['fqcn']));

        return $out;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{ok: bool, raw_json?: array, reply_text?: string, error?: string}
     */
    public function architectChat(
        array $messages,
        string $mode = 'core',
        bool $includeFinancialContext = false,
        int $financialDays = 30,
        ?array $creditContext = null,
        bool $includeWebSearch = false,
        string $webSearchQuery = '',
        ?array $healthSnapshot = null,
        ?string $taskSeverity = null
    ): array {
        $service = new ArchitectChatService($this->container);

        return $service->complete($messages, $mode, $includeFinancialContext, $financialDays, $creditContext, $includeWebSearch, $webSearchQuery, $healthSnapshot, $taskSeverity);
    }

    /**
     * @param array<string, mixed>|null $reasoning Optional explainability payload (bottleneck, arm64_note, expected_gain_ms, …)
     * @return array{ok: bool, path?: string, reasoning_path?: string, error?: string}
     */
    /**
     * Schrijft nooit direct naar het live patch-bestand: altijd naar `.tmp`, daarna atomische {@see rename()}
     * op hetzelfde filesystem (geen half geschreven bestand bij crash).
     */
    public function applyShadowPatch(string $fqcn, string $phpSource, int $actorUserId, ?array $reasoning = null): array
    {
        $okFqcn = self::assertAllowedFqcn($fqcn);
        if ($okFqcn !== null) {
            return ['ok' => false, 'error' => $okFqcn];
        }

        $trimmed = trim($phpSource);
        if (!str_starts_with($trimmed, '<?php')) {
            return ['ok' => false, 'error' => 'Patch must be a full PHP file starting with <?php'];
        }

        $path = self::patchFileForClass($fqcn);
        if ($path === null) {
            return ['ok' => false, 'error' => 'Invalid path'];
        }

        /** @var Config $cfg */
        $cfg = $this->container->get('config');
        $hotBackupPath = null;
        if (HotSwapService::isEnabled($cfg) && is_file($path)) {
            $hotBackupPath = HotSwapService::backupPreviousVersion($fqcn, $path);
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot create patch directory'];
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $phpSource) === false) {
            return ['ok' => false, 'error' => 'Cannot write temp patch'];
        }

        $lint = self::lintFile($tmp);
        if ($lint !== null) {
            @unlink($tmp);

            return ['ok' => false, 'error' => $lint];
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'Cannot activate patch'];
        }

        if (HotSwapService::isEnabled($cfg)) {
            HotSwapService::arm($fqcn, $path, $hotBackupPath);
        }

        PatchExecutionTimer::deleteGuardMeta($fqcn);

        $reasoningPath = self::writeReasoningJson($fqcn, $reasoning, $actorUserId);

        EvolutionLogger::log('patch', 'apply', [
            'fqcn' => $fqcn,
            'path' => $path,
            'actor_user_id' => $actorUserId,
            'reasoning_path' => $reasoningPath,
        ]);

        AgentCodeChangeLogger::append([
            'kind' => 'php_shadow_patch',
            'file' => $path,
            'fqcn' => $fqcn,
            'line_start' => 0,
            'line_end' => 0,
            'agent' => 'Architect',
            'note' => 'shadow patch toegepast (volledig bestand)',
        ]);

        $evo = $cfg->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $provider = strtolower((string)($arch['code_provider'] ?? 'openai'));
        $model = $provider === 'anthropic'
            ? (string)($arch['code_model'] ?? 'claude-3-5-sonnet-20241022')
            : (string)($arch['model'] ?? 'gpt-4o-mini');
        (new CodeDnaRegistry())->record($cfg, [
            'kind' => 'php_shadow',
            'fqcn' => $fqcn,
            'path' => $path,
            'model' => $model,
        ]);

        return ['ok' => true, 'path' => $path, 'reasoning_path' => $reasoningPath];
    }

    /**
     * @param array<string, mixed>|null $reasoning
     */
    private static function writeReasoningJson(string $fqcn, ?array $reasoning, int $actorUserId): ?string
    {
        $target = self::reasoningJsonPathForFqcn($fqcn);
        if ($target === null) {
            return null;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $base = [
            'fqcn' => $fqcn,
            'generated_at' => gmdate('c'),
            'actor_user_id' => $actorUserId,
            'summary' => '',
            'bottleneck' => '',
            'arm64_note' => '',
            'expected_gain_ms' => null,
            'original_baseline_ms' => 10.0,
        ];
        if (is_array($reasoning)) {
            foreach (['summary', 'bottleneck', 'arm64_note', 'expected_gain_ms', 'original_baseline_ms'] as $k) {
                if (array_key_exists($k, $reasoning)) {
                    $base[$k] = $reasoning[$k];
                }
            }
            if (isset($reasoning['reasoning_detail']) && is_array($reasoning['reasoning_detail'])) {
                foreach (['bottleneck', 'arm64_note', 'expected_gain_ms', 'original_baseline_ms'] as $k) {
                    if (array_key_exists($k, $reasoning['reasoning_detail'])) {
                        $base[$k] = $reasoning['reasoning_detail'][$k];
                    }
                }
            }
        }

        $json = json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }

        $tmp = $target . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return null;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);

            return null;
        }

        return $target;
    }

    private function patchesRoot(): string
    {
        /** @var Config $cfg */
        $cfg = $this->container->get('config');
        $rel = (string)$cfg->get('evolution.patches_path', 'storage/patches');
        if ($rel === '') {
            $rel = 'storage/patches';
        }
        if (str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rel) === 1) {
            return $rel;
        }

        return BASE_PATH . '/' . ltrim($rel, '/');
    }

    private static function patchFileForClass(string $fqcn): ?string
    {
        $ok = self::assertAllowedFqcn($fqcn);
        if ($ok !== null) {
            return null;
        }
        $relative = str_replace('\\', '/', substr($fqcn, strlen('App\\')));
        $root = self::patchesRootStatic();

        return $root . '/' . $relative . '.php';
    }

    private static function assertAllowedFqcn(string $fqcn): ?string
    {
        if (!str_starts_with($fqcn, 'App\\')) {
            return 'Class must be in the App namespace';
        }
        if (str_contains($fqcn, '..') || str_contains($fqcn, "\0")) {
            return 'Invalid class name';
        }

        return null;
    }

    /**
     * Dynamic routing: new controllers must live under the Evolution controller namespace (shadow patches).
     */
    public static function assertEvolutionDynamicControllerFqcn(string $fqcn): ?string
    {
        $base = 'App\\Domain\\Web\\Controllers\\Evolution\\';
        if (!str_starts_with($fqcn, $base)) {
            return 'Evolution dynamic controllers must use FQCN prefix ' . $base;
        }

        return self::assertAllowedFqcn($fqcn);
    }

    /**
     * Lint a PHP source string by writing to a temp file.
     * Returns null on success, error message on failure.
     */
    public static function lintSource(string $phpSource): ?string
    {
        $tmp = sys_get_temp_dir() . '/lint_' . bin2hex(random_bytes(6)) . '.php';
        @file_put_contents($tmp, $phpSource);
        $result = self::lintFile($tmp);
        @unlink($tmp);

        return $result;
    }

    private static function lintFile(string $file): ?string
    {
        $php = PHP_BINARY;
        if ($php === '' || $php === 'php') {
            $php = 'php';
        }
        $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1';
        $out = shell_exec($cmd);
        if (!is_string($out)) {
            return 'php -l failed';
        }
        if (str_contains($out, 'No syntax errors')) {
            return null;
        }

        return trim($out) !== '' ? trim($out) : 'Syntax error';
    }
}
