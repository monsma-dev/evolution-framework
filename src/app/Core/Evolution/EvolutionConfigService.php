<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * After PSR-4 moves (StructuralRefactorService), updates allowlisted config/bootstrap files
 * that reference the old FQCN. Critical files (e.g. src/bootstrap/app.php) use backup + optional HotSwap arm + php -l gate.
 */
final class EvolutionConfigService
{
    private const BOOTSTRAP_REL = 'src/bootstrap/app.php';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, files_changed?: int, touched?: list<string>, error?: string}
     */
    public function replaceFqcn(string $oldFqcn, string $newFqcn): array
    {
        $cfg = $this->container->get('config');
        $ec = $cfg->get('evolution.config_evolution', []);
        if (!is_array($ec) || !filter_var($ec['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'files_changed' => 0, 'touched' => []];
        }

        $oldFqcn = trim($oldFqcn);
        $newFqcn = trim($newFqcn);
        if ($oldFqcn === '' || $newFqcn === '' || $oldFqcn === $newFqcn) {
            return ['ok' => false, 'error' => 'invalid FQCN pair'];
        }

        $pairs = $this->replacementPairs($oldFqcn, $newFqcn);
        $targets = $this->collectTargetFiles();
        $changed = 0;
        $touched = [];

        foreach ($targets as $abs) {
            if (!is_file($abs)) {
                continue;
            }
            $raw = (string) @file_get_contents($abs);
            if ($raw === '') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($abs, strlen(BASE_PATH) + 1));
            $isBootstrap = $rel === self::BOOTSTRAP_REL;

            $next = $raw;
            foreach ($pairs as [$from, $to]) {
                if ($from !== '' && str_contains($next, $from)) {
                    $next = str_replace($from, $to, $next);
                }
            }
            if ($next === $raw) {
                continue;
            }

            if ($isBootstrap) {
                $backup = HotSwapService::backupArbitraryFile($abs);
                if ($backup === null) {
                    return ['ok' => false, 'error' => 'cannot backup bootstrap before FQCN replace'];
                }
                HotSwapService::arm('__bootstrap__', $abs, $backup);
            }

            if (@file_put_contents($abs, $next) === false) {
                if ($isBootstrap) {
                    HotSwapService::disarm();
                }

                return ['ok' => false, 'error' => 'cannot write ' . $rel];
            }

            if ($isBootstrap) {
                $lint = $this->phpLint($abs);
                if (!$lint['ok']) {
                    @copy($backup, $abs);
                    HotSwapService::disarm();
                    OpcacheIntelligenceService::invalidateFiles([$abs]);

                    return ['ok' => false, 'error' => 'bootstrap php -l failed after FQCN replace: ' . ($lint['output'] ?? '')];
                }
                OpcacheIntelligenceService::invalidateFiles([$abs]);
                HotSwapService::disarm();
            }

            $changed++;
            $touched[] = $rel;
            EvolutionLogger::log('config_evolution', 'fqcn_replace', ['file' => $rel, 'from' => $oldFqcn, 'to' => $newFqcn]);
        }

        return ['ok' => true, 'files_changed' => $changed, 'touched' => $touched];
    }

    /**
     * Safe .env updates (backup + HotSwap arm + DB verify for DB_* keys). Use when AI adjusts DB host/version.
     *
     * @param array<string, string> $keyValues
     * @return array{ok: bool, error?: string, backup?: string}
     */
    public function updateEnvKeys(array $keyValues): array
    {
        return EvolutionEnvGuardService::applyKeyUpdates($this->container, $keyValues);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function replacementPairs(string $oldFqcn, string $newFqcn): array
    {
        return [
            [$oldFqcn, $newFqcn],
            [str_replace('\\', '\\\\', $oldFqcn), str_replace('\\', '\\\\', $newFqcn)],
        ];
    }

    /**
     * @return list<string> absolute paths
     */
    private function collectTargetFiles(): array
    {
        $out = [];
        $configDir = BASE_PATH . '/src/config';
        if (is_dir($configDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($configDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $ext = strtolower($f->getExtension());
                    if (in_array($ext, ['json', 'php'], true)) {
                        $out[] = $f->getPathname();
                    }
                }
            }
        }
        $bootstrap = BASE_PATH . '/' . self::BOOTSTRAP_REL;
        if (is_file($bootstrap)) {
            $out[] = $bootstrap;
        }
        $envEx = BASE_PATH . '/.env.example';
        if (is_file($envEx)) {
            $out[] = $envEx;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array{ok: bool, output?: string}
     */
    private function phpLint(string $absPath): array
    {
        $php = PHP_BINARY;
        if (!is_file($php) || !is_executable($php)) {
            $php = 'php';
        }
        $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($absPath) . ' 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        $combined = trim(implode("\n", $out));

        return ['ok' => $code === 0, 'output' => $combined];
    }
}
