<?php

declare(strict_types=1);

namespace App\Core\Evolution\Growth;

/**
 * ReputationGuard — The Arbiter that filters outreach messages.
 *
 * Rejects messages that contain sales-talk, false urgency, or misleading claims.
 * Only messages with a technical-value score above threshold pass.
 *
 * Result: ['ok'=>bool, 'score'=>int, 'issues'=>[string], 'verdict'=>string]
 */
final class ReputationGuard
{
    private const MIN_SCORE        = 60;
    private const MAX_MSG_LENGTH   = 800;
    private const MIN_MSG_LENGTH   = 80;

    private const SPAM_PATTERNS = [
        'limited time offer'  => ['score' => -40, 'label' => 'fake urgency'],
        'act now'             => ['score' => -30, 'label' => 'fake urgency'],
        'don\'t miss out'     => ['score' => -30, 'label' => 'fake urgency'],
        'exclusive deal'      => ['score' => -35, 'label' => 'sales talk'],
        'buy now'             => ['score' => -40, 'label' => 'hard sell'],
        'click here'          => ['score' => -20, 'label' => 'spam signal'],
        'guaranteed'          => ['score' => -20, 'label' => 'overclaim'],
        'earn money'          => ['score' => -35, 'label' => 'financial spam'],
        'make money'          => ['score' => -35, 'label' => 'financial spam'],
        'free trial'          => ['score' => -10, 'label' => 'mild sales'],
        '100% free'           => ['score' => -15, 'label' => 'overclaim'],
        'no risk'             => ['score' => -20, 'label' => 'overclaim'],
        'best ai'             => ['score' => -15, 'label' => 'overclaim'],
        '#1'                  => ['score' => -10, 'label' => 'overclaim'],
        'revolutionary'       => ['score' => -10, 'label' => 'hype'],
        'game changer'        => ['score' => -10, 'label' => 'hype'],
        'amazing'             => ['score' => -5,  'label' => 'hype'],
        'incredible'          => ['score' => -5,  'label' => 'hype'],
        '!!!'                 => ['score' => -15, 'label' => 'spam signal'],
    ];

    private const QUALITY_SIGNALS = [
        'open-source'     => 15,
        'open source'     => 15,
        'self-hosted'     => 12,
        'technical'       => 8,
        'php'             => 5,
        'framework'       => 5,
        'local'           => 8,
        'cost'            => 8,
        'benchmark'       => 10,
        'example'         => 8,
        'here\'s how'     => 12,
        'i built'         => 10,
        'solved'          => 10,
        'same problem'    => 12,
        'no sales'        => 15,
        'no pitch'        => 15,
        'worth a look'    => 5,
        'open to feedback'=> 10,
    ];

    /**
     * Validate a proposal message.
     * Returns ['ok'=>bool, 'score'=>int, 'issues'=>[], 'verdict'=>string]
     */
    public function validate(array $proposal): array
    {
        $message = $proposal['message'] ?? '';
        $issues  = [];
        $score   = 50; // baseline

        // Length checks
        if (strlen($message) > self::MAX_MSG_LENGTH) {
            $score  -= 15;
            $issues[] = 'Message too long (' . strlen($message) . ' chars, max ' . self::MAX_MSG_LENGTH . ')';
        }
        if (strlen($message) < self::MIN_MSG_LENGTH) {
            $score  -= 20;
            $issues[] = 'Message too short — no real value communicated';
        }

        $lower = strtolower($message);

        // Spam signals
        foreach (self::SPAM_PATTERNS as $pattern => $data) {
            if (str_contains($lower, $pattern)) {
                $score    += $data['score'];
                $issues[]  = 'Spam signal: "' . $pattern . '" (' . $data['label'] . ')';
            }
        }

        // Quality signals
        foreach (self::QUALITY_SIGNALS as $signal => $points) {
            if (str_contains($lower, $signal)) {
                $score += $points;
            }
        }

        // Must mention concrete value (saving / speed / cost)
        if (!preg_match('/(\$\d+|\d+x|\d+%|ms|cost|saving|faster|cheaper)/i', $message)) {
            $score  -= 10;
            $issues[] = 'No concrete value metric ($ amount, %, or speed)';
        }

        // Must not sound automated
        if (preg_match('/\bhi\s+there\b|\bdear\s+user\b|\bto\s+whom\b/i', $message)) {
            $score  -= 20;
            $issues[] = 'Generic greeting — sounds automated';
        }

        // URL check — must have a link but not too many
        $urlCount = preg_match_all('/https?:\/\//', $message);
        if ($urlCount === 0) {
            $score  -= 10;
            $issues[] = 'No link — hard to follow up';
        }
        if ($urlCount > 2) {
            $score  -= 15;
            $issues[] = 'Too many links — looks spammy';
        }

        $score  = max(0, min(100, $score));
        $ok     = $score >= self::MIN_SCORE && empty(array_filter($issues, fn($i) => str_contains($i, 'Spam signal')));
        $verdict = $ok
            ? 'APPROVED — message cleared for outreach'
            : 'REJECTED — ' . ($issues[0] ?? 'quality threshold not met');

        return [
            'ok'      => $ok,
            'score'   => $score,
            'issues'  => $issues,
            'verdict' => $verdict,
        ];
    }

    /** Validate all draft proposals, return only the approved ones. */
    public function filterDrafts(array $proposals): array
    {
        $approved = [];
        foreach ($proposals as $proposal) {
            $result = $this->validate($proposal);
            if ($result['ok']) {
                $proposal['guard_score']   = $result['score'];
                $proposal['guard_verdict'] = $result['verdict'];
                $approved[] = $proposal;
            }
        }
        return $approved;
    }
}
