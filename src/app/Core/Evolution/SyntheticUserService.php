<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Runs Playwright synthetic scenarios; stores pass tokens for apply-gating.
 */
final class SyntheticUserService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, run_id?: string, result?: array<string, mixed>, error?: string}
     */
    public function runScenario(string $scenarioId): array
    {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        $syn = is_array($evo) ? ($evo['synthetic'] ?? []) : [];
        if (is_array($syn) && !filter_var($syn['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'Synthetic user simulation is disabled'];
        }

        $base = rtrim((string)$config->get('site.url', ''), '/');
        if ($base === '') {
            return ['ok' => false, 'error' => 'site.url not configured'];
        }

        $script = BASE_PATH . '/tooling/scripts/synthetic-user.mjs';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'Missing tooling/scripts/synthetic-user.mjs'];
        }

        $scenariosFile = BASE_PATH . '/tooling/synthetic-scenarios.json';
        $node = NodeBinaryResolver::resolvedShellArg($config);
        $strict = is_array($syn) && filter_var($syn['strict_console'] ?? false, FILTER_VALIDATE_BOOL) ? '1' : '0';

        $cmd = $node . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($base) . ' '
            . escapeshellarg($scenarioId) . ' '
            . escapeshellarg($scenariosFile) . ' '
            . $strict . ' 2>&1';

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $decoded = self::parseJsonLastLine($out);
        $joined = trim(implode("\n", $out));

        if (!is_array($decoded)) {
            EvolutionLogger::log('synthetic', 'parse_error', ['out' => $joined, 'code' => $code]);

            return ['ok' => false, 'error' => 'Invalid synthetic output: ' . $joined];
        }

        if (!($decoded['ok'] ?? false)) {
            EvolutionLogger::log('synthetic', 'failed', ['scenario' => $scenarioId, 'result' => $decoded]);

            return ['ok' => false, 'error' => (string)($decoded['error'] ?? 'Scenario failed'), 'result' => $decoded];
        }

        $runId = 'syn-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $dir = BASE_PATH . '/storage/evolution/synthetic_runs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = [
            'run_id' => $runId,
            'ts' => gmdate('c'),
            'scenario' => $scenarioId,
            'result' => $decoded,
        ];
        @file_put_contents($dir . '/' . $runId . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        EvolutionLogger::log('synthetic', 'pass', ['run_id' => $runId, 'scenario' => $scenarioId]);

        return ['ok' => true, 'run_id' => $runId, 'result' => $decoded];
    }

    public function isValidRecentPass(Config $config, string $runId, int $maxAgeSeconds = 3600): bool
    {
        $runId = trim($runId);
        if ($runId === '') {
            return false;
        }
        $file = BASE_PATH . '/storage/evolution/synthetic_runs/' . $runId . '.json';
        if (!is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return false;
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || !($j['result']['ok'] ?? false)) {
            return false;
        }
        $ts = strtotime((string)($j['ts'] ?? '')) ?: 0;

        return $ts > 0 && (time() - $ts) <= $maxAgeSeconds;
    }

    /**
     * @param list<string> $lines
     * @return ?array<string, mixed>
     */
    private static function parseJsonLastLine(array $lines): ?array
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string)($lines[$i] ?? ''));
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
