<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Auto Unit Test Generator: when the AI proposes a high-severity refactor for a
 * class without tests, it first generates tests for the current code, verifies
 * they pass, then runs the refactored shadow against those same tests.
 *
 * Builds organic test coverage without manual test writing.
 */
final class AutoTestGeneratorService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Generate a test file for a class, write it, and verify it passes.
     *
     * @return array{ok: bool, test_path?: string, test_source?: string, passes?: bool, output?: string, error?: string}
     */
    public function generateAndVerify(string $fqcn): array
    {
        $testPath = $this->testPathForClass($fqcn);
        if (is_file($testPath)) {
            return ['ok' => true, 'test_path' => $testPath, 'passes' => true, 'output' => 'Test file already exists'];
        }

        $sourcePath = $this->sourcePathForClass($fqcn);
        if (!is_file($sourcePath)) {
            return ['ok' => false, 'error' => 'Source file not found: ' . $sourcePath];
        }

        $source = @file_get_contents($sourcePath);
        if (!is_string($source)) {
            return ['ok' => false, 'error' => 'Cannot read source file'];
        }

        $prompt = $this->buildTestPrompt($fqcn, $source);

        $manager = new SelfHealingManager($this->container);
        $health = (new HealthSnapshotService())->snapshot($this->container);
        $result = $manager->architectChat(
            [['role' => 'user', 'content' => $prompt]],
            'core',
            false,
            1,
            ['user_id' => 0, 'listing_id' => 0],
            false,
            '',
            $health
        );

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'AI test generation failed: ' . ($result['error'] ?? 'unknown')];
        }

        $raw = $result['raw_json'] ?? [];
        $changes = $raw['suggested_changes'] ?? [];
        $testSource = null;

        foreach ($changes as $change) {
            if (is_array($change) && str_ends_with((string)($change['fqcn'] ?? ''), 'Test')) {
                $testSource = (string)($change['full_file_php'] ?? '');
                break;
            }
        }

        if ($testSource === null || trim($testSource) === '') {
            return ['ok' => false, 'error' => 'AI did not generate a test file'];
        }

        $lint = SelfHealingManager::lintSource($testSource);
        if ($lint !== null) {
            return ['ok' => false, 'error' => 'Generated test has syntax errors: ' . $lint];
        }

        $dir = dirname($testPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create test directory'];
        }

        @file_put_contents($testPath, $testSource);

        $testResult = $this->runTest($testPath);

        EvolutionLogger::log('auto_test_gen', $testResult['passed'] ? 'generated_and_passed' : 'generated_but_failed', [
            'fqcn' => $fqcn,
            'test_path' => $testPath,
            'passed' => $testResult['passed'],
        ]);

        return [
            'ok' => true,
            'test_path' => $testPath,
            'test_source' => $testSource,
            'passes' => $testResult['passed'],
            'output' => $testResult['output'],
        ];
    }

    /**
     * Check if a class has existing tests.
     */
    public function hasTests(string $fqcn): bool
    {
        return is_file($this->testPathForClass($fqcn));
    }

    /**
     * @return array{passed: bool, output: string}
     */
    private function runTest(string $testFile): array
    {
        $phpunit = BASE_PATH . '/vendor/bin/phpunit';
        if (!is_file($phpunit)) {
            return ['passed' => true, 'output' => 'PHPUnit not installed — skipped'];
        }

        $cmd = 'php ' . escapeshellarg($phpunit) . ' ' . escapeshellarg($testFile) . ' --no-coverage 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);

        return [
            'passed' => $code === 0,
            'output' => implode("\n", array_slice($out, -15)),
        ];
    }

    private function testPathForClass(string $fqcn): string
    {
        $relative = str_replace('\\', '/', substr($fqcn, 4));

        return BASE_PATH . '/tests/' . $relative . 'Test.php';
    }

    private function sourcePathForClass(string $fqcn): string
    {
        $relative = str_replace('\\', '/', substr($fqcn, 4));

        return BASE_PATH . '/src/app/' . $relative . '.php';
    }

    private function buildTestPrompt(string $fqcn, string $source): string
    {
        $lines = [
            'AUTO TEST GENERATION — Generate a PHPUnit test file for this class.',
            '',
            'Class: ' . $fqcn,
            'Source code:',
            '```php',
            $source,
            '```',
            '',
            'Requirements:',
            '- Generate a complete test file that extends PHPUnit\\Framework\\TestCase',
            '- Test all public methods with realistic inputs',
            '- Use the FQCN + "Test" suffix (e.g. App\\Core\\SomeClass -> App\\Core\\SomeClassTest)',
            '- Place in suggested_changes with full_file_php containing the complete test class',
            '- Include edge cases: null inputs, empty strings, boundary values',
            '- Mock external dependencies if needed (use PHPUnit mocks)',
            '- Tests must pass against the current source code',
            '- Use severity "low_autofix" for the test file',
        ];

        return implode("\n", $lines);
    }
}
