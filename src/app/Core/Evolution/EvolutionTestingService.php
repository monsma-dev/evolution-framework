<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * QA Autopilot: optional PHPUnit gate for shadow PHP applies (auto_apply / auto_refactor).
 * When active + phpunit_test present: write test → apply patch → PHPUnit → optional full suite.
 * On failure: purge patch + remove generated test file.
 */
final class EvolutionTestingService
{
    private const GENERATED_DIR = 'tests/Evolution/Generated';

    public static function isGateActive(Config $config): bool
    {
        $tg = $config->get('evolution.testing_gate', []);

        return is_array($tg) && filter_var($tg['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, mixed> $change one suggested_changes[] item
     * @return array{ok: bool, error?: string, apply_manually?: bool, phpunit?: array<string, mixed>}
     */
    public static function gateShadowPhpApply(
        Config $config,
        Container $container,
        string $fqcn,
        string $php,
        array $change,
        int $actorUserId
    ): array {
        if (!self::isGateActive($config)) {
            return ['ok' => true, 'apply_manually' => true];
        }

        $tg = $config->get('evolution.testing_gate', []);
        if (!is_array($tg)) {
            return ['ok' => true, 'apply_manually' => true];
        }

        $requireBlock = filter_var($tg['require_phpunit_block'] ?? true, FILTER_VALIDATE_BOOL);
        $testBlock = $change['phpunit_test'] ?? null;
        if (!is_array($testBlock)) {
            if ($requireBlock) {
                return [
                    'ok' => false,
                    'error' => 'testing_gate: add phpunit_test { file_contents, class_name } for this PHP change.',
                ];
            }

            return ['ok' => true, 'apply_manually' => true];
        }

        $contents = (string) ($testBlock['file_contents'] ?? '');
        $className = trim((string) ($testBlock['class_name'] ?? ''));
        if (trim($contents) === '' || $className === '') {
            return ['ok' => false, 'error' => 'testing_gate: phpunit_test.file_contents and class_name are required.'];
        }

        if (!str_contains($contents, 'namespace App\\Tests\\Evolution\\Generated')) {
            return [
                'ok' => false,
                'error' => 'testing_gate: test must use namespace App\\Tests\\Evolution\\Generated.',
            ];
        }

        $dir = BASE_PATH . '/' . self::GENERATED_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $className);
        if ($safeName === '' || !str_ends_with($safeName, 'Test')) {
            return ['ok' => false, 'error' => 'testing_gate: class_name must end with Test (e.g. FooBarTest).'];
        }

        $relPath = self::GENERATED_DIR . '/' . $safeName . '.php';
        $absPath = BASE_PATH . '/' . $relPath;
        if (@file_put_contents($absPath, $contents) === false) {
            return ['ok' => false, 'error' => 'testing_gate: cannot write ' . $relPath];
        }

        $manager = new SelfHealingManager($container);
        $apply = $manager->applyShadowPatch($fqcn, $php, $actorUserId, $change['reasoning_detail'] ?? null);
        if (!($apply['ok'] ?? false)) {
            @unlink($absPath);

            return ['ok' => false, 'error' => $apply['error'] ?? 'apply failed', 'phpunit' => ['apply' => $apply]];
        }

        $timeout = max(30, min(600, (int) ($tg['timeout_seconds'] ?? 120)));
        $runFull = filter_var($tg['run_full_suite'] ?? false, FILTER_VALIDATE_BOOL);

        $out1 = self::runPhpUnit(['--filter', $safeName], $timeout);
        if (($out1['exit_code'] ?? 1) !== 0) {
            SelfHealingManager::purgePatch($fqcn);
            @unlink($absPath);

            return [
                'ok' => false,
                'error' => 'testing_gate: generated test failed: ' . mb_substr((string) ($out1['stderr'] ?? '') . (string) ($out1['stdout'] ?? ''), 0, 1200),
                'phpunit' => $out1,
            ];
        }

        if ($runFull) {
            $out2 = self::runPhpUnit([], $timeout);
            if (($out2['exit_code'] ?? 1) !== 0) {
                SelfHealingManager::purgePatch($fqcn);
                @unlink($absPath);

                return [
                    'ok' => false,
                    'error' => 'testing_gate: full suite failed after patch: ' . mb_substr((string) ($out2['stderr'] ?? '') . (string) ($out2['stdout'] ?? ''), 0, 1200),
                    'phpunit' => $out2,
                ];
            }

            EvolutionLogger::log('testing_gate', 'passed', ['fqcn' => $fqcn, 'suite' => 'generated+full']);

            return ['ok' => true, 'phpunit' => ['generated' => $out1, 'full' => $out2]];
        }

        EvolutionLogger::log('testing_gate', 'passed', ['fqcn' => $fqcn, 'suite' => 'generated']);

        return ['ok' => true, 'phpunit' => ['generated' => $out1]];
    }

    /**
     * @param list<string> $extraArgs
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function runPhpUnit(array $extraArgs, int $timeoutSeconds): array
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $phpunit = BASE_PATH . '/vendor/phpunit/phpunit/phpunit';
        if (!is_file($phpunit)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'phpunit not found'];
        }

        $cmd = array_merge(
            [$php, $phpunit, '--configuration', BASE_PATH . '/phpunit.xml'],
            $extraArgs
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, BASE_PATH, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            if (time() - $start > $timeoutSeconds) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                return ['exit_code' => 1, 'stdout' => $stdout, 'stderr' => $stderr . "\n[timeout]"];
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['exit_code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
