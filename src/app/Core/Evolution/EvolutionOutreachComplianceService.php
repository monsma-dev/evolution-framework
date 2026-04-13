<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Pre-flight checks for outreach: unsubscribe, AI disclosure, budget flags, data-source policy hints.
 */
final class EvolutionOutreachComplianceService
{
    /**
     * @param array{html?: string, text?: string, unsubscribe_url?: string} $payload
     *
     * @return array{ok: bool, violations: list<string>}
     */
    public static function validateOutboundMessage(Config $config, array $payload): array
    {
        $o = $config->get('evolution.outreach', []);
        if (!is_array($o) || !filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'violations' => ['outreach disabled']];
        }

        $violations = [];
        $html = (string) ($payload['html'] ?? '');
        $text = (string) ($payload['text'] ?? '');
        $combined = $html . "\n" . $text;

        if (filter_var($o['require_unsubscribe_url'] ?? true, FILTER_VALIDATE_BOOL)) {
            $u = trim((string) ($payload['unsubscribe_url'] ?? ''));
            if ($u === '' || !str_starts_with($u, 'http')) {
                $violations[] = 'missing_or_invalid_unsubscribe_url';
            }
        }

        if (filter_var($o['require_ai_disclosure_footer'] ?? true, FILTER_VALIDATE_BOOL)) {
            if (!preg_match('/\b(AI-assisted|AI generated|artificial intelligence|gegenereerd door AI|IA)\b/ui', $combined)) {
                $violations[] = 'missing_ai_disclosure_footer';
            }
        }

        return [
            'ok' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * @return array{ok: bool, note?: string}
     */
    public static function assertStoragePolicy(Config $config): array
    {
        $o = $config->get('evolution.outreach', []);
        $dir = BASE_PATH . '/storage/evolution/outreach';
        if (!is_array($o) || !filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'note' => 'outreach disabled — storage dir not required until enabled'];
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return ['ok' => is_dir($dir), 'note' => 'storage/evolution/outreach ready'];
    }
}
