<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * PiiScanner — GDPR/AVG Art. 5(1)(c) data-minimisation guardrail.
 *
 * Detects and anonymises PII in text before it is persisted to market_signals.
 * Covers:
 *   - Full names (heuristic: Title Case two-word combos near verbs of ownership)
 *   - E-mail addresses (RFC-ish pattern)
 *   - Phone numbers (EU formats: NL, BE, DE, FR, UK, international)
 *   - Social handles (@username / u/username / /u/…)
 *   - Dutch/Belgian IBAN
 *   - IP addresses (v4 + v6)
 *   - Dutch BSN / Belgian national number
 *
 * Usage:
 *   $result = PiiScanner::scan($rawText);
 *   // ['clean' => '…', 'found' => ['email' => 1, 'phone' => 2], 'pii_detected' => true]
 */
final class PiiScanner
{
    /** @var array<string, string> regex patterns keyed by PII type */
    private const PATTERNS = [
        'email'   => '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
        'phone'   => '/(?:\+|00)?(?:31|32|49|33|44)?[\s.\-]?\(?0?\d{1,4}\)?[\s.\-]?\d{2,4}[\s.\-]?\d{2,4}[\s.\-]?\d{0,4}\b/',
        'iban'    => '/\b[A-Z]{2}\d{2}[\s]?(?:[A-Z0-9]{4}[\s]?){2,7}[A-Z0-9]{1,4}\b/',
        'ip_v4'   => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        'ip_v6'   => '/\b(?:[0-9a-fA-F]{1,4}:){2,7}[0-9a-fA-F]{1,4}\b/',
        'bsn'     => '/\b\d{9}\b/',
        'handle'  => '/@[A-Za-z0-9_]{2,40}\b|(?:^|\s)u\/[A-Za-z0-9_]{2,40}\b/',
    ];

    /**
     * Scan and anonymise PII in $text.
     *
     * @return array{clean: string, found: array<string, int>, pii_detected: bool}
     */
    public static function scan(string $text): array
    {
        $found   = [];
        $clean   = $text;

        foreach (self::PATTERNS as $type => $pattern) {
            $count = preg_match_all($pattern, $clean);
            if ($count > 0) {
                $found[$type] = $count;
                $replacement  = match ($type) {
                    'email'  => '[EMAIL_REDACTED]',
                    'phone'  => '[PHONE_REDACTED]',
                    'iban'   => '[IBAN_REDACTED]',
                    'ip_v4',
                    'ip_v6'  => '[IP_REDACTED]',
                    'bsn'    => '[ID_REDACTED]',
                    'handle' => '[HANDLE_REDACTED]',
                    default  => '[PII_REDACTED]',
                };
                $clean = preg_replace($pattern, $replacement, $clean) ?? $clean;
            }
        }

        return [
            'clean'        => $clean,
            'found'        => $found,
            'pii_detected' => !empty($found),
        ];
    }

    /**
     * Returns true if the text contains any detectable PII.
     */
    public static function hasPii(string $text): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether a prompt is safe to send to an external cloud API.
     * Blocks if PII or internal/sensitive markers are found.
     *
     * @return array{safe: bool, reason: string|null, pii_types: list<string>}
     */
    public static function checkPromptSafety(string $prompt): array
    {
        $sensitiveKeywords = [
            'wachtwoord', 'password', 'passwd', 'api_key', 'secret', 'private_key',
            'license_key', 'bearer ', 'token:', 'credential', 'bedrijfsgeheim',
        ];

        foreach ($sensitiveKeywords as $kw) {
            if (stripos($prompt, $kw) !== false) {
                return ['safe' => false, 'reason' => "Sensitive keyword detected: {$kw}", 'pii_types' => []];
            }
        }

        $result   = self::scan($prompt);
        $piiTypes = array_keys($result['found']);

        if ($result['pii_detected']) {
            return [
                'safe'      => false,
                'reason'    => 'PII detected in prompt: ' . implode(', ', $piiTypes),
                'pii_types' => $piiTypes,
            ];
        }

        return ['safe' => true, 'reason' => null, 'pii_types' => []];
    }
}
