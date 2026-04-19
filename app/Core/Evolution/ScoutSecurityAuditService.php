<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Runs `composer audit` and writes JSON reports + suggested composer commands ("shadow patches").
 *
 * @see EvolutionScoutCommand
 */
final class ScoutSecurityAuditService
{
    /**
     * @return array{ok:bool, report_path?:string, shadow_path?:string, advisory_count?:int, error?:string}
     */
    public static function runAndPersist(string $basePath): array
    {
        $dir = $basePath . '/data/evolution';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot create storage/evolution'];
        }

        $raw = self::execComposerAudit($basePath);
        if ($raw === null) {
            return ['ok' => false, 'error' => 'composer audit failed or Composer too old (need 2.4+ with audit)'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'composer audit returned non-JSON'];
        }

        $reportPath = $dir . '/scout_security_report.json';
        $shadowPath = $dir . '/shadow_patch_suggestions.json';

        $shadow = self::buildShadowPatches($decoded);

        $envelope = [
            'generated_at' => gmdate('c'),
            'source'       => 'composer audit --format=json',
            'raw'          => $decoded,
        ];
        file_put_contents($reportPath, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        file_put_contents($shadowPath, json_encode($shadow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        EvolutionLogger::log('scout', 'security_audit', [
            'advisory_count' => $shadow['advisory_count'] ?? 0,
            'report'         => $reportPath,
        ]);

        return [
            'ok'             => true,
            'report_path'    => $reportPath,
            'shadow_path'    => $shadowPath,
            'advisory_count' => (int)($shadow['advisory_count'] ?? 0),
        ];
    }

    private static function execComposerAudit(string $basePath): ?string
    {
        if (!is_file($basePath . '/composer.lock')) {
            return null;
        }

        $composerPhp = $basePath . '/vendor/bin/composer';
        $cmd = is_file($composerPhp)
            ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composerPhp) . ' audit --format=json --no-interaction 2>&1'
            : 'composer audit --format=json --no-interaction 2>&1';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, $basePath);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if (!is_string($stdout) || trim($stdout) === '') {
            return null;
        }

        // composer audit may print warnings before JSON — take last JSON object
        if ($stdout[0] !== '{') {
            $pos = strrpos($stdout, '{');
            if ($pos !== false) {
                $stdout = substr($stdout, $pos);
            }
        }

        return $stdout;
    }

    /**
     * @param array<string, mixed> $auditJson
     * @return array<string, mixed>
     */
    private static function buildShadowPatches(array $auditJson): array
    {
        $items = [];
        $count = 0;

        // Composer 2.4+ audit JSON shape: advisories keyed by package name
        $advisories = $auditJson['advisories'] ?? null;
        if (is_array($advisories)) {
            foreach ($advisories as $package => $list) {
                if (!is_string($package) || !is_array($list)) {
                    continue;
                }
                foreach ($list as $adv) {
                    if (!is_array($adv)) {
                        continue;
                    }
                    $count++;
                    $cve = (string)($adv['cve'] ?? '');
                    $title = (string)($adv['title'] ?? $adv['reportedAt'] ?? 'advisory');
                    $link = (string)($adv['link'] ?? '');
                    $items[] = [
                        'package'      => $package,
                        'title'        => $title,
                        'cve'          => $cve,
                        'link'         => $link,
                        'shadow_patch' => 'composer update ' . $package . ' --with-all-dependencies',
                        'note'         => 'Review changelog; run tests; deploy after verify.',
                    ];
                }
            }
        }

        return [
            'generated_at'    => gmdate('c'),
            'advisory_count'  => $count,
            'suggestions'     => $items,
            'disclaimer'      => 'Shadow patches are CLI suggestions only — not applied automatically.',
        ];
    }

}
