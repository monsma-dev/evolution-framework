<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use App\Support\Ops\JitWarmup;

/**
 * Ghost HTTP warmup after shadow patch apply so {@see PatchExecutionTimer} can measure quickly.
 */
final class PatchWarmupService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param list<string>|null $overridePaths Relative paths from site root (optional)
     * @return array{ok: bool, skipped?: bool, reason?: string, paths?: list<string>, results?: list<array{url: string, status: int, ms: float}>, warmed?: int, error?: string}
     */
    public function run(?string $fqcn, ?array $overridePaths): array
    {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        $pw = is_array($evo) ? ($evo['patch_warmup'] ?? []) : [];
        if (is_array($pw) && !filter_var($pw['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'patch_warmup disabled'];
        }

        $paths = [];
        if (is_array($overridePaths)) {
            foreach ($overridePaths as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $paths[] = '/' . ltrim(trim($p), '/');
                }
            }
        }
        if ($paths === []) {
            $paths = is_array($pw) && isset($pw['paths']) && is_array($pw['paths']) ? $pw['paths'] : [];
        }
        if ($paths === []) {
            $paths = ['/', '/browse'];
        }

        $byFqcn = is_array($pw) ? ($pw['paths_by_fqcn'] ?? []) : [];
        if ($fqcn !== null && $fqcn !== '' && is_array($byFqcn) && isset($byFqcn[$fqcn]) && is_array($byFqcn[$fqcn])) {
            foreach ($byFqcn[$fqcn] as $extra) {
                if (is_string($extra) && trim($extra) !== '') {
                    $paths[] = '/' . ltrim(trim($extra), '/');
                }
            }
        }

        $paths = array_values(array_unique($paths));
        $max = max(1, min(12, (int)(is_array($pw) ? ($pw['max_paths'] ?? 8) : 8)));
        if (count($paths) > $max) {
            $paths = array_slice($paths, 0, $max);
        }

        $base = rtrim((string)$config->get('site.url', 'http://127.0.0.1'), '/');
        if ($base === '') {
            $base = 'http://127.0.0.1';
        }

        try {
            $warm = new JitWarmup($base, null, $config);
            $results = $warm->ghostWarmPaths($paths);
            $warmed = 0;
            foreach ($results as $r) {
                if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 400) {
                    ++$warmed;
                }
            }

            EvolutionLogger::log('patch_warmup', 'ghost', [
                'fqcn' => $fqcn,
                'paths' => $paths,
                'warmed' => $warmed,
            ]);

            return [
                'ok' => true,
                'paths' => $paths,
                'results' => $results,
                'warmed' => $warmed,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
