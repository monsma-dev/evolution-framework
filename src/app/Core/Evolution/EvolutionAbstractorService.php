<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Compresses PHP sources into short pseudo-code outlines (method names + rough signatures)
 * so Architect prompts can stay in Stage 1 without full file bodies.
 */
final class EvolutionAbstractorService
{
    /**
     * @return array{lines: list<string>, fqcn_hint: string}
     */
    public static function abstractFromPhpSource(string $relativePath, string $php, Config $config): array
    {
        $cv = $config->get('evolution.context_vault', []);
        $maxLines = 12;
        if (is_array($cv) && isset($cv['max_abstract_lines'])) {
            $maxLines = max(4, min(40, (int) $cv['max_abstract_lines']));
        }

        $php = preg_replace('/^\s*<\?php\s*/', '', $php) ?? $php;
        $php = preg_replace('/\/\*[\s\S]*?\*\/|\/\/[^\n]*/', '', $php) ?? $php;

        $fqcn = '';
        if (preg_match('/\b(?:class|interface|trait|enum)\s+(\w+)/', $php, $cm)) {
            $short = $cm[1];
            $ns = '';
            if (preg_match('/\bnamespace\s+([^;{]+)[;{]/', $php, $nm)) {
                $ns = trim(str_replace([' ', "\t"], '', $nm[1])) . '\\';
            }
            $fqcn = $ns . $short;
        }

        $lines = ['// ' . ($fqcn !== '' ? $fqcn : $relativePath)];

        if (preg_match_all(
            '/\b(public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)(?:\s*:\s*([^{;]+))?/m',
            $php,
            $mm,
            PREG_SET_ORDER
        )) {
            $n = 0;
            foreach ($mm as $row) {
                $n++;
                if ($n > $maxLines) {
                    $lines[] = '// ... +' . (count($mm) - $maxLines) . ' more methods';
                    break;
                }
                $vis = $row[1];
                $name = $row[2];
                $params = trim(preg_replace('/\s+/', ' ', $row[3]) ?? '');
                if (strlen($params) > 72) {
                    $params = mb_substr($params, 0, 69) . '...';
                }
                $ret = isset($row[4]) ? trim($row[4]) : '';
                $sig = '- ' . $vis . ' ' . $name . '(' . $params . ')' . ($ret !== '' ? ': ' . $ret : '');
                $lines[] = $sig;
            }
        } else {
            $lines[] = '// (no methods matched — file may be config-only or generated)';
        }

        $lines = array_slice($lines, 0, $maxLines + 2);

        return ['lines' => $lines, 'fqcn_hint' => $fqcn];
    }

    /**
     * @return array{ok: bool, abstract?: string, error?: string}
     */
    public static function abstractFile(Config $config, string $relativePath): array
    {
        $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
        $full = BASE_PATH . '/' . $rel;
        if (!is_file($full) || !str_ends_with(strtolower($full), '.php')) {
            return ['ok' => false, 'error' => 'not a php file'];
        }
        $raw = @file_get_contents($full);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'error' => 'empty read'];
        }
        $out = self::abstractFromPhpSource($rel, $raw, $config);

        return ['ok' => true, 'abstract' => implode("\n", $out['lines'])];
    }
}
