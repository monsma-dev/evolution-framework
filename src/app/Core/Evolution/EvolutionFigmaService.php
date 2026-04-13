<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Reverse design engineering: Framework → Figma manifest + optional REST sync;
 * Figma → CSS token hints via File API (nodes).
 *
 * OAuth / PAT scopes (Figma account → developer settings): enable **file content** read + write
 * on the Personal Access Token or OAuth app. Exact labels vary ("File content: Read/Write",
 * or `file_content:read` / `file_content:write` in scope lists). Webhooks are configured per
 * Figma app and require the webhook **passcode** to match {@see self::verifyWebhookPasscode()}.
 *
 * Note: Creating arbitrary vector layers from PHP still typically flows through a **Figma Plugin**
 * importing {@see self::OUTPUT_PLUGIN_PAYLOAD} JSON; the REST API is used here for **read** + metadata.
 *
 * **Clean Slate:** {@see self::clearFigmaCanvas()} collects top-level layer IDs per page (GET /v1/files/:key)
 * and attempts {@see self::figmaApiDeleteNodes()} — Figma’s public REST API may not expose node deletion;
 * if DELETE fails, the response includes `collected_node_ids` so an agent can delete via the **Plugin API**
 * or MCP `use_figma`. Always duplicate the Figma file before destructive operations.
 */
final class EvolutionFigmaService
{
    public const OUTPUT_DIR = 'storage/evolution/figma';

    public const MANIFEST_FILE = 'storage/evolution/figma/reverse_sync_manifest.json';

    public const PLUGIN_PAYLOAD = 'storage/evolution/figma/plugin_import_payload.json';

    public const LAST_FILE_META = 'storage/evolution/figma/last_figma_file_meta.json';

    public const PULL_EVENTS_LOG = 'storage/evolution/figma_webhook_events.jsonl';

    public function __construct(private readonly Container $container)
    {
    }

