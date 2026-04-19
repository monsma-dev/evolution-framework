<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Predictive I/O: pre-reads correlated PHP files (Knowledge Graph + recent learning targets)
 * so the OS page cache holds them before heavy Architect context loading.
 */
final class EvolutionWarmupService
{
    private const KG_PATH = 'storage/evolution/knowledge_graph.json';
    private const APCU_PREFIX = 'evo_warm_';

    /**
     * @param array<int, array{role?: string, content?: string}> $messages
     *
     * @return array{warmed_files: int, bytes: int, apcu: bool}
     */
    public static function warm(Config $config, array $messages): array
    {
        $evo = $config->get('evolution', []);
        $w = is_array($evo) ? ($evo['warmup'] ?? []) : [];
        if (!is_array($w) || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['warmed_files' => 0, 'bytes' => 0, 'apcu' => false];
        }

        $maxFiles = max(1, min(64, (int)($w['max_files'] ?? 24)));
        $maxBytes = max(4096, min(512000, (int)($w['max_bytes_per_file'] ?? 65536)));

        $snippet = self::firstUserSnippet($messages);
        $targets = self::collectWarmTargets($snippet, $maxFiles);

        $bytes = 0;
        $n = 0;
        $pathsTouched = [];
        foreach ($targets as $rel) {
            if ($n >= $maxFiles) {
                break;
            }
            $full = BASE_PATH . '/' . ltrim($rel, '/');
            if (!is_file($full) || !str_ends_with(strtolower($full), '.php')) {
                continue;
            }
            $chunk = @file_get_contents($full, false, null, 0, $maxBytes);
            if (is_string($chunk)) {
                $bytes += strlen($chunk);
                $n++;
                $pathsTouched[] = $rel;
            }
        }

        $apcuOk = false;
        if ($n > 0 && function_exists('apcu_store')) {
            $key = self::APCU_PREFIX . gmdate('Y-m-d-H');
            $apcuOk = @apcu_store($key, json_encode($pathsTouched, JSON_UNESCAPED_UNICODE), 3600);
        }

        if ($n > 0) {
            EvolutionLogger::log('warmup', 'files_touched', ['count' => $n, 'bytes' => $bytes]);
        }

        return ['warmed_files' => $n, 'bytes' => $bytes, 'apcu' => $apcuOk];
    }

    /**
     * @param array<int, array{role?: string, content?: string}> $messages
     */
    private static function firstUserSnippet(array $messages): string
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user' && isset($m['content'])) {
                return substr((string)$m['content'], 0, 4000);
            }
        }

        return '';
    }

    /**
     * @return list<string> relative paths under BASE_PATH
     */
    private static function collectWarmTargets(string $snippet, int $maxFiles): array
    {
        $out = [];
        $kgPath = BASE_PATH . '/' . self::KG_PATH;
        if (is_file($kgPath)) {
            $raw = @file_get_contents($kgPath);
            $kg = is_string($raw) ? json_decode($raw, true) : null;
            $nodes = is_array($kg) && isset($kg['nodes']) && is_array($kg['nodes']) ? $kg['nodes'] : [];
            $edges = is_array($kg) && isset($kg['edges']) && is_array($kg['edges']) ? $kg['edges'] : [];

            $byId = [];
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $id = (string)($node['id'] ?? '');
                if ($id !== '' && isset($node['path'])) {
                    $byId[$id] = (string)$node['path'];
                }
            }

            $mentioned = [];
            if ($snippet !== '' && preg_match_all('/App\\\\[A-Za-z0-9_\\\\]+/', $snippet, $mm)) {
                foreach ($mm[0] as $fqcn) {
                    if (isset($byId[$fqcn])) {
                        $mentioned[$fqcn] = $byId[$fqcn];
                    }
                }
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $id = (string)($node['id'] ?? '');
                $path = (string)($node['path'] ?? '');
                if ($path === '' || $id === '') {
                    continue;
                }
                $leaf = substr($id, strrpos($id, '\\') !== false ? strrpos($id, '\\') + 1 : 0);
                if ($snippet !== '' && $leaf !== '' && str_contains($snippet, $leaf)) {
                    $mentioned[$id] = $path;
                }
            }

            foreach ($mentioned as $fromId => $nodePath) {
                $out[] = $nodePath;
                foreach ($edges as $e) {
                    if (!is_array($e)) {
                        continue;
                    }
                    if (($e['from'] ?? '') === $fromId && isset($e['to'])) {
                        $to = (string)$e['to'];
                        if (isset($byId[$to])) {
                            $out[] = $byId[$to];
                        }
                    }
                }
            }
        }

        $hist = self::recentLearningTargets(8);
        foreach ($hist as $t) {
            if (str_starts_with($t, 'twig:') || str_starts_with($t, 'css:')) {
                continue;
            }
            $fqcn = str_replace('/', '\\', trim($t, '\\'));
            if (!str_contains($fqcn, '\\')) {
                continue;
            }
            $rel = 'app/' . str_replace('\\', '/', $fqcn) . '.php';
            if (is_file(BASE_PATH . '/' . $rel)) {
                $out[] = $rel;
            }
        }

        $out = array_values(array_unique(array_filter($out)));
        if (count($out) > $maxFiles) {
            $out = array_slice($out, 0, $maxFiles);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function recentLearningTargets(int $limit): array
    {
        $path = BASE_PATH . '/data/evolution/learning_history.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $lines = array_slice($lines, -200);
        $targets = [];
        foreach (array_reverse($lines) as $line) {
            $j = @json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $t = trim((string)($j['target'] ?? ''));
            if ($t !== '') {
                $targets[] = $t;
            }
            if (count($targets) >= $limit) {
                break;
            }
        }

        return array_values(array_unique($targets));
    }
}
