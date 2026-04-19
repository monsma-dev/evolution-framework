<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Ultra-concise display strings for Virtual Room bubbles (no LLM — local trim only).
 * Keeps code-like segments mostly intact.
 */
final class CavemanMessageFilter
{
    /**
     * @var list<string>
     */
    private const FILLER_WORDS = [
        'hallo', 'hoi', 'hey', 'please', 'thanks', 'thank you', 'bedankt', 'graag', 'alsjeblieft',
        'natuurlijk', 'sorry', 'ik hoop', 'u bent', 'je bent', 'even', 'gewoon', 'eigenlijk',
        'natuurlijk', 'vriendelijke', 'vriendelijk', 'dear', 'could you', 'would you',
    ];

    public static function lite(string $text): string
    {
        $t = trim($text);
        if ($t === '') {
            return '…';
        }

        if (preg_match('/[`{}\[\]$\\\\]|```|class\s|function\s|SELECT\s|INSERT\s/i', $t) === 1) {
            return self::truncate($t, 140);
        }

        $lower = mb_strtolower($t);
        foreach (self::FILLER_WORDS as $w) {
            $lower = preg_replace('/\b' . preg_quote($w, '/') . '\b/ui', '', $lower) ?? $lower;
        }

        $t = preg_replace('/\s+/u', ' ', $lower) ?? $lower;
        $t = trim((string)$t, " \t\n\r\0\x0B.,;:!?");

        return self::truncate($t !== '' ? $t : '…', 120);
    }

    private static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, max(1, $max - 1)) . '…';
    }
}
