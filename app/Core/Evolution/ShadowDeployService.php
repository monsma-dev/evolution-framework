<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Shadow Deploy: writes a .shadow copy of a proposed file, runs tests against it,
 * compares DNA scores, and presents the result for one-click swap.
 *
 * The shadow file is NOT loaded by the autoloader — it sits dormant until
 * the admin approves the swap via the dashboard.
 */
final class ShadowDeployService
{
    private const SHADOW_DIR = 'storage/evolution/shadow_deploys';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Create a shadow version of a class file for testing.
     *
     * @return array{ok: bool, shadow_id?: string, shadow_path?: string, original_score?: int, shadow_score?: int, improvement?: int, tests_passed?: bool, test_output?: string, error?: string}
     */
    public function createShadow(string $fqcn, string $phpSource): array
    {
        $config = $this->container->get('config');
        $settings = $this->getSettings($config);
        if (!$settings['enabled']) {
            return ['ok' => false, 'error' => 'Shadow deploy disabled'];
        }

        $trimmed = trim($phpSource);
        if (!str_starts_with($trimmed, '<?php')) {
            return ['ok' => false, 'error' => 'Shadow source must start with <?php'];
        }

        $lint = SelfHealingManager::lintSource($phpSource);
        if ($lint !== null) {
            return ['ok' => false, 'error' => 'Lint failed: ' . $lint];
        }

        $dir = BASE_PATH . '/' . self::SHADOW_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create shadow directory'];
        }

        $shadowId = 'shadow-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $relative = str_replace('\\', '/', substr($fqcn, 4));
        $shadowPath = $dir . '/' . $shadowId . '--' . str_replace('/', '--', $relative) . '.php';

        if (@file_put_contents($shadowPath, $phpSource) === false) {
            return ['ok' => false, 'error' => 'Cannot write shadow file'];
        }

        $dna = new CodeDnaScoringService();
        $originalScore = $dna->scoreClass($fqcn)['score'];
        $shadowScore = $this->scoreShadowSource($phpSource, $fqcn);
        $improvement = $shadowScore - $originalScore;

        $testsPassed = null;
        $testOutput = '';
        if ($settings['run_tests']) {
            $testResult = $this->runTestsAgainstShadow($fqcn, $shadowPath);
            $testsPassed = $testResult['passed'];
            $testOutput = $testResult['output'];
        }

        $meta = [
            'shadow_id' => $shadowId,
            'fqcn' => $fqcn,
            'shadow_path' => $shadowPath,
            'original_score' => $originalScore,
            'shadow_score' => $shadowScore,
            'improvement' => $improvement,
            'tests_passed' => $testsPassed,
            'created_at' => gmdate('c'),
            'status' => 'pending',
        ];
        @file_put_contents($shadowPath . '.meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $canSwap = true;
        if ($settings['require_dna_improvement'] && $improvement <= 0) {
            $canSwap = false;
        }
        if ($testsPassed === false) {
            $canSwap = false;
        }

        EvolutionLogger::log('shadow_deploy', 'created', [
            'shadow_id' => $shadowId,
            'fqcn' => $fqcn,
            'original_score' => $originalScore,
            'shadow_score' => $shadowScore,
            'improvement' => $improvement,
            'tests_passed' => $testsPassed,
            'can_swap' => $canSwap,
        ]);

        return [
            'ok' => true,
            'shadow_id' => $shadowId,
            'shadow_path' => $shadowPath,
            'original_score' => $originalScore,
            'shadow_score' => $shadowScore,
            'improvement' => $improvement,
            'tests_passed' => $testsPassed,
            'test_output' => $testOutput,
            'can_swap' => $canSwap,
        ];
    }

