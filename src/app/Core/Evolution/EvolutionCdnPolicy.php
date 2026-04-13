<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Whitelist for external script/style URLs used by evolution page_libraries.json.
 */
final class EvolutionCdnPolicy
{
    /** @var list<string> */
    private const DEFAULT_HOSTS = [
        'cdn.jsdelivr.net',
        'unpkg.com',
        'cdnjs.cloudflare.com',
        'esm.sh',
        'ga.jspm.io',
        'fastly.jsdelivr.net',
    ];

    public static function isAllowedUrl(string $url, ?Config $config = null): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        $hosts = self::DEFAULT_HOSTS;
        if ($config !== null) {
            $evo = $config->get('evolution.evolution_assets', []);
            $extra = is_array($evo) ? ($evo['cdn_hosts'] ?? []) : [];
            if (is_array($extra)) {
                foreach ($extra as $h) {
                    $h = strtolower(trim((string) $h));
                    if ($h !== '') {
                        $hosts[] = $h;
                    }
                }
            }
        }

        return in_array($host, array_unique($hosts), true);
    }
}
