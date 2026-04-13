<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * SRE: ingests infrastructure/EOL signals (IMAP/SNS/AWS Health feeds should write storage/evolution/infra_signals.json)
 * and exposes a dashboard risk score + Ghost prompts for migration planning (e.g. MySQL 8.0 → 8.4).
 */
final class EvolutionInfrasentinelService
{
    public const SIGNALS_FILE = 'storage/evolution/infra_signals.json';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Compact block for HealthSnapshot / dashboards.
     *
     * @return array<string, mixed>
     */
    public function snapshotForHealth(): array
    {
        $cfg = $this->container->get('config');
        $s = $cfg->get('evolution.infrastructure_sentinel', []);
        if (!is_array($s) || !filter_var($s['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['enabled' => false];
        }

        $signals = $this->loadSignals();
        $score = $this->computeRiskScore($signals);

        return [
            'enabled' => true,
            'infrastructure_risk_score' => $score,
            'signal_count' => count($signals),
            'top_signals' => array_slice($signals, 0, 5),
            'signals_file' => self::SIGNALS_FILE,
            'hint' => 'Populate infra_signals.json from AWS Health, SNS, or IMAP bridge webhooks.',
        ];
    }

    public function promptSection(): string
    {
        $cfg = $this->container->get('config');
        $s = $cfg->get('evolution.infrastructure_sentinel', []);
        if (!is_array($s) || !filter_var($s['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $signals = $this->loadSignals();
        $score = $this->computeRiskScore($signals);
        $lines = [
            "\n\nINFRASTRUCTURE SENTINEL (SRE — EOL & cloud risk):",
            '  infrastructure_risk_score: ' . $score . ' / 100',
        ];
        if ($signals === []) {
            $lines[] = '  No signals in ' . self::SIGNALS_FILE . ' — forward AWS Health / RDS notices into this file for tracking.';

            return implode("\n", $lines);
        }
        foreach (array_slice($signals, 0, 12) as $sig) {
            if (!is_array($sig)) {
                continue;
            }
            $title = (string) ($sig['title'] ?? $sig['subject'] ?? 'signal');
            $comp = (string) ($sig['component'] ?? 'unknown');
            $sev = (string) ($sig['severity'] ?? 'info');
            $eol = (string) ($sig['eol_date'] ?? '');
            $lines[] = '  - [' . $sev . '] ' . $comp . ': ' . $title . ($eol !== '' ? ' (EOL/ref: ' . $eol . ')' : '');
        }
        $lines[] = '  Action: propose MySQL/Node/PHP upgrade path; scan SQL for deprecated patterns (ONLY_FULL_GROUP_BY, utf8mb4 defaults); prefer ShadowDeployService + staging RDS snapshot before production.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $signal
     * @return array{ok: bool, error?: string}
     */
    public function mergeSignal(array $signal): array
    {
        $path = BASE_PATH . '/' . self::SIGNALS_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $data = ['signals' => [], 'updated_at' => gmdate('c')];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($j) && isset($j['signals']) && is_array($j['signals'])) {
                $data['signals'] = $j['signals'];
            }
        }
        $signal['ingested_at'] = gmdate('c');
        $data['signals'][] = $signal;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($path, $json . "\n") === false) {
            return ['ok' => false, 'error' => 'cannot write signals file'];
        }
        EvolutionLogger::log('infra_sentinel', 'signal_merged', ['title' => $signal['title'] ?? '']);

        return ['ok' => true];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadSignals(): array
    {
        $path = BASE_PATH . '/' . self::SIGNALS_FILE;
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j) || !isset($j['signals']) || !is_array($j['signals'])) {
            return [];
        }

        return array_values(array_filter($j['signals'], 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $signals
     */
    private function computeRiskScore(array $signals): int
    {
        $score = 0;
        foreach ($signals as $sig) {
            $sev = strtolower((string) ($sig['severity'] ?? 'info'));
            $base = match ($sev) {
                'critical', 'crit' => 28,
                'warn', 'warning', 'high' => 18,
                'medium' => 10,
                default => 4,
            };
            $eol = (string) ($sig['eol_date'] ?? '');
            $urgency = 0;
            if ($eol !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $eol, $m)) {
                $ts = mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
                $days = (int) floor(($ts - time()) / 86400);
                if ($days < 0) {
                    $urgency += 25;
                } elseif ($days < 30) {
                    $urgency += 15;
                } elseif ($days < 90) {
                    $urgency += 8;
                }
            }
            $score += $base + $urgency;
        }

        return min(100, $score);
    }
}
