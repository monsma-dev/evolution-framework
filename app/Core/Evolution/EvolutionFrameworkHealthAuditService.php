<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Monthly-style architectural debt scan: PHP 8.x idioms, risky legacy patterns, Twig/Tailwind hygiene hints.
 */
final class EvolutionFrameworkHealthAuditService
{
    private const PHP_ROOT = 'src/app';

    /** @var list<array{pattern: string, name: string, advice: string}> */
    private const HEURISTICS = [
        ['pattern' => '/\bmysql_\w+\s*\(/i', 'name' => 'mysql_* extension', 'advice' => 'Remove mysql_* — use PDO/MySQLi.'],
        ['pattern' => '/\beach\s*\(/i', 'name' => 'legacy_each', 'advice' => 'Legacy iterator removed in PHP 8 — use foreach.'],
        ['pattern' => '/\bcreate_function\s*\(/i', 'name' => 'legacy_create_function', 'advice' => 'Replace with closures or named functions.'],
        ['pattern' => '/\$GLOBALS\s*\[\s*[\'"]\w+[\'"]\s*\]/', 'name' => 'heavy GLOBALS', 'advice' => 'Prefer DI / constructor injection.'],
    ];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, findings: list<array{file: string, rule: string, advice: string}>, files_scanned?: int}
     */
    public function runAudit(): array
    {
        $cfg = $this->container->get('config');
        $fh = $cfg->get('evolution.framework_health_audit', []);
        if (!is_array($fh) || !filter_var($fh['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'findings' => [], 'error' => 'framework_health_audit disabled', 'files_scanned' => 0];
        }

        $root = BASE_PATH . '/' . self::PHP_ROOT;
        if (!is_dir($root)) {
            return ['ok' => false, 'findings' => [], 'error' => 'src/app missing', 'files_scanned' => 0];
        }

        $findings = [];
        $scanned = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            $path = $file->getPathname();
            $src = (string) @file_get_contents($path);
            if ($src === '') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen(BASE_PATH) + 1));
            foreach (self::HEURISTICS as $h) {
                if (preg_match($h['pattern'], $src)) {
                    $findings[] = [
                        'file' => $rel,
                        'rule' => $h['name'],
                        'advice' => $h['advice'],
                    ];
                }
            }
        }

        EvolutionLogger::log('framework_health', 'audit', ['findings' => count($findings), 'scanned' => $scanned]);

        return ['ok' => true, 'findings' => $findings, 'files_scanned' => $scanned];
    }

    public function promptSection(): string
    {
        $cfg = $this->container->get('config');
        $fh = $cfg->get('evolution.framework_health_audit', []);
        if (!is_array($fh) || !filter_var($fh['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $r = $this->runAudit();
        if (!($r['ok'] ?? false)) {
            return '';
        }
        $findings = $r['findings'] ?? [];
        $lines = [
            "\n\nFRAMEWORK HEALTH AUDIT (architectural debt — PHP 8.3+/Twig/Tailwind alignment):",
            '  Files scanned: ' . (int) ($r['files_scanned'] ?? 0) . ', heuristic hits: ' . count($findings),
        ];
        foreach (array_slice($findings, 0, 20) as $f) {
            $lines[] = '  - ' . $f['file'] . ' [' . $f['rule'] . '] ' . $f['advice'];
        }
        if (count($findings) > 20) {
            $lines[] = '  … truncated; run `php ai_bridge.php evolution:framework-health` for full list.';
        }
        $lines[] = '  Prefer: readonly properties, enums for states, constructor promotion, Twig strict_variables, Tailwind @theme tokens.';

        return implode("\n", $lines);
    }
}
