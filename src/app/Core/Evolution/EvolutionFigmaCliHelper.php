<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * CLI / tooling helpers: token + file probe, FILE_UPDATE webhook registration (Figma API v2).
 *
 * @see EvolutionFigmaService Core push/pull/manifest flows.
 */
final class EvolutionFigmaCliHelper
{
    /**
     * @return array{ok: bool, file_key: string, site_base: string, webhook_endpoint: string, figma_bridge_enabled: bool, token_configured: bool, webhook_passcode_configured: bool, me?: array<string, mixed>|null, file_meta?: array<string, mixed>|null, error?: string, http_hints?: list<string>}
     */
    public static function status(Container $container): array
    {
        $cfg = $container->get('config');
        $hints = [];
        $base = rtrim((string) $cfg->get('site.url', ''), '/');
        if ($base === '') {
            $hints[] = 'site.url leeg — zet APP_URL of SITE_URL in .env zodat het webhook-endpoint klopt.';
        }

        $fileKey = self::resolveFileKey($cfg);
        $token = EvolutionFigmaService::accessTokenForBridge($cfg);
        $passOk = self::webhookPasscodeNonEmpty($cfg);
        $endpoint = $base !== '' ? $base . '/api/v1/evolution/figma-webhook' : '';

        $out = [
            'ok' => true,
            'figma_bridge_enabled' => EvolutionFigmaService::isEnabled($cfg),
            'file_key' => $fileKey,
            'site_base' => $base,
            'webhook_endpoint' => $endpoint,
            'token_configured' => $token !== '',
            'webhook_passcode_configured' => $passOk,
            'http_hints' => $hints,
        ];

        if ($token === '') {
            $out['ok'] = false;
            $out['error'] = 'FIGMA_ACCESS_TOKEN ontbreekt (of evolution.figma_bridge.access_token).';

            return $out;
        }

        $me = self::figmaHttpJson('GET', 'https://api.figma.com/v1/me', $token, null);
        $out['me'] = $me['data'] ?? null;
        if (!($me['ok'] ?? false)) {
            $out['ok'] = false;
            $out['error'] = $me['error'] ?? 'GET /v1/me failed';
            $out['http_code'] = $me['http_code'] ?? 0;

            return $out;
        }

        if ($fileKey === '') {
            $out['ok'] = false;
            $out['error'] = 'Geen file_key — zet FIGMA_FILE_KEY of evolution.figma_bridge.file_key.';

            return $out;
        }

        $file = self::figmaHttpJson(
            'GET',
            'https://api.figma.com/v1/files/' . rawurlencode($fileKey) . '?depth=1',
            $token,
            null
        );
        $out['file_meta'] = is_array($file['data'] ?? null)
            ? [
                'name' => $file['data']['name'] ?? '',
                'lastModified' => $file['data']['lastModified'] ?? '',
                'version' => $file['data']['version'] ?? '',
            ]
            : null;
        if (!($file['ok'] ?? false)) {
            $out['ok'] = false;
            $out['error'] = $file['error'] ?? 'GET /v1/files/{key} failed';
            $out['http_code_file'] = $file['http_code'] ?? 0;
        }

        if (!$passOk) {
            $hints[] = 'FIGMA_WEBHOOK_PASSCODE leeg — webhook body wordt door /figma-webhook geweigerd.';
        }

        $out['http_hints'] = $hints;

        return $out;
    }

