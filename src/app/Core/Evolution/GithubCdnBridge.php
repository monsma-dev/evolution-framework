<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Leest ruwe bron van GitHub (raw) zonder volledige clone — alleen allowlisted hosts.
 */
final class GithubCdnBridge
{
    private const ALLOWED_HOSTS = [
        'raw.githubusercontent.com',
        'gist.githubusercontent.com',
        'cdn.jsdelivr.net',
    ];

    /**
     * @return array{ok: bool, body?: string, bytes?: int, error?: string}
     */
    public static function fetchRaw(string $url, int $maxBytes = 512000): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'error' => 'empty url'];
        }
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return ['ok' => false, 'error' => 'invalid url'];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || !in_array($host, self::ALLOWED_HOSTS, true)) {
            return ['ok' => false, 'error' => 'https required; host must be raw.githubusercontent.com, gist.githubusercontent.com, or cdn.jsdelivr.net'];
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 25,
                'header' => "User-Agent: EvolutionLibraryScout/1.0\r\nAccept: text/plain,*/*\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if (!is_string($body)) {
            return ['ok' => false, 'error' => 'fetch failed'];
        }
        if (strlen($body) > $maxBytes) {
            $body = substr($body, 0, $maxBytes) . "\n…(truncated)";
        }

        return ['ok' => true, 'body' => $body, 'bytes' => strlen($body)];
    }

    /**
     * Converteer github.com/blob/... naar raw.githubusercontent.com/.../HEAD/... (best effort).
     */
    public static function blobUrlToRaw(string $blobUrl): ?string
    {
        $blobUrl = trim($blobUrl);
        if (!preg_match('#^https://github\.com/([^/]+)/([^/]+)/blob/([^/]+)/(.+)$#', $blobUrl, $m)) {
            return null;
        }

        return 'https://raw.githubusercontent.com/' . $m[1] . '/' . $m[2] . '/' . $m[3] . '/' . $m[4];
    }
}
