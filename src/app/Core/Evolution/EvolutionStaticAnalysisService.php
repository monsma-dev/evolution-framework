<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Runs PHPStan at configurable max level on src paths (dev dependency).
 */
final class EvolutionStaticAnalysisService
{
    /**
     * @return array{ok: bool, exit_code: int, stdout: string, stderr: string, report_path?: string}
     */
    public function runPhpStan(Config $config): array
    {
        $sa = $config->get('evolution.static_analysis', []);
        if (!is_array($sa) || !filter_var($sa['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'exit_code' => 0, 'stdout' => '', 'stderr' => 'static_analysis disabled'];
        }

        $level = max(0, min(9, (int) ($sa['phpstan_level'] ?? 9)));
        $paths = $sa['paths'] ?? ['src/app'];
        if (!is_array($paths) || $paths === []) {
            $paths = ['src/app'];
        }
        $timeout = max(60, min(3600, (int) ($sa['timeout_seconds'] ?? 600)));

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $phpstan = BASE_PATH . '/vendor/phpstan/phpstan/phpstan';
        if (!is_file($phpstan)) {
            return ['ok' => false, 'exit_code' => 1, 'stdout' => '', 'stderr' => 'phpstan not installed (composer require-dev phpstan/phpstan)'];
        }

        $args = [$php, $phpstan, 'analyse', '--memory-limit=512M', '--level=' . (string) $level];
        foreach ($paths as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $args[] = BASE_PATH . '/' . ltrim($p, '/');
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($args, $descriptors, $pipes, BASE_PATH, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'exit_code' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
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
            if (time() - $start > $timeout) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                return ['ok' => false, 'exit_code' => 1, 'stdout' => $stdout, 'stderr' => $stderr . "\n[timeout]"];
            }
            usleep(150000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $dir = BASE_PATH . '/storage/evolution/static_analysis';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $report = $dir . '/last_phpstan.txt';
        @file_put_contents($report, $stdout . "\n" . $stderr);

        EvolutionLogger::log('static_analysis', 'phpstan', ['exit_code' => $code, 'level' => $level]);

        return [
            'ok' => $code === 0,
            'exit_code' => $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'report_path' => $report,
        ];
    }
}
