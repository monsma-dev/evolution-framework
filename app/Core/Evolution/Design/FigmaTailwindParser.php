<?php

declare(strict_types=1);

namespace App\Core\Evolution\Design;

/**
 * FigmaTailwindParser — reads Figma File API and extracts design context for the DesignAgent.
 *
 * Maps:
 *   Figma frames    → flex/grid container classes
 *   Text nodes      → typography Tailwind classes (text-xl, font-semibold, etc.)
 *   Fill colors     → closest Tailwind color (bg-*, text-*, border-*)
 *   Constraints     → responsive breakpoint hints
 *   Auto layout     → flex / grid with gap, padding, alignment
 *   Vectors/SVG     → exported as inline SVG placeholders
 *
 * Asset export: saves images to public/assets/design/figma/{node_id}.{ext}
 */
final class FigmaTailwindParser
{
    private const API_BASE   = 'https://api.figma.com/v1';
    private const ASSET_DIR  = 'public/assets/design/figma';
    private const TIMEOUT    = 15;

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Parse a Figma URL and return design context for the DesignAgent.
     *
     * @return array{ok: bool, context?: array, node_name?: string, assets?: list<string>, error?: string}
     */
    public function parseUrl(string $figmaUrl, string $pat): array
    {
        $parsed = FigmaUrlParser::parseFileKeyAndNodeId($figmaUrl);
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'Could not extract Figma file key from URL'];
        }

        return $this->parseNode($parsed['file_key'], $parsed['node_id'], $pat);
    }

    /**
     * Parse a specific node from a Figma file.
     *
     * @return array{ok: bool, context?: array, node_name?: string, assets?: list<string>, error?: string}
     */
    public function parseNode(string $fileKey, string $nodeId, string $pat): array
    {
        $endpoint = $nodeId !== ''
            ? sprintf('%s/files/%s/nodes?ids=%s', self::API_BASE, $fileKey, rawurlencode($nodeId))
            : sprintf('%s/files/%s', self::API_BASE, $fileKey);

        $res = $this->apiGet($endpoint, $pat);
        if ($res === null) {
            return ['ok' => false, 'error' => 'Figma API request failed (check FIGMA_ACCESS_TOKEN and file access scopes)'];
        }

        $raw = $res['body'];
        $httpCode = $res['status'] ?? 0;
        if ($httpCode < 200 || $httpCode >= 300) {
            $hint = mb_substr($raw, 0, 280);

            return ['ok' => false, 'error' => 'Figma API HTTP ' . $httpCode . ($hint !== '' ? ': ' . $hint : '')];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Figma API returned invalid JSON'];
        }

        // Extract the target node
        $node = null;
        $nodeName = 'component';
        if ($nodeId !== '' && isset($data['nodes'][$nodeId]['document'])) {
            $node = $data['nodes'][$nodeId]['document'];
            $nodeName = (string)($node['name'] ?? 'component');
        } elseif (isset($data['document'])) {
            $node = $data['document'];
            $nodeName = (string)($data['name'] ?? 'design');
        }

        if ($node === null) {
            return ['ok' => false, 'error' => 'Target node not found in Figma response'];
        }

        // Build design context
        $context = $this->extractContext($node);

        // Export assets (images/vectors)
        $assets = $this->exportAssets($fileKey, $node, $pat);

        return [
            'ok'        => true,
            'node_name' => preg_replace('/[^a-z0-9\-_]/', '-', strtolower($nodeName)),
            'context'   => $context,
            'assets'    => $assets,
        ];
    }

    // ─── Context extraction ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $node
     * @return array{type: string, name: string, layout: string, tailwind_hints: list<string>, children: list<array>, text?: string, style?: array}
     */
    private function extractContext(array $node): array
    {
        $type = (string)($node['type'] ?? 'UNKNOWN');
        $name = (string)($node['name'] ?? '');

        $ctx = [
            'type'          => $type,
            'name'          => $name,
            'layout'        => $this->inferLayout($node),
            'tailwind_hints' => $this->nodeToTailwindHints($node),
            'children'      => [],
        ];

        if (isset($node['characters'])) {
            $ctx['text'] = (string)$node['characters'];
            $ctx['style'] = $this->textStyleToTailwind($node['style'] ?? []);
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach (array_slice($node['children'], 0, 20) as $child) {
                if (is_array($child)) {
                    $ctx['children'][] = $this->extractContext($child);
                }
            }
        }

        return $ctx;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function nodeToTailwindHints(array $node): array
    {
        $classes = [];
        $type = (string)($node['type'] ?? '');

        // Auto-layout → flex/grid
        if (isset($node['layoutMode'])) {
            $mode = (string)$node['layoutMode'];
            if ($mode === 'HORIZONTAL') {
                $classes[] = 'flex flex-row items-center';
            } elseif ($mode === 'VERTICAL') {
                $classes[] = 'flex flex-col';
            }
            if (isset($node['itemSpacing'])) {
                $classes[] = $this->spacingToGap((float)$node['itemSpacing']);
            }
        }

        // Padding
        $pad = $this->extractPadding($node);
        if ($pad !== '') {
            $classes[] = $pad;
        }

        // Background fill
        $fills = $node['fills'] ?? [];
        if (is_array($fills) && count($fills) > 0 && isset($fills[0]['color'])) {
            $bg = $this->colorToTailwindBg($fills[0]['color']);
            if ($bg !== '') {
                $classes[] = $bg;
            }
        }

        // Border radius
        if (isset($node['cornerRadius'])) {
            $classes[] = $this->radiusToTailwind((float)$node['cornerRadius']);
        }

        // Width hints
        if (isset($node['absoluteBoundingBox']['width'])) {
            $w = (float)$node['absoluteBoundingBox']['width'];
            if ($w >= 1200) {
                $classes[] = 'w-full max-w-7xl';
            } elseif ($w >= 768) {
                $classes[] = 'w-full max-w-4xl';
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param array<string, mixed> $style
     * @return array{size: string, weight: string, color: string, align: string}
     */
    private function textStyleToTailwind(array $style): array
    {
        $size = match (true) {
            ($style['fontSize'] ?? 0) >= 36 => 'text-4xl',
            ($style['fontSize'] ?? 0) >= 30 => 'text-3xl',
            ($style['fontSize'] ?? 0) >= 24 => 'text-2xl',
            ($style['fontSize'] ?? 0) >= 20 => 'text-xl',
            ($style['fontSize'] ?? 0) >= 18 => 'text-lg',
            ($style['fontSize'] ?? 0) >= 14 => 'text-sm',
            ($style['fontSize'] ?? 0) >= 12 => 'text-xs',
            default                         => 'text-base',
        };

        $weight = match ((string)($style['fontWeight'] ?? '400')) {
            '700', '800', '900' => 'font-bold',
            '600'               => 'font-semibold',
            '500'               => 'font-medium',
            default             => 'font-normal',
        };

        $align = match ((string)($style['textAlignHorizontal'] ?? 'LEFT')) {
            'CENTER' => 'text-center',
            'RIGHT'  => 'text-right',
            default  => 'text-left',
        };

        return ['size' => $size, 'weight' => $weight, 'color' => 'text-slate-900', 'align' => $align];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function inferLayout(array $node): string
    {
        $type = (string)($node['type'] ?? '');
        if (in_array($type, ['FRAME', 'COMPONENT', 'INSTANCE'], true)) {
            return isset($node['layoutMode']) ? 'flex' : 'block';
        }
        if ($type === 'TEXT') {
            return 'inline';
        }
        if (in_array($type, ['VECTOR', 'BOOLEAN_OPERATION', 'STAR', 'LINE', 'ELLIPSE', 'POLYGON', 'RECTANGLE'], true)) {
            return 'svg';
        }
        return 'block';
    }

    // ─── Color mapping ───────────────────────────────────────────────────────

    /**
     * @param array{r?: float, g?: float, b?: float, a?: float} $color
     */
    private function colorToTailwindBg(array $color): string
    {
        $r = (int)(($color['r'] ?? 0) * 255);
        $g = (int)(($color['g'] ?? 0) * 255);
        $b = (int)(($color['b'] ?? 0) * 255);

        // Near-white
        if ($r > 240 && $g > 240 && $b > 240) {
            return 'bg-white';
        }
        // Near-black
        if ($r < 30 && $g < 30 && $b < 30) {
            return 'bg-slate-900';
        }
        // Blue family
        if ($b > $r && $b > $g && $b > 100) {
            return $b > 180 ? 'bg-blue-600' : 'bg-blue-500';
        }
        // Slate/gray
        if (abs($r - $g) < 20 && abs($g - $b) < 20) {
            $avg = ($r + $g + $b) / 3;
            return match (true) {
                $avg > 200 => 'bg-slate-100',
                $avg > 150 => 'bg-slate-200',
                $avg > 100 => 'bg-slate-400',
                default    => 'bg-slate-700',
            };
        }
        // Emerald/green
        if ($g > $r && $g > $b) {
            return 'bg-emerald-500';
        }
        // Amber/yellow
        if ($r > 200 && $g > 150 && $b < 50) {
            return 'bg-amber-400';
        }
        // Red
        if ($r > 180 && $g < 80 && $b < 80) {
            return 'bg-red-500';
        }

        return '';
    }

    private function spacingToGap(float $spacing): string
    {
        return match (true) {
            $spacing <= 4  => 'gap-1',
            $spacing <= 8  => 'gap-2',
            $spacing <= 12 => 'gap-3',
            $spacing <= 16 => 'gap-4',
            $spacing <= 24 => 'gap-6',
            $spacing <= 32 => 'gap-8',
            default        => 'gap-10',
        };
    }

    private function radiusToTailwind(float $radius): string
    {
        return match (true) {
            $radius <= 2  => 'rounded-sm',
            $radius <= 4  => 'rounded',
            $radius <= 8  => 'rounded-lg',
            $radius <= 12 => 'rounded-xl',
            $radius <= 16 => 'rounded-2xl',
            $radius >= 50 => 'rounded-full',
            default       => 'rounded-3xl',
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    private function extractPadding(array $node): string
    {
        $pt = (float)($node['paddingTop'] ?? $node['verticalPadding'] ?? 0);
        $pr = (float)($node['paddingRight'] ?? $node['horizontalPadding'] ?? 0);
        $pb = (float)($node['paddingBottom'] ?? $node['verticalPadding'] ?? 0);
        $pl = (float)($node['paddingLeft'] ?? $node['horizontalPadding'] ?? 0);

        if ($pt === $pr && $pr === $pb && $pb === $pl && $pt > 0) {
            return $this->pxToPadding($pt);
        }
        if ($pt === $pb && $pr === $pl && $pt > 0) {
            return $this->pxToPadding($pt, 'y') . ' ' . $this->pxToPadding($pr, 'x');
        }
        return '';
    }

    private function pxToPadding(float $px, string $dir = ''): string
    {
        $pre = $dir !== '' ? "p{$dir}-" : 'p-';
        return match (true) {
            $px <= 4  => $pre . '1',
            $px <= 8  => $pre . '2',
            $px <= 12 => $pre . '3',
            $px <= 16 => $pre . '4',
            $px <= 20 => $pre . '5',
            $px <= 24 => $pre . '6',
            $px <= 32 => $pre . '8',
            default   => $pre . '10',
        };
    }

    // ─── Asset export ─────────────────────────────────────────────────────────

    /**
     * Export image/vector assets from Figma to public/assets/design/figma/
     *
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function exportAssets(string $fileKey, array $node, string $pat): array
    {
        $imageNodeIds = $this->collectImageNodeIds($node);
        if (empty($imageNodeIds)) {
            return [];
        }

        $url = sprintf('%s/images/%s?ids=%s&format=svg', self::API_BASE, $fileKey, implode(',', array_slice($imageNodeIds, 0, 10)));
        $res = $this->apiGet($url, $pat);
        if ($res === null) {
            return [];
        }
        $st = $res['status'] ?? 0;
        if ($st < 200 || $st >= 300) {
            return [];
        }
        $raw = $res['body'];
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['images'])) {
            return [];
        }

        $saved = [];
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $dir = $base . '/' . self::ASSET_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        foreach ($data['images'] as $nodeId => $imageUrl) {
            if (!is_string($imageUrl) || $imageUrl === '') {
                continue;
            }
            $filename = preg_replace('/[^a-z0-9\-]/', '-', strtolower($nodeId)) . '.svg';
            $path = $dir . '/' . $filename;
            $content = @file_get_contents($imageUrl, false, stream_context_create(['http' => ['timeout' => self::TIMEOUT]]));
            if ($content !== false) {
                @file_put_contents($path, $content);
                $saved[] = '/assets/design/figma/' . $filename;
            }
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function collectImageNodeIds(array $node): array
    {
        $ids = [];
        $type = (string)($node['type'] ?? '');
        if (in_array($type, ['VECTOR', 'BOOLEAN_OPERATION', 'STAR', 'LINE', 'ELLIPSE', 'POLYGON', 'RECTANGLE'], true)) {
            if (!empty($node['fills'])) {
                $ids[] = (string)($node['id'] ?? '');
            }
        }
        foreach ((array)($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $ids = array_merge($ids, $this->collectImageNodeIds($child));
            }
        }
        return array_values(array_filter(array_unique($ids)));
    }

    // ─── HTTP helpers ─────────────────────────────────────────────────────────

    /**
     * @return array{body: string, status: int}|null
     */
    private function apiGet(string $url, string $pat): ?array
    {
        $headerSets = [
            ['Authorization: Bearer ' . $pat, 'Accept: application/json', 'User-Agent: EvolutionDesignAgent/1.0'],
            ['X-Figma-Token: ' . $pat, 'Accept: application/json', 'User-Agent: EvolutionDesignAgent/1.0'],
        ];

        $lastBody = '';
        $lastStatus = 0;
        foreach ($headerSets as $headers) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'timeout' => self::TIMEOUT,
                    'ignore_errors' => true,
                ],
            ]);
            $http_response_header = [];
            $result = @file_get_contents($url, false, $ctx);
            $code = 0;
            if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
            if ($result !== false && $result !== '') {
                $lastBody = $result;
                $lastStatus = $code;
                if ($code >= 200 && $code < 300) {
                    return ['body' => $result, 'status' => $code];
                }
            }
        }

        if ($lastBody !== '') {
            return ['body' => $lastBody, 'status' => $lastStatus];
        }

        return null;
    }

}
