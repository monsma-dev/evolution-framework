<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Planned atomic deploy steps (shadow dir + symlink swap). Uses EVOLUTION_DEPLOY_SSH_IDENTITY (.pem path) when set.
 */
final class EvolutionDeployDroneService
{
    /**
     * @return array{ok: bool, dry_run: bool, commands: list<string>, ssh_identity?: string, error?: string}
     */
    public static function planAtomicSwap(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $d = is_array($evo) ? ($evo['deploy'] ?? []) : [];
        if (!is_array($d) || !filter_var($d['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'dry_run' => true, 'commands' => ['# deploy.enabled=false — set evolution.deploy.enabled and EVOLUTION_DEPLOY_SSH_* in .env']];
        }

        $hostEnv = (string) ($d['ssh_host_env'] ?? 'EVOLUTION_DEPLOY_SSH_HOST');
        $host = trim((string) (getenv($hostEnv) ?: ''));
        $user = (string) ($d['ssh_user'] ?? 'ubuntu');
        $shadow = (string) ($d['remote_shadow_path'] ?? '/var/www/html_shadow');
        $live = (string) ($d['remote_live_symlink'] ?? '/var/www/html');
        $projectRoot = trim((string) ($d['remote_project_root'] ?? '/var/www/html'));
        $health = trim((string) ($d['health_check_url'] ?? ''));

        $identity = trim((string) (getenv('EVOLUTION_DEPLOY_SSH_IDENTITY') ?: ''));
        if ($identity === '' && isset($d['ssh_identity_path']) && is_string($d['ssh_identity_path'])) {
            $identity = trim($d['ssh_identity_path']);
        }

        $sshOpts = '-o StrictHostKeyChecking=accept-new';
        $sshIdentity = '';
        if ($identity !== '') {
            $sshIdentity = '-i ' . escapeshellarg($identity) . ' ';
        }
        $remote = $user . '@' . $host;
        $sshCmd = 'ssh ' . $sshIdentity . $sshOpts . ' ' . escapeshellarg($remote);

        $commands = [];
        if ($host === '') {
            $commands[] = '# Set ' . $hostEnv . ' (e.g. ec2-….compute.amazonaws.com)';
            $liveDeploy = filter_var(getenv('EVOLUTION_DEPLOY_LIVE') ?: '', FILTER_VALIDATE_BOOL);

            return [
                'ok' => true,
                'dry_run' => !$liveDeploy,
                'commands' => $commands,
                'ssh_identity' => $identity !== '' ? $identity : null,
            ];
        }

        if ($identity !== '' && !is_file($identity)) {
            return [
                'ok' => false,
                'dry_run' => true,
                'commands' => ['# SSH identity file not found: ' . $identity],
                'error' => 'ssh_identity_missing',
            ];
        }

        $commands[] = '# 1) Connectivity (run from dev machine)';
        $commands[] = $sshCmd . ' ' . escapeshellarg('echo Sovereign_deploy_ping_OK && hostname && pwd');

        $commands[] = '# 2) After new code is on the server (git pull / rsync), from project root:';
        $commands[] = $sshCmd . ' ' . escapeshellarg('cd ' . $projectRoot . ' && php ai_bridge.php evolution:sovereign-preflight');
        $commands[] = $sshCmd . ' ' . escapeshellarg('cd ' . $projectRoot . ' && php ai_bridge.php evolution:native-compile status');

        if ($health !== '') {
            $commands[] = '# 3) Health check';
            $commands[] = 'curl -fsS ' . escapeshellarg($health) . ' >/dev/null && echo health_ok';
        }

        $commands[] = '# 4) Atomic symlink swap (adjust paths to match server layout):';
        $commands[] = $sshCmd . ' ' . escapeshellarg('ln -sfn ' . $shadow . ' ' . $live . '_next && mv -Tf ' . $live . '_next ' . $live);

        $commands[] = '# 5) Restart PHP-FPM / Apache and evolution-worker as on your host (systemd/docker).';
        $commands[] = '# 6) composer on server: composer dump-autoload -o (in project root).';

        $liveDeploy = filter_var(getenv('EVOLUTION_DEPLOY_LIVE') ?: '', FILTER_VALIDATE_BOOL);

        return [
            'ok' => true,
            'dry_run' => !$liveDeploy,
            'commands' => $commands,
            'ssh_identity' => $identity !== '' ? $identity : null,
        ];
    }
}