    /**
     * Registers FILE_UPDATE → POST /api/v1/evolution/figma-webhook (Figma v2 webhooks).
     *
     * @return array{ok: bool, http_code?: int, endpoint?: string, response?: mixed, error?: string}
     */
    public static function registerWebhook(Container $container, ?string $siteBaseOverride = null): array
    {
        $cfg = $container->get('config');
        $token = EvolutionFigmaService::accessTokenForBridge($cfg);
        $fileKey = self::resolveFileKey($cfg);
        $pass = self::webhookPasscodeRaw($cfg);

        $base = $siteBaseOverride !== null && $siteBaseOverride !== ''
            ? rtrim($siteBaseOverride, '/')
            : rtrim((string) $cfg->get('site.url', ''), '/');

        if ($token === '' || $pass === '') {
            return ['ok' => false, 'error' => 'FIGMA_ACCESS_TOKEN en FIGMA_WEBHOOK_PASSCODE zijn verplicht.'];
        }
        if ($base === '') {
            return ['ok' => false, 'error' => 'Publieke basis-URL ontbreekt — zet APP_URL/SITE_URL of gebruik --base=https://...'];
        }
        if ($fileKey === '') {
            return ['ok' => false, 'error' => 'file_key ontbreekt — FIGMA_FILE_KEY of evolution.figma_bridge.file_key.'];
        }

        $probe = self::figmaHttpJson(
            'GET',
            'https://api.figma.com/v1/files/' . rawurlencode($fileKey) . '?depth=1',
            $token,
            null
        );
        if (!($probe['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $probe['error'] ?? 'file probe failed',
                'http_code' => $probe['http_code'] ?? 0,
            ];
        }

        $endpoint = $base . '/api/v1/evolution/figma-webhook';
        $payload = [
            'event_type' => 'FILE_UPDATE',
            'context' => 'file',
            'context_id' => $fileKey,
            'endpoint' => $endpoint,
            'passcode' => $pass,
            'description' => 'Framework Evolution',
        ];

        $post = self::figmaHttpJson(
            'POST',
            'https://api.figma.com/v2/webhooks',
            $token,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        if (!($post['ok'] ?? false)) {
            return [
                'ok' => false,
                'endpoint' => $endpoint,
                'error' => $post['error'] ?? 'POST /v2/webhooks failed',
                'http_code' => $post['http_code'] ?? 0,
                'response' => $post['data'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'endpoint' => $endpoint,
            'http_code' => $post['http_code'] ?? 201,
            'response' => $post['data'] ?? null,
        ];
    }

    public static function resolveFileKey(Config $config): string
    {
        $fb = $config->get('evolution.figma_bridge', []);
        if (is_array($fb)) {
            $k = trim((string) ($fb['file_key'] ?? ''));
            if ($k !== '') {
                return $k;
            }
        }

        return trim((string) (getenv('FIGMA_FILE_KEY') ?: ''));
    }

    private static function webhookPasscodeNonEmpty(Config $config): bool
    {
        return self::webhookPasscodeRaw($config) !== '';
    }

    private static function webhookPasscodeRaw(Config $config): string
    {
        $fb = $config->get('evolution.figma_bridge', []);
        $p = is_array($fb) ? trim((string) ($fb['webhook_passcode'] ?? '')) : '';
        if ($p !== '') {
            return $p;
        }

        return trim((string) ($_ENV['FIGMA_WEBHOOK_PASSCODE'] ?? getenv('FIGMA_WEBHOOK_PASSCODE') ?: ''));
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>|null, error?: string, http_code?: int}
     */
    private static function figmaHttpJson(string $method, string $url, string $token, ?string $jsonBody): array
    {
        $headers = [
            'X-Figma-Token: ' . $token,
            'Accept: application/json',
        ];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $jsonBody ?? '';
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($jsonBody !== null) {
                $opts[CURLOPT_POSTFIELDS] = $jsonBody;
            }
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body)) {
            return ['ok' => false, 'error' => 'curl failed', 'http_code' => $code];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'HTTP ' . $code . ' ' . mb_substr($body, 0, 500), 'http_code' => $code];
        }
        if ($body === '') {
            return ['ok' => true, 'data' => [], 'http_code' => $code];
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'error' => 'json: ' . $e->getMessage(), 'http_code' => $code];
        }

        return is_array($data) ? ['ok' => true, 'data' => $data, 'http_code' => $code] : ['ok' => false, 'error' => 'invalid json shape'];
    }
}
