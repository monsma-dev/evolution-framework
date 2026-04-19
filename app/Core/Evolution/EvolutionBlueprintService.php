<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Ruimtelijk bewustzijn: compacte blueprint.txt + blueprint.json (PHP, Twig, assets, links).
 */
final class EvolutionBlueprintService
{
    private const TXT = 'storage/evolution/blueprint.txt';

    private const JSON = 'storage/evolution/blueprint.json';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, error?: string, php_files?: int, twig_files?: int, asset_files?: int}
     */
    public function generate(): array
    {
        $cfg = $this->container->get('config');
        $evo = $cfg->get('evolution.blueprint', []);
        if (!is_array($evo) || !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'blueprint disabled'];
        }
        if (!defined('BASE_PATH')) {
            return ['ok' => false, 'error' => 'BASE_PATH undefined'];
        }

        $fatThreshold = max(4096, (int) ($evo['fat_asset_bytes'] ?? 102400));
        $maxTxt = max(4000, min(120000, (int) ($evo['blueprint_txt_max_chars'] ?? 28000)));

        $dna = new CodeDnaScoringService();
        $dnaAll = $dna->scoreAll($cfg);
        $scores = is_array($dnaAll['scores'] ?? null) ? $dnaAll['scores'] : [];

        $phpFiles = $this->scanPhp($scores);
        $twigData = $this->scanTwig();
        $assetData = $this->scanAssets($fatThreshold);
        $links = $this->buildImpactLinks($twigData['files'], $assetData['by_rel']);

        $payload = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'fat_asset_bytes' => $fatThreshold,
            'php' => $phpFiles,
            'twig' => $twigData,
            'assets' => $assetData['list'],
            'twig_asset_links' => $links,
            'fat_warnings' => $assetData['fat'],
        ];

        $jsonPath = BASE_PATH . '/' . self::JSON;
        $dir = dirname($jsonPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'cannot create storage dir'];
        }
        if (@file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            return ['ok' => false, 'error' => 'cannot write blueprint.json'];
        }

        $txt = $this->renderBlueprintText($payload, $maxTxt);
        @file_put_contents(BASE_PATH . '/' . self::TXT, $txt, LOCK_EX);

        if ($assetData['fat'] !== []) {
            EvolutionNotepadService::appendFatAssetCriticalNotes($cfg, $assetData['fat']);
        }

        EvolutionLogger::log('blueprint', 'generated', [
            'php' => count($phpFiles),
            'twig' => count($twigData['files'] ?? []),
            'assets' => count($assetData['list'] ?? []),
            'links' => count($links),
        ]);

        return [
            'ok' => true,
            'php_files' => count($phpFiles),
            'twig_files' => count($twigData['files'] ?? []),
            'asset_files' => count($assetData['list'] ?? []),
        ];
    }

    public static function promptBlueprintTxt(Config $config): string
    {
        $evo = $config->get('evolution.blueprint', []);
        if (!is_array($evo) || !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $max = max(2000, min(120000, (int) ($evo['blueprint_txt_max_chars'] ?? 28000)));
        $path = BASE_PATH . '/' . self::TXT;
        if (!is_file($path)) {
            return "\n\nCURRENT_CODEBASE_BLUEPRINT: (bestand ontbreekt — run `php ai_bridge.php evolution:knowledge-rebuild`.)\n";
        }
        $raw = (string) @file_get_contents($path);
        if ($raw === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($raw) > $max) {
            $raw = mb_substr($raw, 0, $max) . "\n… [blueprint.txt truncated]\n";
        } elseif (strlen($raw) > $max) {
            $raw = substr($raw, 0, $max) . "\n… [blueprint.txt truncated]\n";
        }

        return "\n\nCURRENT_CODEBASE_BLUEPRINT (compact — signatures/imports only):\n" . $raw;
    }

    /**
     * @param array<string, array{score: int, metrics: array, advice: string}> $scores
     *
     * @return list<array{path: string, fqcn: string, dna: int|null, imports: list<string>, public_methods: list<string>}>
     */
    private function scanPhp(array $scores): array
    {
        $root = BASE_PATH . '/app';
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $full = $file->getPathname();
            $rel = str_replace('\\', '/', substr($full, strlen(BASE_PATH) + 1));
            $src = (string) @file_get_contents($full);
            if ($src === '' || !preg_match('/^namespace\s+([^;]+);/m', $src, $nm)) {
                continue;
            }
            $ns = trim($nm[1]);
            $className = null;
            if (preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cl)) {
                $className = $cl[1];
            } elseif (preg_match('/^interface\s+(\w+)/m', $src, $if)) {
                $className = $if[1];
            }
            if ($className === null) {
                continue;
            }
            $fqcn = $ns . '\\' . $className;
            $dna = isset($scores[$fqcn]) ? (int) ($scores[$fqcn]['score'] ?? 0) : null;

            $imports = [];
            if (preg_match_all('/^use\s+([^;]+);/m', $src, $uses)) {
                foreach ($uses[1] as $u) {
                    $u = trim($u);
                    if (str_contains($u, ' function ')) {
                        continue;
                    }
                    $parts = preg_split('/\s+as\s+/i', $u);
                    $target = trim((string) ($parts[0] ?? ''));
                    if (str_starts_with($target, 'App\\')) {
                        $imports[] = $target;
                    }
                }
            }

            $methods = [];
            if (preg_match_all('/^\s*public\s+function\s+(\w+)\s*\(/m', $src, $mm)) {
                foreach ($mm[1] as $name) {
                    if ($name !== '__construct') {
                        $methods[] = $name;
                    }
                }
            }

            $out[] = [
                'path' => $rel,
                'fqcn' => $fqcn,
                'dna' => $dna,
                'imports' => array_slice(array_values(array_unique($imports)), 0, 24),
                'public_methods' => array_slice($methods, 0, 24),
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $out;
    }

    /**
     * @return array{files: list<array{path: string, extends: string, includes: list<string>, asset_refs: list<string>}>}
     */
    private function scanTwig(): array
    {
        $views = BASE_PATH . '/resources/views';
        $files = [];
        if (!is_dir($views)) {
            return ['files' => []];
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($views, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }
            $full = $file->getPathname();
            $rel = 'resources/views/' . ltrim(str_replace('\\', '/', substr($full, strlen($views))), '/');
            $src = (string) @file_get_contents($full);
            $ext = '';
            if (preg_match('/\{%\s*extends\s+[\"\']([^\"\']+)[\"\']\s*%\}/', $src, $m)) {
                $ext = trim($m[1]);
            }
            $inc = [];
            if (preg_match_all('/\{%\s*include\s+[\"\']([^\"\']+)[\"\']/', $src, $im)) {
                foreach ($im[1] as $x) {
                    $inc[] = trim($x);
                }
            }
            $refs = [];
            if (preg_match_all('/(?:href|src)\s*=\s*[\"\']([^\"\']+\.(?:css|js))[\"\' ]/i', $src, $rm)) {
                foreach ($rm[1] as $r) {
                    $refs[] = $this->normalizeAssetRef($r);
                }
            }
            if (preg_match_all('/@import\s+[\"\']([^\"\']+\.css)[\"\']/i', $src, $zm)) {
                foreach ($zm[1] as $r) {
                    $refs[] = $this->normalizeAssetRef($r);
                }
            }

            $files[] = [
                'path' => $rel,
                'extends' => $ext,
                'includes' => array_slice(array_values(array_unique($inc)), 0, 20),
                'asset_refs' => array_slice(array_values(array_unique($refs)), 0, 20),
            ];
        }
        usort($files, static fn ($a, $b) => strcmp($a['path'], $b['path']));

        return ['files' => $files];
    }

    /**
     * @return array{list: list<array{path: string, bytes: int, fat: bool}>, fat: list<array{path: string, bytes: int}>, by_rel: array<string, array{path: string, bytes: int}>}
     */
    private function scanAssets(int $fatThreshold): array
    {
        $roots = [
            BASE_PATH . '/web/assets',
            BASE_PATH . '/resources/assets',
        ];
        $list = [];
        $fat = [];
        $byRel = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $baseLabel = str_contains($root, 'public/assets') ? 'public/assets' : 'resources/assets';
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['css', 'js', 'mjs', 'map'], true)) {
                    continue;
                }
                $full = $file->getPathname();
                $inner = ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
                $rel = $baseLabel . '/' . $inner;
                $bytes = (int) $file->getSize();
                $isFat = $bytes >= $fatThreshold;
                $row = ['path' => $rel, 'bytes' => $bytes, 'fat' => $isFat];
                $list[] = $row;
                $byRel[$rel] = ['path' => $rel, 'bytes' => $bytes];
                if ($isFat) {
                    $fat[] = ['path' => $rel, 'bytes' => $bytes];
                }
            }
        }
        usort($list, static fn ($a, $b) => strcmp($a['path'], $b['path']));

        return ['list' => $list, 'fat' => $fat, 'by_rel' => $byRel];
    }

    /**
     * @param list<array{path: string, extends: string, includes: list<string>, asset_refs: list<string>}> $twigFiles
     * @param array<string, array{path: string, bytes: int}> $assetsByRel
     *
     * @return list<array{twig: string, target: string, kind: string}>
     */
    private function buildImpactLinks(array $twigFiles, array $assetsByRel): array
    {
        $links = [];
        $assetKeys = array_keys($assetsByRel);
        foreach ($twigFiles as $tw) {
            $tp = $tw['path'];
            foreach ($tw['asset_refs'] as $ref) {
                if (isset($assetsByRel[$ref])) {
                    $links[] = ['twig' => $tp, 'target' => $ref, 'kind' => 'direct_path'];
                    continue;
                }
                $bn = basename(str_replace('\\', '/', $ref));
                foreach ($assetKeys as $ap) {
                    if ($bn !== '' && str_ends_with(strtolower($ap), strtolower($bn))) {
                        $links[] = ['twig' => $tp, 'target' => $ap, 'kind' => 'basename_match'];
                        break;
                    }
                }
            }
            $base = (string) pathinfo($tp, PATHINFO_FILENAME);
            if ($base !== '' && strlen($base) > 2) {
                $n = 0;
                foreach ($assetKeys as $ap) {
                    if ($n >= 4) {
                        break;
                    }
                    if (str_contains(strtolower($ap), strtolower($base)) && (str_ends_with($ap, '.css') || str_ends_with($ap, '.js'))) {
                        $links[] = ['twig' => $tp, 'target' => $ap, 'kind' => 'name_overlap'];
                        $n++;
                    }
                }
            }
        }
        $seen = [];
        $uniq = [];
        foreach ($links as $L) {
            $k = $L['twig'] . '|' . $L['target'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $uniq[] = $L;
        }

        return array_slice($uniq, 0, 400);
    }

    private function normalizeAssetRef(string $ref): string
    {
        $ref = trim($ref);
        $ref = preg_replace('#^(\./|/)+#', '', $ref) ?? $ref;
        if (str_starts_with($ref, 'assets/')) {
            return 'public/' . $ref;
        }
        if (str_starts_with($ref, 'public/')) {
            return $ref;
        }

        return 'public/assets/' . ltrim($ref, '/');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderBlueprintText(array $payload, int $maxChars): string
    {
        $lines = [];
        $lines[] = '=== CODEBASE BLUEPRINT (compact) ===';
        $lines[] = 'generated_at: ' . ($payload['generated_at'] ?? '');
        $lines[] = 'NAVIGATION_HINT: Je bekijkt src/app (PHP), src/resources/views (Twig), public/assets + resources/assets (CSS/JS). Verdere structuur staat ook in de Vault / knowledge_graph.';
        $lines[] = '';

        $lines[] = '--- PHP (src/app) — DNA + imports + public methods ---';
        foreach ($payload['php'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dna = $row['dna'] !== null ? (string) $row['dna'] : '?';
            $imps = isset($row['imports']) && is_array($row['imports']) ? implode(', ', $row['imports']) : '';
            $meth = isset($row['public_methods']) && is_array($row['public_methods']) ? implode(', ', $row['public_methods']) : '';
            $lines[] = ($row['path'] ?? '') . ' | ' . ($row['fqcn'] ?? '') . ' | DNA:' . $dna;
            if ($imps !== '') {
                $lines[] = '  uses: ' . $imps;
            }
            if ($meth !== '') {
                $lines[] = '  public: ' . $meth;
            }
        }

        $lines[] = '';
        $lines[] = '--- TWIG (hiërarchie / extends / includes) ---';
        foreach ($payload['twig']['files'] ?? [] as $tw) {
            if (!is_array($tw)) {
                continue;
            }
            $lines[] = ($tw['path'] ?? '');
            if (($tw['extends'] ?? '') !== '') {
                $lines[] = '  extends: ' . $tw['extends'];
            }
            if (!empty($tw['includes'])) {
                $lines[] = '  includes: ' . implode(', ', $tw['includes']);
            }
        }

        $lines[] = '';
        $lines[] = '--- ASSETS (size; ⚠️ = fat > threshold) ---';
        foreach ($payload['assets'] ?? [] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $kb = max(1, (int) round(($a['bytes'] ?? 0) / 1024));
            $flag = !empty($a['fat']) ? '⚠️ [FAT_ASSET_WARNING] ' : '';
            $lines[] = $flag . ($a['path'] ?? '') . ' — ' . $kb . ' KB';
        }

        $lines[] = '';
        $lines[] = '--- IMPACT MAP (Twig ↔ CSS/JS hints) ---';
        foreach (array_slice($payload['twig_asset_links'] ?? [], 0, 120) as $L) {
            if (!is_array($L)) {
                continue;
            }
            $lines[] = ($L['twig'] ?? '') . ' -> ' . ($L['target'] ?? '') . ' [' . ($L['kind'] ?? '') . ']';
        }

        $lines[] = '';
        $lines[] = 'ASSET_HYGIENE: Bij [FAT_ASSET_WARNING] prioriteit geven aan opschonen/splitsen van CSS/JS in je volgende ui_autofix.';

        $txt = implode("\n", $lines);
        if (strlen($txt) > $maxChars) {
            $txt = substr($txt, 0, $maxChars) . "\n… [truncated to blueprint_txt_max_chars]\n";
        }

        return $txt;
    }
}
