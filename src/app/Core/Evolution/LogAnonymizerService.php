<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Redacts emails, IPv4, and card-like digit runs before log lines enter LLM context.
 */
final class LogAnonymizerService
{
    public static function isEnabled(Config $config): bool
    {
        $evo = $config->get('evolution', []);
        $la = is_array($evo) ? ($evo['log_anonymizer'] ?? []) : [];

        return is_array($la) && filter_var($la['enabled'] ?? true, FILTER_VALIDATE_BOOL);
    }

    /**
     * Without Config, applies full redaction (safe default for ad-hoc use).
     */
    public static function scrub(string $text, ?Config $config = null): string
    {
        if ($config !== null && !self::isEnabled($config)) {
            return $text;
        }

        $evo = $config?->get('evolution', []);
        $la = is_array($evo) ? ($evo['log_anonymizer'] ?? []) : [];
        $stripEmail = $config === null ? true : filter_var($la['strip_emails'] ?? true, FILTER_VALIDATE_BOOL);
        $stripIp = $config === null ? true : filter_var($la['strip_ipv4'] ?? true, FILTER_VALIDATE_BOOL);
        $maskCc = $config === null ? true : filter_var($la['mask_credit_card_like'] ?? true, FILTER_VALIDATE_BOOL);

        $out = $text;
        if ($stripEmail) {
            $out = (string)preg_replace(
                '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
                '[email]',
                $out
            );
        }
        if ($stripIp) {
            $out = (string)preg_replace(
                '/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/',
                '[ip]',
                $out
            );
        }
        if ($maskCc) {
            $out = (string)preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[redacted]', $out);
        }

        return $out;
    }
}
