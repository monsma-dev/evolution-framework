<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

/**
 * Loads Director Sync artefacts: essence markdown + DirectorFeedback.skill JSON.
 */
final class DirectorSyncContext
{
    private const ESSENCE_REL = 'storage/evolution/mind_dump/director_essence.md';

    private const SKILL_REL = 'app/Core/Evolution/Skills/DirectorFeedback.skill';

    public function __construct(private readonly string $basePath)
    {
    }

    public function essencePath(): string
    {
        return rtrim($this->basePath, '/\\') . '/' . self::ESSENCE_REL;
    }

    public function skillPath(): string
    {
        return rtrim($this->basePath, '/\\') . '/' . self::SKILL_REL;
    }

    /** Plaintext for LLM system injection (bounded). */
    public function loadEssenceText(int $maxChars = 12000): string
    {
        $p = $this->essencePath();
        if (!is_readable($p)) {
            return '';
        }
        $t = trim((string) file_get_contents($p));

        return $maxChars > 0 && strlen($t) > $maxChars ? substr($t, 0, $maxChars) . "\n…" : $t;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadSkillJson(): ?array
    {
        $p = $this->skillPath();
        if (!is_readable($p)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($p), true);

        return is_array($j) ? $j : null;
    }

    /** Short line list of DR principles for prompts. */
    public function principlesSummary(): string
    {
        $j = $this->loadSkillJson();
        if ($j === null) {
            return '';
        }
        $principles = (array) ($j['core_principles'] ?? []);
        $lines      = [];
        foreach ($principles as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id   = (string) ($p['id'] ?? '');
            $prin = (string) ($p['principle'] ?? '');
            if ($id !== '' && $prin !== '') {
                $lines[] = "{$id}: {$prin}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Prefix appended to every system prompt in {@see \App\Domain\AI\LlmClient}.
     */
    public static function globalSystemSuffix(): string
    {
        return "\n\nDirector-sync: Unless specifically asked for detail, speak like a Caveman to the Director. No fluff, only data and action. (Tenzij de Director expliciet detail vraagt: geen vulling — alleen feiten en actie.)";
    }

    /**
     * @param array<string, mixed> $skill
     */
    public function heuristicDirectorHold(array $proposal, array $scores, array $skill): bool
    {
        $side = strtoupper((string) ($proposal['side'] ?? ''));
        $sent = (float) ($proposal['sentiment'] ?? 0.0);
        $trend = (float) ($scores['trend_prediction'] ?? 0.0);

        // DR-03: geen agressieve koop tegen zwaar negatief sentiment (yield-focus)
        if ($side === 'BUY' && $sent < -0.35 && $trend < 0.15) {
            return true;
        }

        return false;
    }

    /**
     * Append a line to director feedback queue (human-in-loop via ai_bridge --read).
     *
     * @param array<string, mixed> $payload
     */
    public function appendFeedbackQueue(string $kind, array $payload): void
    {
        $dir = rtrim($this->basePath, '/\\') . '/storage/evolution/mind_dump';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = json_encode([
            'ts'   => gmdate('c'),
            'kind' => $kind,
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($dir . '/director_feedback_queue.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
