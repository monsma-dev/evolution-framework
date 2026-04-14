<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Monthly-style trim of learning_history + blueprint kladblok to keep prompts lean (forgotten / stale lines).
 */
final class SelfPruningMetadataService
{
    /**
     * @return array{ok: bool, learning_trimmed?: int, blueprint_trimmed?: bool, error?: string}
     */
    public static function prune(Config $config): array
    {
        $sp = $config->get('evolution.metadata_prune', []);
        if (!is_array($sp) || !filter_var($sp['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'metadata_prune disabled'];
        }

        $maxLearn = max(100, min(5000, (int) ($sp['learning_history_max_lines'] ?? 450)));
        $learningPath = BASE_PATH . '/storage/evolution/learning_history.jsonl';

        $trimmed = 0;
        if (is_file($learningPath)) {
            $lines = @file($learningPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines) && count($lines) > $maxLearn) {
                $keep = array_slice($lines, -$maxLearn);
                @file_put_contents($learningPath, implode("\n", $keep) . "\n", LOCK_EX);
                $trimmed = count($lines) - count($keep);
            }
        }

        $bpPath = BASE_PATH . '/storage/evolution/blueprint_notes.json';
        $blueprintTrimmed = false;
        if (is_file($bpPath)) {
            $raw = @file_get_contents($bpPath);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($j)) {
                $maxNotes = max(20, min(300, (int) ($sp['blueprint_notes_cap'] ?? 100)));
                $maxLessons = max(10, min(200, (int) ($sp['lessons_cap'] ?? 60)));
                $notes = $j['notes'] ?? [];
                $lessons = $j['lessons_from_master'] ?? [];
                if (is_array($notes) && count($notes) > $maxNotes) {
                    $j['notes'] = array_slice($notes, -$maxNotes);
                    $blueprintTrimmed = true;
                }
                if (is_array($lessons) && count($lessons) > $maxLessons) {
                    $j['lessons_from_master'] = array_slice($lessons, -$maxLessons);
                    $blueprintTrimmed = true;
                }
                if ($blueprintTrimmed) {
                    $j['pruned_at'] = gmdate('c');
                    @file_put_contents($bpPath, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
                }
            }
        }

        EvolutionLogger::log('metadata_prune', 'run', ['learning_trimmed' => $trimmed, 'blueprint' => $blueprintTrimmed]);

        return [
            'ok' => true,
            'learning_trimmed' => $trimmed,
            'blueprint_trimmed' => $blueprintTrimmed,
        ];
    }
}
