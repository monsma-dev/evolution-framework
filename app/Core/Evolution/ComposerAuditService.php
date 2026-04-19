<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Runs `composer audit --format=json` and parses advisories.
 * Used by Ghost Mode to detect vendor vulnerabilities and propose updates.
 */
final class ComposerAuditService
{
    /**
     * @return array{ok: bool, advisories: list<array{package: string, title: string, cve: string, severity: string, affected_versions: string, fixed_version: string}>, raw_count: int, error?: string}
     */
    public function audit(): array
    {
        $composerBin = $this->findComposerBin();
        if ($composerBin === null) {
            return ['ok' => false, 'advisories' => [], 'raw_count' => 0, 'error' => 'composer binary not found'];
        }

        $cmd = escapeshellarg($composerBin) . ' audit --format=json --working-dir=' . escapeshellarg(BASE_PATH) . ' 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        $json = implode("\n", $out);
        $decoded = @json_decode($json, true);

        if (!is_array($decoded)) {
            return ['ok' => false, 'advisories' => [], 'raw_count' => 0, 'error' => 'Cannot parse composer audit output: ' . substr($json, 0, 200)];
        }

        $advisories = [];
        $advData = $decoded['advisories'] ?? [];
        if (is_array($advData)) {
            foreach ($advData as $package => $items) {
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $advisories[] = [
                        'package' => (string)$package,
                        'title' => (string)($item['title'] ?? ''),
                        'cve' => (string)($item['cve'] ?? $item['advisoryId'] ?? ''),
                        'severity' => strtolower((string)($item['severity'] ?? 'unknown')),
                        'affected_versions' => (string)($item['affectedVersions'] ?? ''),
                        'fixed_version' => (string)($item['fixedVersion'] ?? $item['reportedAt'] ?? ''),
                        'link' => (string)($item['link'] ?? ''),
                    ];
                }
            }
        }

        EvolutionLogger::log('composer_audit', 'completed', [
            'advisory_count' => count($advisories),
            'exit_code' => $code,
        ]);

        return [
            'ok' => true,
            'advisories' => $advisories,
            'raw_count' => count($advisories),
        ];
    }

    /**
     * Build a prompt section for Ghost Mode with composer audit results.
     */
    public function promptSection(): string
    {
        $result = $this->audit();
        if (!$result['ok'] || $result['advisories'] === []) {
            return '';
        }

        $lines = ["\n\nCOMPOSER SECURITY AUDIT ({$result['raw_count']} advisories):"];
        foreach (array_slice($result['advisories'], 0, 10) as $adv) {
            $lines[] = "  - [{$adv['severity']}] {$adv['package']}: {$adv['title']} (CVE: {$adv['cve']})";
            if ($adv['fixed_version'] !== '') {
                $lines[] = "    Fixed in: {$adv['fixed_version']}";
            }
        }
        $lines[] = "For patch-level updates (e.g. v1.2.3 -> v1.2.4), you may propose a composer.json update as low_autofix.";
        $lines[] = "For major updates, propose as high severity and explain the migration path.";

        return implode("\n", $lines);
    }

    private function findComposerBin(): ?string
    {
        $candidates = [
            BASE_PATH . '/vendor/bin/composer',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
        ];
        foreach ($candidates as $c) {
            if (is_file($c) && is_executable($c)) {
                return $c;
            }
        }
        $out = [];
        $code = 1;
        @exec('command -v composer 2>/dev/null', $out, $code);
        if ($code === 0 && isset($out[0]) && trim($out[0]) !== '') {
            return trim($out[0]);
        }

        return null;
    }
}
