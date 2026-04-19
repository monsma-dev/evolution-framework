<?php

declare(strict_types=1);

namespace App\Core\Evolution\Design;

/**
 * Shared Figma URL → file key + node id (branch links, design/file/proto).
 */
final class FigmaUrlParser
{
    /**
     * @return array{file_key: string, node_id: string}|null null when no file key can be read
     */
    public static function parseFileKeyAndNodeId(string $url): ?array
    {
        $fileKey = '';
        // Branch file: …/design/OLDKEY/branch/BRANCHKEY/title — API uses branch key as file key.
        if (preg_match('#figma\.com/(?:design|file)/[A-Za-z0-9]+/branch/([A-Za-z0-9]+)#', $url, $br)) {
            $fileKey = $br[1];
        } elseif (preg_match('#figma\.com/(?:design|file|proto)/([A-Za-z0-9]+)#', $url, $m)) {
            $fileKey = $m[1];
        }

        if ($fileKey === '') {
            return null;
        }

        $nodeId = '';
        if (preg_match('/[?&]node-id=([^&]+)/', $url, $nm)) {
            $raw = rawurldecode(str_replace(['%3A'], [':'], $nm[1]));
            if (preg_match('/([0-9]+)[:\-]([0-9]+)/', $raw, $parts)) {
                $nodeId = $parts[1] . ':' . $parts[2];
            }
        }

        return ['file_key' => $fileKey, 'node_id' => $nodeId];
    }
}
