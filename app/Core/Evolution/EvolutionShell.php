<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use PDO;

/**
 * EvolutionShell — secured terminal for Evolution Architect agents.
 *
 * Only whitelisted commands are allowed. All executions are audited via
 * ComplianceLogger. The Police-Agent must approve the command before exec.
 *
 * Enable in evolution.json: { "toolbox": { "shell_enabled": true } }
 */
final class EvolutionShell
{
    private const WHITELIST = [
        'composer dump-autoload',
        'composer dump-autoload --optimize',
        'composer install --no-dev',
        'npm run build',
        'npm --prefix tooling run build',
        'php framework opcache:preload-generate',
        'php framework cache:clear',
        'php framework migrate:run',
        'php framework warmup',
        'docker compose restart app',
        'docker compose restart evolution-worker',
        'find public/storage/cache/twig -type f -delete',
    ];

    private const MAX_TIMEOUT_SECONDS = 60;

    /**
     * @return array{ok: bool, stdout?: string, stderr?: string, exit_code?: int, error?: string}
     */
    public static function runTerminalCommand(Config $config, string $command, ?PDO $db = null): array
    {
        $evo = $config->get('evolution', []);
        $tb  = is_array($evo) ? ($evo['toolbox'] ?? []) : [];

        if (!is_array($tb) || !filter_var($tb['shell_enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'EvolutionShell disabled — set evolution.toolbox.shell_enabled = true.'];
        }

        $command = trim($command);

        if (!self::isWhitelisted($command)) {
            if ($db) {
                ComplianceLogger::log($db, 'EvolutionShell', ComplianceLogger::ACTION_PROMPT_BLOCKED,
                    'Blocked non-whitelisted shell command: ' . mb_substr($command, 0, 200));
            }
            return ['ok' => false, 'error' => 'Command not in whitelist: ' . $command];
        }

        $appRoot = $config->get('app.root', '/var/www/html');
        if (!is_string($appRoot)) {
            $appRoot = defined('BASE_PATH') ? BASE_PATH : '/var/www/html';
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $appRoot,
            ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']
        );

        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Failed to start process.'];
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = time() + self::MAX_TIMEOUT_SECONDS;

        while (time() < $deadline) {
            $stdout .= (string)fread($pipes[1], 4096);
            $stderr .= (string)fread($pipes[2], 4096);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(100_000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $ok = $exitCode === 0;

        if ($db) {
            ComplianceLogger::log($db, 'EvolutionShell', $ok ? 'SHELL_EXEC_OK' : 'SHELL_EXEC_FAILED',
                'cmd=' . mb_substr($command, 0, 200) . ' exit=' . $exitCode);
        }

        EvolutionLogger::log('shell', $ok ? 'exec_ok' : 'exec_failed', [
            'cmd'       => $command,
            'exit_code' => $exitCode,
            'stderr'    => mb_substr($stderr, 0, 500),
        ]);

        return [
            'ok'        => $ok,
            'stdout'    => mb_substr($stdout, 0, 8000),
            'stderr'    => mb_substr($stderr, 0, 2000),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @return array{ok: bool, error?: string, note?: string}
     */
    public static function evaluatePhp(Config $config, string $code): array
    {
        return ['ok' => false, 'error' => 'PHP eval disabled — use runTerminalCommand() for safe execution.'];
    }

    /** @return list<string> */
    public static function getWhitelist(): array
    {
        return self::WHITELIST;
    }

    private static function isWhitelisted(string $command): bool
    {
        foreach (self::WHITELIST as $allowed) {
            if ($command === $allowed || str_starts_with($command, $allowed . ' ')) {
                return true;
            }
        }
        return false;
    }
}
