<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * PSR-4-safe single-file moves under src/app with namespace rewrite + composer dump-autoload.
 * Use from Architect/cron — not exposed to untrusted HTTP.
 *
 * Does not modify `.env`. Database host/version changes must go through EvolutionConfigService::updateEnvKeys()
 * / EvolutionEnvGuardService (backup + HotSwap arm + connection check).
 */
final class StructuralRefactorService
{
    private const APP_PREFIX = 'src/app/';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Move a PHP class file to a new path under src/app, update `namespace` line, regenerate Composer autoload.
     *
     * @return array{ok: bool, error?: string, from?: string, to?: string}
     */
    public function relocatePhpClass(string $relativeFrom, string $relativeTo): array
    {
        $cfg = $this->container->get('config');
        $sr = $cfg->get('evolution.structural_refactor', []);
        if (!is_array($sr) || !filter_var($sr['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'evolution.structural_refactor.enabled is false'];
        }

        $from = $this->normalizeRelative($relativeFrom);
        $to = $this->normalizeRelative($relativeTo);
        if ($from === null || $to === null) {
            return ['ok' => false, 'error' => 'invalid path'];
        }
        if (!str_ends_with($from, '.php') || !str_ends_with($to, '.php')) {
            return ['ok' => false, 'error' => 'only .php class files'];
        }
        if (!str_starts_with($from, self::APP_PREFIX) || !str_starts_with($to, self::APP_PREFIX)) {
            return ['ok' => false, 'error' => 'paths must stay under src/app/'];
        }
        if ($from === $to) {
            return ['ok' => false, 'error' => 'source and destination identical'];
        }

        $absFrom = BASE_PATH . '/' . $from;
        $absTo = BASE_PATH . '/' . $to;
        if (!is_file($absFrom)) {
            return ['ok' => false, 'error' => 'source file missing'];
        }
        $root = realpath(BASE_PATH . '/src/app');
        $realFrom = realpath($absFrom);
        if ($root === false || $realFrom === false || !str_starts_with($realFrom, $root)) {
            return ['ok' => false, 'error' => 'source outside src/app'];
        }

        $dirTo = dirname($absTo);
        if (!is_dir($dirTo) && !@mkdir($dirTo, 0755, true) && !is_dir($dirTo)) {
            return ['ok' => false, 'error' => 'cannot create destination directory'];
        }

        HotSwapService::disarm();

        $php = (string) @file_get_contents($absFrom);
        if ($php === '') {
            return ['ok' => false, 'error' => 'empty source'];
        }

        $nsOld = self::namespaceFromAppPath($from);
        $nsNew = self::namespaceFromAppPath($to);
        if ($nsNew === null) {
            return ['ok' => false, 'error' => 'cannot derive target namespace'];
        }

        $updated = preg_replace(
            '/^namespace\s+[^;]+;/m',
            'namespace ' . $nsNew . ';',
            $php,
            1,
            $count
        );
        if (!is_string($updated) || $count < 1) {
            return ['ok' => false, 'error' => 'namespace declaration not found or not updated'];
        }

        if (@file_put_contents($absTo, $updated) === false) {
            return ['ok' => false, 'error' => 'cannot write destination'];
        }
        if (!@unlink($absFrom)) {
            @unlink($absTo);

            return ['ok' => false, 'error' => 'cannot remove source after copy'];
        }

        $auto = ComposerAutoloadService::dumpAutoload($cfg);
        if (!($auto['ok'] ?? false)) {
            @rename($absTo, $absFrom);
            @file_put_contents($absFrom, $php);

            return ['ok' => false, 'error' => 'composer dump-autoload failed: ' . ($auto['error'] ?? 'unknown'), 'from' => $from, 'to' => $to];
        }

        OpcacheIntelligenceService::invalidateFiles([$absTo]);
        EvolutionLogger::log('structural_refactor', 'relocate', [
            'from' => $from,
            'to' => $to,
            'namespace_from' => $nsOld,
            'namespace_to' => $nsNew,
        ]);

        $shortName = basename($from, '.php');
        if ($nsOld !== null && $nsNew !== null) {
            $oldFqcn = $nsOld . '\\' . $shortName;
            $newFqcn = $nsNew . '\\' . $shortName;
            (new EvolutionConfigService($this->container))->replaceFqcn($oldFqcn, $newFqcn);
        }

        return ['ok' => true, 'from' => $from, 'to' => $to];
    }

    private function normalizeRelative(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    private static function namespaceFromAppPath(string $relativeFrom): ?string
    {
        if (!str_starts_with($relativeFrom, self::APP_PREFIX)) {
            return null;
        }
        $rel = substr($relativeFrom, strlen(self::APP_PREFIX));
        $dir = dirname($rel);
        if ($dir === '.' || $dir === '') {
            return 'App';
        }

        return 'App\\' . str_replace('/', '\\', $dir);
    }
}
