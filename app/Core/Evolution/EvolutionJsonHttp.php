<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Minimale POST JSON zonder Guzzle (werkt als vendor onvolledig is).
 */
final class EvolutionJsonHttp
{
    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: string}
     */
    public static function post(string $url, array $headers, array $json, int $timeoutSeconds = 12): array
    {
        $payload = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['ok' => false, 'status' => 0, 'body' => ''];
        }
        // Extend PHP execution limit for this HTTP call so long AI responses
        // don't hit max_execution_time (default 15s) before the stream timeout fires.
        @set_time_limit(max(30, $timeoutSeconds + 10));
        $h = '';
        foreach ($headers as $k => $v) {
            $h .= $k . ': ' . $v . "\r\n";
        }
        $h .= 'Content-Type: application/json' . "\r\n";
        $h .= 'Content-Length: ' . strlen($payload) . "\r\n";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $h,
                'content' => $payload,
                'timeout' => max(1, $timeoutSeconds),
                'ignore_errors' => true,
            ],
        ]);
        $http_response_header = [];
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }

        return [
            'ok' => is_string($raw) && $code > 0 && $code < 400,
            'status' => $code,
            'body' => is_string($raw) ? $raw : '',
        ];
    }

    /**
     * Lightweight GET (RSS, version endpoints) — no LLM cost.
     *
     * @return array{ok: bool, status: int, body: string}
     */
    public static function get(string $url, int $timeoutSeconds = 12): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(1, $timeoutSeconds),
                'ignore_errors' => true,
                'header' => "Accept: application/rss+xml, application/atom+xml, text/xml, */*;q=0.8\r\n",
            ],
        ]);
        $http_response_header = [];
        $raw = @file_get_contents($url, false, $ctx);
        // $http_response_header populated by fopen wrapper
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }

        return [
            'ok' => is_string($raw) && $raw !== '' && ($code === 0 || ($code >= 200 && $code < 400)),
            'status' => $code,
            'body' => is_string($raw) ? $raw : '',
        ];
    }
}