    public static function isEnabled(Config $config): bool
    {
        $fb = $config->get('evolution.figma_bridge', []);

        return is_array($fb) && filter_var($fb['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    public static function isWebhookEnabled(Config $config): bool
    {
        $fb = $config->get('evolution.figma_bridge', []);

        return is_array($fb)
            && filter_var($fb['enabled'] ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($fb['webhook_enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    public function promptSection(): string
    {
        $cfg = $this->container->get('config');
        if (!self::isEnabled($cfg)) {
            return '';
        }
        $fb = $cfg->get('evolution.figma_bridge', []);
        if (!is_array($fb) || !filter_var($fb['ghost_suggest'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $fileKey = trim((string) ($fb['file_key'] ?? ''));
        $frame = trim((string) ($fb['ghost_frame_name'] ?? 'Ghost — A/B variant'));

        return "\nFIGMA_GHOST (optional):\n"
            . '- If figma_bridge is configured, you may describe a **hidden frame** concept for A/B UI (e.g. "' . $frame . '") '
            . "and reference file key `{$fileKey}` for human follow-up in Figma.\n"
            . "- Do not claim layers were created unless pushDesignToFigma was run; keep suggestions as **ui_autofix**-sized.\n"
            . "\nFIGMA_CLEAN_SLATE (when user asks full reverse sync / push live site to Figma):\n"
            . "- Code/CSS/Twig in the repo is authoritative; treat existing Figma layers as stale until re-injected.\n"
            . "- Order: (1) GET /v1/files/{file_key} → collect **top-level** node ids under each CANVAS page → DELETE batches via REST if available, else use Plugin API / MCP with returned ids; "
            . '(2) clear local ' . self::MANIFEST_FILE . "; (3) run pushDesignToFigma() or cleanSlatePushToFigma().\n"
            . "- Destructive: confirm in admin API (confirm:true) or duplicate the Figma file first.\n";
    }

    /**
     * Collect Tailwind/CSS tokens + view inventory; write manifest + plugin-oriented JSON; optionally GET /v1/files/:key.
     *
     * @return array{ok: bool, error?: string, manifest_path?: string, plugin_payload_path?: string, figma?: array<string, mixed>}
     */
    public function pushDesignToFigma(): array
    {
        $cfg = $this->container->get('config');
        if (!self::isEnabled($cfg)) {
            return ['ok' => false, 'error' => 'figma_bridge disabled'];
        }

        $dir = BASE_PATH . '/' . self::OUTPUT_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tokens = $this->collectCssTokens($cfg);
        $views = $this->collectTwigInventory($cfg);
        $manifest = [
            'generated_at' => gmdate('c'),
            'tokens' => $tokens,
            'twig_inventory' => $views,
            'note' => 'Import plugin_import_payload.json via a small Figma plugin, or use MCP use_figma to create frames from this structure.',
        ];

        $manifestPath = BASE_PATH . '/' . self::MANIFEST_FILE;
        @file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $pluginPayload = $this->buildPluginPayload($tokens, $views);
        $pluginPath = BASE_PATH . '/' . self::PLUGIN_PAYLOAD;
        @file_put_contents(
            $pluginPath,
            json_encode($pluginPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        EvolutionLogger::log('figma_bridge', 'push_manifest', ['tokens' => count($tokens['colors']), 'twig_files' => count($views['paths'])]);

        $out = [
            'ok' => true,
            'manifest_path' => self::MANIFEST_FILE,
            'plugin_payload_path' => self::PLUGIN_PAYLOAD,
        ];

        $fb = $cfg->get('evolution.figma_bridge', []);
        $fileKey = is_array($fb) ? trim((string) ($fb['file_key'] ?? '')) : '';
        $token = self::accessToken($cfg);
        if ($fileKey !== '' && $token !== '') {
            $fig = $this->figmaApiGet('/v1/files/' . rawurlencode($fileKey));
            if (!($fig['ok'] ?? false)) {
                $out['figma_error'] = $fig['error'] ?? 'figma request failed';
            } else {
                $meta = [
                    'fetched_at' => gmdate('c'),
                    'name' => $fig['data']['name'] ?? '',
                    'lastModified' => $fig['data']['lastModified'] ?? '',
                    'version' => $fig['data']['version'] ?? '',
                    'thumbnailUrl' => $fig['data']['thumbnailUrl'] ?? '',
                ];
                @file_put_contents(
                    BASE_PATH . '/' . self::LAST_FILE_META,
                    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
                );
                $out['figma'] = $meta;
            }
        } else {
            $out['figma'] = [
                'skipped' => true,
                'reason' => 'Set evolution.figma_bridge.file_key and FIGMA_ACCESS_TOKEN (or figma_bridge.access_token) to verify REST read.',
            ];
        }

        return $out;
    }

    /**
     * Delete local reverse-sync manifest (and stale plugin payload) so the next push starts clean.
     *
     * @return array{ok: bool, removed: list<string>}
     */
    public function clearLocalReverseSyncManifest(): array
    {
        $removed = [];
        $paths = [
            BASE_PATH . '/' . self::MANIFEST_FILE,
            BASE_PATH . '/' . self::PLUGIN_PAYLOAD,
        ];
        foreach ($paths as $abs) {
            if (is_file($abs) && @unlink($abs)) {
                $removed[] = str_replace(BASE_PATH . '/', '', $abs);
            }
        }

        EvolutionLogger::log('figma_bridge', 'clean_slate_manifest', ['removed' => $removed]);

        return ['ok' => true, 'removed' => $removed];
    }

    /**
     * GET file JSON, collect direct child node ids of every CANVAS (page) — layers sitting on each page.
     *
     * @return list<string>
     */
    public function collectTopLevelCanvasChildIdsFromFile(array $fileData): array
    {
        $doc = $fileData['document'] ?? null;
        if (!is_array($doc)) {
            return [];
        }
        $ids = [];
        $pages = $doc['children'] ?? [];
        if (!is_array($pages)) {
            return [];
        }
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $type = (string) ($page['type'] ?? '');
            if ($type !== 'CANVAS') {
                continue;
            }
            $children = $page['children'] ?? [];
            if (!is_array($children)) {
                continue;
            }
            foreach ($children as $ch) {
                if (is_array($ch) && isset($ch['id'])) {
                    $ids[] = (string) $ch['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * Attempt to remove top-level layers on all pages via REST DELETE (batch).
     * If Figma returns 4xx/405, `ok` is false and `collected_node_ids` is still returned for Plugin/MCP cleanup.
     *
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   http_code?: int,
     *   deleted_batches?: int,
     *   collected_node_ids?: list<string>,
     *   figma_rest_delete_supported?: bool,
     *   hint?: string
     * }
     */
    public function clearFigmaCanvas(): array
    {
        $cfg = $this->container->get('config');
        if (!self::isEnabled($cfg)) {
            return ['ok' => false, 'error' => 'figma_bridge disabled'];
        }
        $fb = $cfg->get('evolution.figma_bridge', []);
        $fileKey = is_array($fb) ? trim((string) ($fb['file_key'] ?? '')) : '';
        $token = self::accessToken($cfg);
        if ($fileKey === '' || $token === '') {
            return ['ok' => false, 'error' => 'Need file_key and FIGMA_ACCESS_TOKEN'];
        }

        $get = $this->figmaApiGet('/v1/files/' . rawurlencode($fileKey) . '?depth=4');
        if (!($get['ok'] ?? false) || !isset($get['data']) || !is_array($get['data'])) {
            return [
                'ok' => false,
                'error' => $get['error'] ?? 'GET /v1/files failed',
                'http_code' => $get['http_code'] ?? 0,
            ];
        }

        $ids = $this->collectTopLevelCanvasChildIdsFromFile($get['data']);
        if ($ids === []) {
            EvolutionLogger::log('figma_bridge', 'clear_canvas', ['note' => 'no_top_level_layers']);

            return [
                'ok' => true,
                'deleted_batches' => 0,
                'collected_node_ids' => [],
                'figma_rest_delete_supported' => true,
                'hint' => 'No top-level layers found on CANVAS pages (already empty or depth insufficient).',
            ];
        }

        $batchSize = 40;
        $batches = array_chunk($ids, $batchSize);
        $deletedOk = 0;
        $lastCode = 0;
        $lastErr = '';
        $anyUnsupported = false;

        foreach ($batches as $batch) {
            $q = '/v1/files/' . rawurlencode($fileKey) . '/nodes?ids=' . rawurlencode(implode(',', $batch));
            $del = $this->figmaApiDelete($q);
            $lastCode = (int) ($del['http_code'] ?? 0);
            if ($del['ok'] ?? false) {
                $deletedOk++;
            } else {
                $lastErr = (string) ($del['error'] ?? 'delete failed');
                if ($lastCode === 405 || $lastCode === 404 || $lastCode === 400) {
                    $anyUnsupported = true;
                }
                break;
            }
        }

        EvolutionLogger::log('figma_bridge', 'clear_canvas', [
            'ids' => count($ids),
            'batches_ok' => $deletedOk,
            'http' => $lastCode,
        ]);

        if ($deletedOk === count($batches)) {
            return [
                'ok' => true,
                'deleted_batches' => $deletedOk,
                'collected_node_ids' => $ids,
                'figma_rest_delete_supported' => true,
            ];
        }

        return [
            'ok' => false,
            'error' => $anyUnsupported
                ? 'figma_rest_delete_unsupported_or_forbidden'
                : ($lastErr !== '' ? $lastErr : 'DELETE failed'),
            'http_code' => $lastCode,
            'deleted_batches' => $deletedOk,
            'collected_node_ids' => $ids,
            'figma_rest_delete_supported' => false,
            'hint' => 'Figma’s public REST API often does not allow deleting document nodes. '
                . 'Use the returned collected_node_ids with a Figma Plugin (node.remove()) or MCP use_figma, '
                . 'or duplicate the file and clear manually. Then run pushDesignToFigma().',
        ];
    }

    /**
     * Clear manifest → optional canvas delete → regenerate manifest + plugin payload (+ file meta GET).
     *
     * @return array{ok: bool, error?: string, clean_manifest?: array, clear_canvas?: array, push?: array}
     */
    public function cleanSlatePushToFigma(bool $skipFigmaDelete = false): array
    {
        $clear = ['ok' => true, 'skipped' => true];
        if (!$skipFigmaDelete) {
            $clear = $this->clearFigmaCanvas();
        }

        $manifestClear = $this->clearLocalReverseSyncManifest();

        $push = $this->pushDesignToFigma();
        $pushOk = (bool) ($push['ok'] ?? false);
        $clearOk = $skipFigmaDelete || (bool) ($clear['ok'] ?? false);

        return [
            'ok' => $pushOk,
            'clean_slate_fully_applied' => $pushOk && $clearOk,
            'clear_canvas' => $clear,
            'clean_manifest' => $manifestClear,
            'push' => $push,
            'error' => !$pushOk ? (string) ($push['error'] ?? 'push failed') : null,
            'warning' => $pushOk && !$clearOk && !$skipFigmaDelete
                ? 'Canvas DELETE did not complete (REST may not support it). Manifest was reset and push ran; use collected_node_ids from clear_canvas with Plugin/MCP or duplicate file and clear manually.'
                : null,
        ];
    }

    /**
     * Read nodes via GET /v1/files/:key/nodes and derive :root CSS hex hints.
     *
     * @return array{ok: bool, error?: string, suggested_css_append?: string, colors?: list<array{name: string, hex: string}>}
     */
    public function pullDesignFromFigma(?string $nodeIdsCsv = null): array
    {
        $cfg = $this->container->get('config');
        if (!self::isEnabled($cfg)) {
            return ['ok' => false, 'error' => 'figma_bridge disabled'];
        }

        $fb = $cfg->get('evolution.figma_bridge', []);
        $fileKey = is_array($fb) ? trim((string) ($fb['file_key'] ?? '')) : '';
        $ids = $nodeIdsCsv ?? (is_array($fb) ? trim((string) ($fb['default_pull_node_ids'] ?? '')) : '');
        $token = self::accessToken($cfg);

        if ($fileKey === '' || $ids === '' || $token === '') {
            return [
                'ok' => false,
                'error' => 'Need file_key, default_pull_node_ids (or parameter), and FIGMA_ACCESS_TOKEN',
            ];
        }

        $q = '/v1/files/' . rawurlencode($fileKey) . '/nodes?ids=' . rawurlencode($ids);
        $res = $this->figmaApiGet($q);
        if (!($res['ok'] ?? false)) {
            return ['ok' => false, 'error' => $res['error'] ?? 'figma nodes failed'];
        }

        $nodes = $res['data']['nodes'] ?? [];
        $colors = [];
        foreach ($nodes as $nid => $wrap) {
            $doc = $wrap['document'] ?? null;
            if (is_array($doc)) {
                $colors = array_merge($colors, $this->extractFillsRecursive($doc, (string) $nid));
            }
        }

        $cssLines = [ '/* figma_bridge pull ' . gmdate('c') . ' */', ':root {' ];
        $i = 0;
        foreach ($colors as $c) {
            $i++;
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $c['name']) ?: 'color_' . $i;
            $cssLines[] = '  --from-figma-' . $name . ': ' . $c['hex'] . ';';
        }
        $cssLines[] = '}';
        $suggested = implode("\n", $cssLines);

        $pullPath = BASE_PATH . '/' . self::OUTPUT_DIR . '/last_pull_suggested.css';
        @file_put_contents($pullPath, $suggested . "\n");

        EvolutionLogger::log('figma_bridge', 'pull_nodes', ['colors' => count($colors)]);

        $result = [
            'ok' => true,
            'suggested_css_append' => $suggested,
            'colors' => $colors,
            'written_to' => self::OUTPUT_DIR . '/last_pull_suggested.css',
        ];

        FigmaMirrorService::afterSuccessfulPull($this->container, $result);
        $lab = DesignLighthouseService::scanAfterPull($this->container, $pullPath);
        if ($lab !== []) {
            $result['design_lab'] = $lab;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload raw webhook JSON
     */
    public function handleWebhookPayload(array $payload): array
    {
        EvolutionVaultService::markFigmaStructureDirty();
        $cfg = $this->container->get('config');
        $line = json_encode([
            'ts' => gmdate('c'),
            'event_type' => (string) ($payload['event_type'] ?? ''),
            'file_key' => (string) ($payload['file_key'] ?? ''),
            'webhook_id' => (string) ($payload['webhook_id'] ?? ''),
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(BASE_PATH . '/' . self::PULL_EVENTS_LOG, $line, FILE_APPEND | LOCK_EX);

        $fb = $cfg->get('evolution.figma_bridge', []);
        if (is_array($fb) && filter_var($fb['auto_pull_on_webhook'] ?? false, FILTER_VALIDATE_BOOL)) {
            $pull = $this->pullDesignFromFigma(null);

            return ['ok' => true, 'logged' => true, 'pull' => $pull];
        }

        return ['ok' => true, 'logged' => true];
    }

    public static function verifyWebhookPasscode(Config $config, string $passcode): bool
    {
        $fb = $config->get('evolution.figma_bridge', []);
        $expected = is_array($fb) ? trim((string) ($fb['webhook_passcode'] ?? '')) : '';
        if ($expected === '') {
            $expected = trim((string) ($_ENV['FIGMA_WEBHOOK_PASSCODE'] ?? getenv('FIGMA_WEBHOOK_PASSCODE') ?: ''));
        }
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $passcode);
    }

    /**
     * @return array{colors: list<array{name: string, value: string}>, raw_variables: list<string>}
     */
    private function collectCssTokens(Config $config): array
    {
        $paths = [];
        $evo = $config->get('evolution', []);
        if (is_array($evo)) {
            $fe = $evo['frontend_patches'] ?? [];
            if (is_array($fe) && isset($fe['css_file'])) {
                $paths[] = (string) $fe['css_file'];
            }
            $tt = $evo['theme_tokens'] ?? [];
            if (is_array($tt) && isset($tt['css_file'])) {
                $paths[] = (string) $tt['css_file'];
            }
        }

        $colors = [];
        $rawVars = [];
        foreach ($paths as $rel) {
            $rel = ltrim($rel, '/');
            $abs = BASE_PATH . '/' . $rel;
            if (!is_file($abs)) {
                continue;
            }
            $css = (string) @file_get_contents($abs);
            if ($css === '') {
                continue;
            }
            if (preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $css, $m)) {
                foreach ($m[1] as $idx => $varName) {
                    $val = trim((string) ($m[2][$idx] ?? ''));
                    $rawVars[] = $varName . ': ' . $val;
                    if (preg_match('/#([0-9a-fA-F]{3,8})\b/', $val, $hm)) {
                        $colors[] = ['name' => $varName, 'value' => '#' . $hm[1]];
                    }
                }
            }
            if (preg_match_all('/#([0-9a-fA-F]{3,8})\b/', $css, $hx)) {
                foreach ($hx[0] as $literal) {
                    $colors[] = ['name' => 'hex_' . substr(sha1($literal), 0, 6), 'value' => $literal];
                }
            }
        }

        return ['colors' => $colors, 'raw_variables' => array_slice(array_unique($rawVars), 0, 200)];
    }

    /**
     * @return array{paths: list<string>, sample: list<string>}
     */
    private function collectTwigInventory(Config $config): array
    {
        $root = BASE_PATH . '/src/resources/views';
        $paths = [];
        if (is_dir($root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (str_ends_with(strtolower($f->getFilename()), '.twig')) {
                    $paths[] = str_replace(BASE_PATH . '/', '', $f->getPathname());
                    if (count($paths) >= 80) {
                        break;
                    }
                }
            }
        }

        return [
            'paths' => $paths,
            'sample' => array_slice($paths, 0, 12),
        ];
    }

    /**
     * Minimal structure a companion plugin can turn into Frames + colored rects + labels.
     *
     * @param array{colors: list<array{name: string, value: string}>, raw_variables: list<string>} $tokens
     * @param array{paths: list<string>, sample: list<string>} $views
     *
     * @return array<string, mixed>
     */
    private function buildPluginPayload(array $tokens, array $views): array
    {
        $swatches = [];
        $y = 0;
        foreach (array_slice($tokens['colors'], 0, 48) as $c) {
            $swatches[] = [
                'type' => 'SWATCH',
                'name' => $c['name'],
                'hex' => $c['value'],
                'x' => 0,
                'y' => $y,
                'w' => 240,
                'h' => 32,
            ];
            $y += 40;
        }

        return [
            'format' => 'framework.figma_bridge.v1',
            'frames' => [
                [
                    'name' => 'Reverse sync — tokens',
                    'width' => 420,
                    'height' => max(400, $y + 80),
                    'children' => $swatches,
                ],
                [
                    'name' => 'Reverse sync — Twig inventory',
                    'width' => 640,
                    'height' => 400,
                    'children' => [
                        [
                            'type' => 'TEXT_BLOCK',
                            'text' => implode("\n", array_slice($views['paths'], 0, 40)),
                            'x' => 16,
                            'y' => 16,
                            'w' => 600,
                            'h' => 360,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{name: string, hex: string}>
     */
    private function extractFillsRecursive(array $node, string $prefix): array
    {
        $out = [];
        $name = (string) ($node['name'] ?? 'node');
        $id = (string) ($node['id'] ?? '');
        if (isset($node['fills']) && is_array($node['fills'])) {
            foreach ($node['fills'] as $fill) {
                if (!is_array($fill) || ($fill['type'] ?? '') !== 'SOLID') {
                    continue;
                }
                $col = $fill['color'] ?? null;
                if (!is_array($col)) {
                    continue;
                }
                $r = (float) ($col['r'] ?? 0);
                $g = (float) ($col['g'] ?? 0);
                $b = (float) ($col['b'] ?? 0);
                $hex = sprintf(
                    '#%02x%02x%02x',
                    (int) round($r * 255),
                    (int) round($g * 255),
                    (int) round($b * 255)
                );
                $out[] = ['name' => $prefix . '_' . $name . '_' . $id, 'hex' => $hex];
            }
        }
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $ch) {
                if (is_array($ch)) {
                    $out = array_merge($out, $this->extractFillsRecursive($ch, $prefix));
                }
            }
        }

        return $out;
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, http_code?: int}
     */
    private function figmaApiGet(string $pathAndQuery): array
    {
        $cfg = $this->container->get('config');
        $token = self::accessToken($cfg);
        if ($token === '') {
            return ['ok' => false, 'error' => 'missing FIGMA_ACCESS_TOKEN'];
        }
        $url = 'https://api.figma.com' . $pathAndQuery;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'X-Figma-Token: ' . $token,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            return ['ok' => false, 'error' => 'curl failed', 'http_code' => $code];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'HTTP ' . $code . ' ' . mb_substr($body, 0, 400), 'http_code' => $code];
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'error' => 'json: ' . $e->getMessage()];
        }

        return is_array($data) ? ['ok' => true, 'data' => $data, 'http_code' => $code] : ['ok' => false, 'error' => 'invalid response'];
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, http_code?: int}
     */
    private function figmaApiDelete(string $pathAndQuery): array
    {
        $cfg = $this->container->get('config');
        $token = self::accessToken($cfg);
        if ($token === '') {
            return ['ok' => false, 'error' => 'missing FIGMA_ACCESS_TOKEN'];
        }
        $url = 'https://api.figma.com' . $pathAndQuery;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'X-Figma-Token: ' . $token,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            return ['ok' => false, 'error' => 'curl failed', 'http_code' => $code];
        }
        if ($code >= 200 && $code < 300) {
            if ($body === '') {
                return ['ok' => true, 'data' => [], 'http_code' => $code];
            }
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ['ok' => true, 'data' => ['raw' => $body], 'http_code' => $code];
            }

            return is_array($data) ? ['ok' => true, 'data' => $data, 'http_code' => $code] : ['ok' => true, 'data' => [], 'http_code' => $code];
        }

        return ['ok' => false, 'error' => 'HTTP ' . $code . ' ' . mb_substr($body, 0, 500), 'http_code' => $code];
    }

    /**
     * Same resolution as internal Figma REST calls — for CLI/gateway diagnostics only.
     */
    public static function accessTokenForBridge(Config $config): string
    {
        return self::accessToken($config);
    }

    private static function accessToken(Config $config): string
    {
        $fb = $config->get('evolution.figma_bridge', []);
        $t = is_array($fb) ? trim((string) ($fb['access_token'] ?? '')) : '';
        if ($t !== '') {
            return $t;
        }

        return trim((string) ($_ENV['FIGMA_ACCESS_TOKEN'] ?? getenv('FIGMA_ACCESS_TOKEN') ?: ''));
    }
}
