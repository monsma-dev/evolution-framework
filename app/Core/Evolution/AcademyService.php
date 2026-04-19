<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Evolution Academy — "Lessen aan de Master"
 *
 * De Architect vraagt hier lessen aan wanneer hij vastzat of merkt dat
 * zijn aanpak inefficiënt was. De Master (premium AI of beheerder) beantwoordt
 * de vragen. Antwoorden worden automatisch verwerkt in:
 *   - SYSTEM_MAP.md  (Living Wiki)
 *   - storage/evolution/academy_prompt_snippet.txt  (injecteerbaar in system prompts)
 *
 * Gebruik vanuit ArchitectChatService:
 *   AcademyService::requestLesson($db, $taskSummary, $question, $contextSnippet, $curiosityScore);
 *
 * Gebruik vanuit AcademyController (admin antwoord):
 *   AcademyService::answerLesson($db, $id, $answer, $model);
 */
final class AcademyService
{
    private const SNIPPET_FILE   = 'storage/evolution/academy_prompt_snippet.txt';
    private const SYSTEM_MAP     = 'docs/SYSTEM_MAP.md';
    private const MAX_PROMPT_LESSONS = 5;   // hoeveel lessen in prompt-snippet

    // ── Public API ──────────────────────────────────────────────────────────────

