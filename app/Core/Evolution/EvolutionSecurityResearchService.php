<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Static pattern scan on Evolution (and optional) controllers + prompt hints for CVE-driven review via web_search.
 */
final class EvolutionSecurityResearchService
{
    /** @var list<string> */
    private const DANGEROUS_PATTERNS = [
        '/\$_GET\s*\[\s*[\'"][^\'"]+[\'"]\s*\]\s*\.\s*\$_(?:GET|POST|REQUEST)/',
        '/->query\s*\(\s*\$_(?:GET|POST|REQUEST)/i',
        '/mysqli?_query\s*\(\s*\$[^,]+,\s*\$_(?:GET|POST|REQUEST)/i',
        '/\beval\s*\(/i',
        '/\b(shell_exec|exec|passthru|system)\s*\(\s*\$/',
    ];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return list<array{file: string, pattern: string, line_hint: string}>
     */
    public function scanEvolutionControllers(): array
    {
        $cfg = $this->container->get('config');
        $sec = $cfg->get('evolution.security_research', []);
        if (!is_array($sec) || !filter_var($sec['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $root = BASE_PATH . '/app/Domain/Web/Controllers/Evolution';
        if (!is_dir($root)) {
            return [];
        }
        $findings = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $src = (string) @file_get_contents($path);
            if ($src === '') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen(BASE_PATH) + 1));
            foreach (self::DANGEROUS_PATTERNS as $i => $rx) {
                if (preg_match($rx, $src)) {
                    $findings[] = [
                        'file' => $rel,
                        'pattern' => 'rule#' . ($i + 1),
                        'line_hint' => 'possible injection / dangerous call — verify parameter binding',
                    ];
                }
            }
        }

        return $findings;
    }

    public function promptSection(): string
    {
        $findings = $this->scanEvolutionControllers();
        $lines = ["\n\nSECURITY RESEARCH (static scan — pair with web_search for recent CVEs in your stack):"];
        if ($findings === []) {
            $lines[] = '  No high-risk static patterns in Domain/Web/Controllers/Evolution (this run).';

            return implode("\n", $lines);
        }
        foreach (array_slice($findings, 0, 15) as $f) {
            $lines[] = '  - ' . $f['file'] . ' [' . $f['pattern'] . '] ' . $f['line_hint'];
        }
        $lines[] = 'Simulate parameterized requests against new /v/* and Evolution routes before enabling dynamic_routing in production.';

        return implode("\n", $lines);
    }
}
