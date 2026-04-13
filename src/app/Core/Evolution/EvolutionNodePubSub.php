<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Fire-and-forget Redis PUBLISH for evolution-worker (optional; requires Redis reachable).
 * Uses raw RESP over TCP — no php-redis extension required.
 */
final class EvolutionNodePubSub
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function tryPublish(string $event, array $payload = []): void
    {
        $cfg = self::resolveConfig();
        if ($cfg === null) {
            return;
        }

        $host = $cfg['host'];
        $port = $cfg['port'];
        $channel = $cfg['channel'];
        $body = json_encode(
            array_merge(['event' => $event, 'ts' => gmdate('c')], $payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($body)) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            0.15,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($fp)) {
            return;
        }
        stream_set_timeout($fp, 0, 200000);
        $cmd = self::encodePublish($channel, $body);
        @fwrite($fp, $cmd);
        // Read optional reply (skip)
        @fread($fp, 512);
        @fclose($fp);
    }

    /**
     * @return array{host: string, port: int, channel: string}|null
     */
    private static function resolveConfig(): ?array
    {
        $config = self::getConfig();
        if ($config === null) {
            return null;
        }
        $nb = $config->get('evolution.node_bridge', []);
        if (!is_array($nb)) {
            return null;
        }
        $rs = $nb['redis_pubsub'] ?? [];
        if (!is_array($rs) || !filter_var($rs['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }
        $host = trim((string) ($rs['host'] ?? '127.0.0.1'));
        $port = max(1, min(65535, (int) ($rs['port'] ?? 6379)));
        $channel = trim((string) ($rs['channel'] ?? 'evolution:events'));
        if ($host === '' || $channel === '') {
            return null;
        }

        return ['host' => $host, 'port' => $port, 'channel' => $channel];
    }

    private static function getConfig(): ?Config
    {
        if (isset(($GLOBALS)['app_container'])) {
            try {
                $c = ($GLOBALS)['app_container'];
                if (is_object($c) && method_exists($c, 'get')) {
                    $cfg = $c->get('config');

                    return $cfg instanceof Config ? $cfg : null;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private static function encodePublish(string $channel, string $message): string
    {
        $parts = ['PUBLISH', $channel, $message];

        $out = '*' . (string) count($parts) . "\r\n";
        foreach ($parts as $p) {
            $b = $p;
            $out .= '$' . (string) strlen($b) . "\r\n" . $b . "\r\n";
        }

        return $out;
    }
}
