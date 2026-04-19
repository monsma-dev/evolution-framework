<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Queue Figma-exported assets for Node worker optimization (Sharp/TinyPNG) and Twig path rewrites.
 */
final class FigmaGhostAssetService
{
    private const QUEUE = 'storage/evolution/figma_asset_queue.jsonl';

    /**
     * @param array{url: string, target_public_path: string, twig_refs?: list<string>} $job
     */
    public function enqueue(array $job): array
    {
        $url = trim((string) ($job['url'] ?? ''));
        $target = trim((string) ($job['target_public_path'] ?? ''));
        if ($url === '' || $target === '') {
            return ['ok' => false, 'error' => 'url and target_public_path required'];
        }

        $line = json_encode([
            'ts' => gmdate('c'),
            'url' => $url,
            'target_public_path' => $target,
            'twig_refs' => $job['twig_refs'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        $p = BASE_PATH . '/' . self::QUEUE;
        $dir = dirname($p);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (@file_put_contents($p, $line, FILE_APPEND | LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'cannot append queue'];
        }

        EvolutionLogger::log('figma_assets', 'enqueue', ['target' => $target]);

        return ['ok' => true];
    }
}