    /**
     * Swap a shadow deploy into the live shadow patch.
     */
    public function swap(string $shadowId, int $actorUserId): array
    {
        $block = StagingMirrorGateService::reasonIfBlocked($this->container->get('config'));
        if ($block !== null) {
            return ['ok' => false, 'error' => $block];
        }

        $dir = BASE_PATH . '/' . self::SHADOW_DIR;
        $metaFiles = glob($dir . '/' . $shadowId . '--*.meta.json') ?: [];
        if ($metaFiles === []) {
            return ['ok' => false, 'error' => 'Shadow deploy not found: ' . $shadowId];
        }

        $metaPath = $metaFiles[0];
        $meta = @json_decode((string)@file_get_contents($metaPath), true);
        if (!is_array($meta)) {
            return ['ok' => false, 'error' => 'Cannot read shadow metadata'];
        }

        $fqcn = (string)($meta['fqcn'] ?? '');
        $shadowPath = (string)($meta['shadow_path'] ?? '');
        if (!is_file($shadowPath)) {
            return ['ok' => false, 'error' => 'Shadow file missing'];
        }

        $phpSource = @file_get_contents($shadowPath);
        if (!is_string($phpSource)) {
            return ['ok' => false, 'error' => 'Cannot read shadow file'];
        }

        $manager = new SelfHealingManager($this->container);
        $result = $manager->applyShadowPatch($fqcn, $phpSource, $actorUserId, [
            'bottleneck' => 'Low DNA score — auto-refactored via shadow deploy',
            'shadow_id' => $shadowId,
            'original_score' => $meta['original_score'] ?? 0,
            'shadow_score' => $meta['shadow_score'] ?? 0,
        ]);

        if ($result['ok'] ?? false) {
            OpcacheIntelligenceService::invalidateForPatch($fqcn);
            $meta['status'] = 'swapped';
            $meta['swapped_at'] = gmdate('c');
            $meta['actor'] = $actorUserId;
            @file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            EvolutionLogger::log('shadow_deploy', 'swapped', [
                'shadow_id' => $shadowId,
                'fqcn' => $fqcn,
                'actor' => $actorUserId,
            ]);
        }

        return $result;
    }

    /**
     * List pending shadow deploys.
     *
     * @return list<array<string, mixed>>
     */
    public function listPending(): array
    {
        $dir = BASE_PATH . '/' . self::SHADOW_DIR;
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.meta.json') ?: [];
        $pending = [];
        foreach ($files as $f) {
            $meta = @json_decode((string)@file_get_contents($f), true);
            if (is_array($meta) && ($meta['status'] ?? '') === 'pending') {
                $pending[] = $meta;
            }
        }

        return $pending;
    }

    private function scoreShadowSource(string $source, string $fqcn): int
    {
        $tmp = sys_get_temp_dir() . '/shadow_score_' . bin2hex(random_bytes(4)) . '.php';
        @file_put_contents($tmp, $source);
        $dna = new CodeDnaScoringService();
        $result = $dna->scoreClass($fqcn);
        @unlink($tmp);

        $lines = substr_count($source, "\n") + 1;
        $methods = preg_match_all('/\b(public|protected|private)\s+(?:static\s+)?function\s+\w+/i', $source);
        $maxNesting = 0;
        $depth = 0;
        for ($i = 0, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
                $maxNesting = max($maxNesting, $depth);
            } elseif ($source[$i] === '}') {
                $depth = max(0, $depth - 1);
            }
        }

        $score = 10;
        if ($lines > 500) { $score -= 2; } elseif ($lines > 300) { $score -= 1; }
        if ($methods > 20) { $score -= 2; } elseif ($methods > 12) { $score -= 1; }
        if ($maxNesting > 6) { $score -= 2; } elseif ($maxNesting > 4) { $score -= 1; }

        return max(1, min(10, $score));
    }

    /**
     * @return array{passed: bool, output: string}
     */
    private function runTestsAgainstShadow(string $fqcn, string $shadowPath): array
    {
        $relative = str_replace('\\', '/', substr($fqcn, 4));
        $testFile = BASE_PATH . '/tests/' . $relative . 'Test.php';

        if (!is_file($testFile)) {
            return ['passed' => true, 'output' => 'No test file found — skipped'];
        }

        $origPath = BASE_PATH . '/app/' . $relative . '.php';
        $backup = null;
        if (is_file($origPath)) {
            $backup = @file_get_contents($origPath);
            @copy($shadowPath, $origPath);
        }

        $phpunit = BASE_PATH . '/vendor/bin/phpunit';
        if (!is_file($phpunit)) {
            if ($backup !== null) {
                @file_put_contents($origPath, $backup);
            }
            return ['passed' => true, 'output' => 'PHPUnit not installed — skipped'];
        }

        $cmd = 'php ' . escapeshellarg($phpunit) . ' ' . escapeshellarg($testFile) . ' --no-coverage 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);

        if ($backup !== null) {
            @file_put_contents($origPath, $backup);
        }

        return [
            'passed' => $code === 0,
            'output' => implode("\n", array_slice($out, -10)),
        ];
    }

    /**
     * @return array{enabled: bool, run_tests: bool, require_dna_improvement: bool}
     */
    private function getSettings(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];
        $sd = is_array($aa) ? ($aa['shadow_deploy'] ?? []) : [];

        return [
            'enabled' => is_array($sd) && filter_var($sd['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'run_tests' => is_array($sd) && filter_var($sd['run_tests'] ?? true, FILTER_VALIDATE_BOOL),
            'require_dna_improvement' => is_array($sd) && filter_var($sd['require_dna_improvement'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }
}
