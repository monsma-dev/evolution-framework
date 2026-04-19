<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Opt-in composer require/remove for Evolution (Ghost / manual). Blocks major version jumps unless explicitly allowed.
 */
final class EvolutionComposerService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, output?: string, error?: string}
     */
    public function requirePackage(string $package, string $versionConstraint, bool $ghostMode): array
    {
        $cfg = $this->container->get('config');
        $ce = $cfg->get('evolution.composer_evolution', []);
        if (!is_array($ce) || !filter_var($ce['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'evolution.composer_evolution.enabled is false'];
        }
        $package = trim($package);
        $versionConstraint = trim($versionConstraint);
        if ($package === '' || !preg_match('#^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]+$#i', $package)) {
            return ['ok' => false, 'error' => 'invalid package name'];
        }

        if ($ghostMode && !filter_var($ce['allow_major_in_ghost'] ?? false, FILTER_VALIDATE_BOOL)) {
            $block = $this->wouldBumpMajor($package, $versionConstraint);
            if ($block !== null) {
                return ['ok' => false, 'error' => $block];
            }
        }

        $bin = $this->findComposer();
        if ($bin === null) {
            return ['ok' => false, 'error' => 'composer binary not found'];
        }

        $arg = $package . ':' . $versionConstraint;
        $cmd = escapeshellarg($bin) . ' require ' . escapeshellarg($arg)
            . ' --no-interaction --no-ansi --working-dir=' . escapeshellarg(BASE_PATH) . ' 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        $combined = trim(implode("\n", $out));

        if ($code !== 0) {
            EvolutionLogger::log('composer_evolution', 'require_failed', ['package' => $package, 'code' => $code]);

            return ['ok' => false, 'error' => 'composer require failed: ' . $combined];
        }
        EvolutionLogger::log('composer_evolution', 'require_ok', ['package' => $package]);

        return ['ok' => true, 'output' => $combined];
    }

    /**
     * @return array{ok: bool, output?: string, error?: string}
     */
    public function removePackage(string $package): array
    {
        $cfg = $this->container->get('config');
        $ce = $cfg->get('evolution.composer_evolution', []);
        if (!is_array($ce) || !filter_var($ce['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'evolution.composer_evolution.enabled is false'];
        }
        $package = trim($package);
        if ($package === '') {
            return ['ok' => false, 'error' => 'invalid package'];
        }
        $bin = $this->findComposer();
        if ($bin === null) {
            return ['ok' => false, 'error' => 'composer binary not found'];
        }
        $cmd = escapeshellarg($bin) . ' remove ' . escapeshellarg($package)
            . ' --no-interaction --no-ansi --working-dir=' . escapeshellarg(BASE_PATH) . ' 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        $combined = trim(implode("\n", $out));
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'composer remove failed: ' . $combined];
        }

        return ['ok' => true, 'output' => $combined];
    }

    private function wouldBumpMajor(string $package, string $constraint): ?string
    {
        $lockPath = BASE_PATH . '/composer.lock';
        if (!is_file($lockPath)) {
            return null;
        }
        $lock = json_decode((string) file_get_contents($lockPath), true);
        if (!is_array($lock)) {
            return null;
        }
        $currentMajor = null;
        foreach ($lock['packages'] ?? [] as $p) {
            if (!is_array($p) || ($p['name'] ?? '') !== $package) {
                continue;
            }
            $ver = (string) ($p['version'] ?? '');
            $currentMajor = $this->majorFromVersion($ver);
            break;
        }
        if ($currentMajor === null) {
            return null;
        }

        if (preg_match('/^[\^~]?\s*(\d+)/', $constraint, $m)) {
            $reqMajor = (int) $m[1];
            if ($reqMajor > $currentMajor) {
                return 'Major bump blocked in Ghost mode — use manual approval / evolution.composer_evolution.allow_major_in_ghost or Swap flow.';
            }
        }

        return null;
    }

    private function majorFromVersion(string $ver): ?int
    {
        $ver = ltrim($ver, 'vV');
        if (preg_match('/^(\d+)/', $ver, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function findComposer(): ?string
    {
        $cfg = $this->container->get('config');
        $evo = $cfg->get('evolution.composer_autoload', []);
        $bin = is_array($evo) ? trim((string) ($evo['composer_binary'] ?? 'composer')) : 'composer';
        if ($bin === '') {
            $bin = 'composer';
        }
        $which = [];
        $code = 0;
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('where ' . escapeshellarg($bin) . ' 2>NUL', $which, $code);
        } else {
            @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $which, $code);
        }
        if ($which !== [] && is_string($which[0]) && $which[0] !== '') {
            return $which[0];
        }

        return is_file($bin) ? $bin : null;
    }

    /**
     * PHP + Node runtime compatibility hints for SRE (pair with Infrastructure Sentinel EOL signals).
     *
     * @return array{php: array<string, mixed>, node: array<string, mixed>, hints: list<string>}
     */
    public function runtimeCompatibilityReport(): array
    {
        $hints = [];
        $php = [
            'runtime_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
        ];

        $composerJson = BASE_PATH . '/composer.json';
        $platformPhp = null;
        if (is_file($composerJson)) {
            $cj = json_decode((string) file_get_contents($composerJson), true);
            if (is_array($cj) && isset($cj['config']['platform']['php'])) {
                $platformPhp = (string) $cj['config']['platform']['php'];
            }
        }
        $php['composer_platform_php'] = $platformPhp;

        $node = ['package_json' => false, 'engines_node' => null, 'volta' => null];
        $pj = BASE_PATH . '/package.json';
        if (is_file($pj)) {
            $node['package_json'] = true;
            $pjData = json_decode((string) file_get_contents($pj), true);
            if (is_array($pjData)) {
                $node['engines_node'] = $pjData['engines']['node'] ?? null;
                $node['volta'] = $pjData['volta']['node'] ?? null;
            }
        }

        if (is_string($platformPhp) && version_compare(PHP_VERSION, $platformPhp, '<')) {
            $hints[] = 'PHP runtime ' . PHP_VERSION . ' is below composer platform ' . $platformPhp . ' — align CI/Docker base image.';
        }
        $ga = BASE_PATH . '/.github/workflows';
        if (is_dir($ga)) {
            $hints[] = 'Review .github/workflows for node-version / php-version matrices when upgrading Node (e.g. 20 → 22) or PHP.';
        }
        $dock = BASE_PATH . '/Dockerfile';
        if (is_file($dock)) {
            $hints[] = 'Dockerfile present — bump FROM images in sync with EOL notices (Node/MySQL/PHP).';
        }

        return ['php' => $php, 'node' => $node, 'hints' => $hints];
    }

    /**
     * Prompt block for Ghost Mode: dependency + runtime upgrade alignment.
     */
    public function promptRuntimeUpgradeSection(): string
    {
        $cfg = $this->container->get('config');
        $ru = $cfg->get('evolution.runtime_upgrade_hints', []);
        if (!is_array($ru) || !filter_var($ru['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $r = $this->runtimeCompatibilityReport();
        $lines = [
            "\n\nRUNTIME & DEPENDENCY AUTOPILOT (composer + Node tooling):",
            '  PHP: ' . ($r['php']['runtime_version'] ?? '') . ' (platform: ' . (string) ($r['php']['composer_platform_php'] ?? 'n/a') . ')',
        ];
        $node = $r['node'];
        if (!empty($node['package_json'])) {
            $lines[] = '  Node engines: ' . (string) ($node['engines_node'] ?? 'unspecified') . ' (volta: ' . (string) ($node['volta'] ?? 'n/a') . ')';
        } else {
            $lines[] = '  No package.json — skip Node EOL unless frontend build lives elsewhere.';
        }
        foreach ($r['hints'] as $h) {
            $lines[] = '  Hint: ' . $h;
        }
        $lines[] = '  When infra_signals report Node/MySQL EOL, bump engines + CI matrices + Dockerfiles together; run npm ci && build after upgrade.';

        return implode("\n", $lines);
    }
}