    /**
     * Agent vraagt een les aan (curiosity trigger).
     *
     * @return int  inserted lesson ID (0 on failure)
     */
    public static function requestLesson(
        \PDO   $db,
        string $taskSummary,
        string $question,
        string $contextSnippet = '',
        float  $curiosityScore = 0.7,
        string $sourceMode = 'core',
    ): int {
        if (trim($question) === '' || trim($taskSummary) === '') {
            return 0;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO academy_lessons
                    (task_summary, question, context_snippet, curiosity_score, source_mode)
                VALUES (:task, :q, :ctx, :score, :mode)
            ");
            $stmt->execute([
                ':task'  => mb_substr($taskSummary, 0, 1000),
                ':q'     => mb_substr($question, 0, 2000),
                ':ctx'   => mb_substr($contextSnippet, 0, 3000),
                ':score' => round(max(0.0, min(1.0, $curiosityScore)), 3),
                ':mode'  => $sourceMode,
            ]);
            $id = (int)$db->lastInsertId();

            EvolutionLogger::log('academy', 'lesson_requested', [
                'id'       => $id,
                'question' => mb_substr($question, 0, 100),
                'score'    => $curiosityScore,
            ]);

            return $id;
        } catch (\Exception $e) {
            EvolutionLogger::log('academy', 'request_error', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Master beantwoordt een les + triggert self-improvement loop.
     */
    public static function answerLesson(\PDO $db, int $id, string $answer, string $model = 'master'): bool
    {
        if (trim($answer) === '' || $id <= 0) {
            return false;
        }

        try {
            // 1. Sla antwoord op
            $stmt = $db->prepare("
                UPDATE academy_lessons
                SET status = 'answered',
                    answer = :answer,
                    answer_model = :model,
                    answered_at = NOW()
                WHERE id = :id AND status = 'pending'
            ");
            $stmt->execute([':answer' => $answer, ':model' => $model, ':id' => $id]);

            if ($stmt->rowCount() === 0) {
                return false;
            }

            // 2. Haal les op voor self-improvement
            $lesson = self::getById($db, $id);
            if ($lesson === null) {
                return false;
            }

            // 3. Zelfverbetering: schrijf naar prompt snippet + system map
            self::applyToPromptSnippet($db, $id, $lesson, $answer);
            self::applyToSystemMap($db, $id, $lesson, $answer);

            EvolutionLogger::log('academy', 'lesson_answered', [
                'id'    => $id,
                'model' => $model,
            ]);

            return true;
        } catch (\Exception $e) {
            EvolutionLogger::log('academy', 'answer_error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Laad beantwoorde lessen als injecteerbare prompt-tekst.
     * Wordt door ArchitectChatService in het system prompt gestopt.
     */
    public static function loadPromptSnippet(): string
    {
        $path = self::basePath() . '/' . self::SNIPPET_FILE;
        if (!is_file($path)) {
            return '';
        }
        $content = trim((string)@file_get_contents($path));
        return $content !== '' ? "\n\n--- ACADEMY LESSEN (permanent geheugen) ---\n{$content}\n---" : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getPendingLessons(\PDO $db): array
    {
        try {
            return $db->query("
                SELECT id, task_summary, question, context_snippet,
                       curiosity_score, source_mode, created_at
                FROM academy_lessons
                WHERE status = 'pending'
                ORDER BY curiosity_score DESC, created_at ASC
                LIMIT 20
            ")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getAllLessons(\PDO $db, int $limit = 50): array
    {
        try {
            return $db->query("
                SELECT id, task_summary, question, answer, answer_model,
                       curiosity_score, status, source_mode, created_at, answered_at,
                       applied_to_system_map, applied_to_system_prompt
                FROM academy_lessons
                ORDER BY created_at DESC
                LIMIT {$limit}
            ")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getAnsweredLessons(\PDO $db, int $limit = 20): array
    {
        try {
            return $db->query("
                SELECT id, task_summary, question, answer, answer_model,
                       curiosity_score, source_mode, created_at, answered_at
                FROM academy_lessons
                WHERE status = 'answered'
                ORDER BY answered_at DESC
                LIMIT {$limit}
            ")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    // ── Self-improvement loop ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $lesson
     */
    private static function applyToPromptSnippet(\PDO $db, int $id, array $lesson, string $answer): void
    {
        $base = self::basePath();
        $dir  = $base . '/data/evolution';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

        // Bouw snippet op van de laatste MAX_PROMPT_LESSONS beantwoorde lessen
        try {
            $rows = $db->query("
                SELECT question, answer, answered_at
                FROM academy_lessons
                WHERE status = 'answered' AND answer IS NOT NULL
                ORDER BY answered_at DESC
                LIMIT " . self::MAX_PROMPT_LESSONS
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            $rows = [];
        }

        $lines = [];
        foreach ($rows as $row) {
            $date = isset($row['answered_at']) ? substr((string)$row['answered_at'], 0, 10) : '?';
            $lines[] = "Q [{$date}]: " . mb_substr((string)$row['question'], 0, 200);
            $lines[] = "A: " . mb_substr((string)$row['answer'], 0, 400);
            $lines[] = '';
        }

        @file_put_contents($base . '/' . self::SNIPPET_FILE, implode("\n", $lines));

        // Markeer als verwerkt
        try {
            $db->prepare("UPDATE academy_lessons SET applied_to_system_prompt = 1 WHERE id = :id")
               ->execute([':id' => $id]);
        } catch (\Exception $e) {}
    }

    /**
     * @param array<string, mixed> $lesson
     */
    private static function applyToSystemMap(\PDO $db, int $id, array $lesson, string $answer): void
    {
        $mapPath = self::basePath() . '/' . self::SYSTEM_MAP;
        if (!is_file($mapPath)) {
            return;
        }

        $content = (string)file_get_contents($mapPath);
        $date    = date('Y-m-d');
        $section = "## Academy Les #{$id} — {$date}\n\n"
                 . "**Vraag:** " . mb_substr((string)($lesson['question'] ?? ''), 0, 300) . "\n\n"
                 . "**Taak-context:** " . mb_substr((string)($lesson['task_summary'] ?? ''), 0, 200) . "\n\n"
                 . "**Antwoord (Master):** " . mb_substr($answer, 0, 800) . "\n\n"
                 . "_Model: " . ($lesson['answer_model'] ?? 'master') . " | Verwerkt: {$date}_\n\n---\n";

        // Voeg toe na de eerste ---  (onder het hoofd-overzicht)
        $marker = "---\n\n## Machine Overzicht";
        if (str_contains($content, "## Academy Lessen")) {
            // Voeg toe aan bestaande sectie
            $content = str_replace("## Academy Lessen\n\n", "## Academy Lessen\n\n" . $section, $content);
        } else {
            // Maak nieuwe Academy sectie aan na eerste ---
            $content = str_replace(
                $marker,
                "---\n\n## Academy Lessen\n\n{$section}\n## Machine Overzicht",
                $content
            );
        }

        @file_put_contents($mapPath, $content);

        try {
            $db->prepare("UPDATE academy_lessons SET applied_to_system_map = 1 WHERE id = :id")
               ->execute([':id' => $id]);
        } catch (\Exception $e) {}
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getById(\PDO $db, int $id): ?array
    {
        try {
            $stmt = $db->prepare("SELECT * FROM academy_lessons WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function basePath(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
    }
}
