<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Placeholder for a future Mercure (or SSE) bridge for live Architect streaming.
 * Configure evolution.architect_stream in evolution.json when ready.
 */
final class ArchitectStream
{
    public function __construct(private readonly Config $config)
    {
    }

    public function mercurePublicUrl(): string
    {
        $s = $this->config->get('evolution.architect_stream', []);
        if (!is_array($s)) {
            return '';
        }

        return trim((string)($s['mercure_public_url'] ?? ''));
    }

    /**
     * @return array{enabled: bool, url: string, hint: string}
     */
    public function status(): array
    {
        $url = $this->mercurePublicUrl();

        return [
            'enabled' => $url !== '',
            'url' => $url,
            'hint' => $url === ''
                ? 'Set evolution.architect_stream.mercure_public_url to enable push updates.'
                : 'Mercure URL configured.',
        ];
    }
}
