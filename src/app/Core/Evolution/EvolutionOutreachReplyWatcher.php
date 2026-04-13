<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Future: detect inbound journalist replies (IMAP/webhook), notify Governor, optional draft in wiki.
 * Wire EvolutionWikiService / notification channel when provider credentials exist.
 */
final class EvolutionOutreachReplyWatcher
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    public function poll(Config $config): array
    {
        $o = $config->get('evolution.outreach', []);
        if (!is_array($o) || !filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'detail' => 'outreach disabled'];
        }

        return [
            'ok' => true,
            'detail' => 'reply watcher stub — connect IMAP or inbound webhook + store thread id in storage/evolution/outreach/',
        ];
    }
}
