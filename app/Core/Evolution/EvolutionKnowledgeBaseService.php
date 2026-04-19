<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Builds storage/evolution/knowledge_graph.json — lightweight class/use edges for Architect retrieval.
 */
final class EvolutionKnowledgeBaseService
{
    private const OUTPUT = 'storage/evolution/knowledge_graph.json';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, nodes?: int, edges?: int, error?: string}
     */
    public function rebuildIndex(): array
    {
        $cfg = $this->container->get('config');
        $kb = $cfg->get('evolution.knowledge_base', []);
        if (!is_array($kb) || !filter_var($kb['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'knowledge_base disabled'];
        }
        $root = BASE_PATH . '/app';
        if (!is_dir($root)) {
            return ['ok' => false, 'error' => 'src/app missing'];
        }

        $nodes = [];
        $edges = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $rel = str_replace('\\', '/', substr($path, strlen(BASE_PATH) + 1));
            $src = (string) @file_get_contents($path);
            if ($src === '') {
                continue;
            }
            if (!preg_match('/^namespace\s+([^;]+);/m', $src, $nm)) {
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
            $nodes[$fqcn] = [
                'id' => $fqcn,
                'path' => $rel,
                'kind' => 'class',
            ];

            if (preg_match_all('/^use\s+([^;]+);/m', $src, $uses)) {
                foreach ($uses[1] as $u) {
                    $u = trim($u);
                    if (str_contains($u, ' function ')) {
                        continue;
                    }
                    $parts = preg_split('/\s+as\s+/i', $u);
                    $target = trim($parts[0] ?? '');
                    if ($target !== '' && str_starts_with($target, 'App\\')) {
                        $edges[] = ['from' => $fqcn, 'to' => $target, 'type' => 'uses'];
                    }
                }
            }
        }

        $payload = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'nodes' => array_values($nodes),
            'edges' => $edges,
        ];
        $out = BASE_PATH . '/' . self::OUTPUT;
        $dir = dirname($out);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'cannot write output dir'];
        }
        if (@file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            return ['ok' => false, 'error' => 'cannot write knowledge_graph.json'];
        }
        EvolutionLogger::log('knowledge_base', 'rebuilt', [
            'nodes' => count($nodes),
            'edges' => count($edges),
        ]);

        return ['ok' => true, 'nodes' => count($nodes), 'edges' => count($edges)];
    }

    /**
     * Voegt Twig→asset kanten toe uit blueprint.json (na blueprint:generate).
     *
     * @return array{ok: bool, added?: int, error?: string}
     */
    public static function mergeBlueprintAssetEdges(): array
    {
        $kgPath = BASE_PATH . '/' . self::OUTPUT;
        $bpPath = BASE_PATH . '/data/evolution/blueprint.json';
        if (!is_file($kgPath) || !is_file($bpPath)) {
            return ['ok' => false, 'error' => 'missing knowledge_graph or blueprint'];
        }
        $kgRaw = @file_get_contents($kgPath);
        $bpRaw = @file_get_contents($bpPath);
        if (!is_string($kgRaw) || !is_string($bpRaw)) {
            return ['ok' => false, 'error' => 'read failed'];
        }
        $kg = json_decode($kgRaw, true);
        $bp = json_decode($bpRaw, true);
        if (!is_array($kg) || !is_array($bp)) {
            return ['ok' => false, 'error' => 'invalid json'];
        }
        $edges = $kg['edges'] ?? [];
        if (!is_array($edges)) {
            $edges = [];
        }
        $nodes = $kg['nodes'] ?? [];
        if (!is_array($nodes)) {
            $nodes = [];
        }
        $nodeIds = [];
        foreach ($nodes as $n) {
            if (is_array($n) && isset($n['id'])) {
                $nodeIds[(string) $n['id']] = true;
            }
        }
        $links = $bp['twig_asset_links'] ?? [];
        if (!is_array($links)) {
            $links = [];
        }
        $added = 0;
        foreach ($links as $L) {
            if (!is_array($L)) {
                continue;
            }
            $tw = (string) ($L['twig'] ?? '');
            $tg = (string) ($L['target'] ?? '');
            if ($tw === '' || $tg === '') {
                continue;
            }
            $fromId = 'twig:' . $tw;
            $toId = 'asset:' . $tg;
            if (!isset($nodeIds[$fromId])) {
                $nodes[] = ['id' => $fromId, 'path' => $tw, 'kind' => 'twig'];
                $nodeIds[$fromId] = true;
            }
            if (!isset($nodeIds[$toId])) {
                $nodes[] = ['id' => $toId, 'path' => $tg, 'kind' => 'asset'];
                $nodeIds[$toId] = true;
            }
            $edges[] = ['from' => $fromId, 'to' => $toId, 'type' => 'twig_uses_asset', 'hint' => (string) ($L['kind'] ?? '')];
            $added++;
        }
        $kg['nodes'] = array_values($nodes);
        $kg['edges'] = $edges;
        $kg['blueprint_merged_at'] = gmdate('c');
        if (@file_put_contents($kgPath, json_encode($kg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            return ['ok' => false, 'error' => 'write failed'];
        }
        EvolutionLogger::log('knowledge_base', 'blueprint_edges_merged', ['added' => $added]);

        return ['ok' => true, 'added' => $added];
    }

    public function promptSection(int $maxChars = 4500): string
    {
        $path = BASE_PATH . '/' . self::OUTPUT;
        if (!is_file($path)) {
            return '';
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }
        if (strlen($raw) > $maxChars) {
            $raw = substr($raw, 0, $maxChars) . "\n…(truncated)";
        }

        return "\n\nKNOWLEDGE_GRAPH (indexed class/use edges — see storage/evolution/knowledge_graph.json):\n" . $raw;
    }
}
