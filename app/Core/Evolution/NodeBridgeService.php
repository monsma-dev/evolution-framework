<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Optional bridge to a local Node worker (Socket.io stream, Playwright QA, embedding offload).
 * Configure evolution.node_bridge in evolution.json; worker listens on node_bridge.port (default 3791).
 */
final class NodeBridgeService
{
    public static function isEnabled(Config $config): bool
    {
        $nb = $config->get('evolution.node_bridge', []);

        return is_array($nb) && filter_var($nb['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array{ok: bool, reachable?: bool, error?: string, detail?: array<string, mixed>}
     */
    public static function healthCheck(Container $container): array
    {
        $cfg = $container->get('config');
        $nb = $cfg->get('evolution.node_bridge', []);
        if (!is_array($nb) || !filter_var($nb['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'reachable' => false, 'error' => 'node_bridge disabled'];
        }
        $host = self::resolveHost($cfg);
        $port = max(1, min(65535, (int) ($nb['port'] ?? 3791)));
        $timeout = max(0.2, min(5.0, (float) ($nb['timeout_seconds'] ?? 1.0)));
        $unixFromEnv = trim((string) ($_ENV['EVOLUTION_NODE_UNIX_SOCKET'] ?? getenv('EVOLUTION_NODE_UNIX_SOCKET') ?: ''));
        $unixFromCfg = trim((string) ($nb['unix_socket'] ?? ''));
        $unix = $unixFromEnv !== '' ? $unixFromEnv : $unixFromCfg;
        $preferUnix = $unix !== ''
            && defined('CURLOPT_UNIX_SOCKET_PATH')
            && (
                $unixFromEnv !== ''
                || filter_var($nb['prefer_unix_socket'] ?? false, FILTER_VALIDATE_BOOL)
            );

        $url = 'http://' . $host . ':' . $port . '/health';
        if ($preferUnix) {
            $url = 'http://localhost/health';
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
        ];
        if ($preferUnix) {
            $opts[CURLOPT_UNIX_SOCKET_PATH] = $unix;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($preferUnix && (!is_string($body) || $code < 200 || $code >= 300)) {
            $url = 'http://' . $host . ':' . $port . '/health';
            $ch2 = curl_init($url);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => (int) ($timeout * 1000),
                CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            ]);
            $body = curl_exec($ch2);
            $code = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
        }

        if (!is_string($body)) {
            return ['ok' => false, 'reachable' => false, 'error' => 'curl failed to ' . $url];
        }
        if ($code >= 200 && $code < 300) {
            $j = json_decode($body, true);

            return [
                'ok' => true,
                'reachable' => true,
                'detail' => is_array($j) ? $j : ['raw' => mb_substr($body, 0, 200)],
            ];
        }

        return ['ok' => false, 'reachable' => false, 'error' => 'HTTP ' . $code, 'detail' => ['body' => mb_substr($body, 0, 200)]];
    }

    public static function promptAppend(Config $config): string
    {
        if (!self::isEnabled($config)) {
            return '';
        }

        $h = self::resolveHost($config);

        $unix = '';
        $nb = $config->get('evolution.node_bridge', []);
        if (is_array($nb) && filter_var($nb['prefer_unix_socket'] ?? false, FILTER_VALIDATE_BOOL)) {
            $p = trim((string) ($nb['unix_socket'] ?? ''));
            if ($p !== '') {
                $unix = '; unix health: ' . $p . ' (zero-latency vs TCP)';
            }
        }

        $out = "\n\nNODE_BRIDGE (optional): evolution-worker streams evolution.log (anomaly → pause), optional Redis SUB; host="
            . $h . ' (EVOLUTION_NODE_HOST in Docker).'
            . $unix;

        $evo = $config->get('evolution', []);
        $tb = is_array($evo) ? ($evo['toolbox'] ?? []) : [];
        if (is_array($tb) && filter_var($tb['inspector_enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $nb = $config->get('evolution.node_bridge', []);
            $port = max(1, min(65535, (int) (is_array($nb) ? ($nb['port'] ?? 3791) : 3791)));
            $out .= "\n\nEVOLUTION_TOOLBOX: Inspector POST http://{$h}:{$port}/toolbox/inspector JSON {\"url\",\"selectors\"[]} — X-Evolution-Toolbox-Key if EVOLUTION_TOOLBOX_KEY set; else localhost only. GET /toolbox/capabilities.";
        }

        return $out;
    }

    /**
     * Docker Compose: set EVOLUTION_NODE_HOST=evolution-worker so the app container reaches the worker.
     */
    private static function resolveHost(Config $config): string
    {
        $env = trim((string) ($_ENV['EVOLUTION_NODE_HOST'] ?? getenv('EVOLUTION_NODE_HOST') ?: ''));
        if ($env !== '') {
            return $env;
        }
        $nb = $config->get('evolution.node_bridge', []);

        return trim((string) (is_array($nb) ? ($nb['host'] ?? '127.0.0.1') : '127.0.0.1'));
    }
}
