<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * AI kladblok: architecturale ontdekkingen tussen sessies (blueprint_notes.json).
 */
final class EvolutionNotepadService
{
    private const FILE = 'storage/evolution/blueprint_notes.json';

    /**
     * @param list<string> $lines
     */
    public static function appendNotes(Config $config, array $lines): int
    {
        $bp = $config->get('evolution.blueprint', []);
        if (!is_array($bp) || !filter_var($bp['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return 0;
        }
        $max = max(10, min(500, (int) ($bp['max_notes'] ?? 120)));
        $path = self::path();
        $data = self::read($path);
        $notes = $data['notes'] ?? [];
        if (!is_array($notes)) {
            $notes = [];
        }
        $added = 0;
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $line = mb_substr($line, 0, 2000);
            $notes[] = [
                'ts' => gmdate('c'),
                'text' => $line,
            ];
            $added++;
        }
        if (count($notes) > $max) {
            $notes = array_slice($notes, -$max);
        }
        $data['notes'] = $notes;
        $data['updated_at'] = gmdate('c');
        self::write($path, $data);

        return $added;
    }

    /**
     * @param list<string> $lines
     */
    public static function appendLessonsFromMaster(Config $config, array $lines): int
    {
        $bp = $config->get('evolution.blueprint', []);
        if (!is_array($bp) || !filter_var($bp['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return 0;
        }
        $max = max(10, min(200, (int) ($bp['max_lessons_from_master'] ?? 80)));
        $path = self::path();
        $data = self::read($path);
        $lessons = $data['lessons_from_master'] ?? [];
        if (!is_array($lessons)) {
            $lessons = [];
        }
        $added = 0;
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $line = mb_substr($line, 0, 2000);
            $lessons[] = [
                'ts' => gmdate('c'),
                'text' => $line,
            ];
            $added++;
        }
        if (count($lessons) > $max) {
            $lessons = array_slice($lessons, -$max);
        }
        $data['lessons_from_master'] = $lessons;
        $data['updated_at'] = gmdate('c');
        self::write($path, $data);
        self::appendHallOfWisdomJsonl($lines);

        return $added;
    }

    /**
     * Automatische Critical Notes voor zware assets (blueprint-scan).
     *
     * @param list<array{path: string, bytes: int}> $fatAssets
     */
    public static function appendFatAssetCriticalNotes(Config $config, array $fatAssets): void
    {
        if ($fatAssets === []) {
            return;
        }
        $lines = [];
        foreach ($fatAssets as $row) {
            $p = (string) ($row['path'] ?? '');
            $b = (int) ($row['bytes'] ?? 0);
            if ($p === '') {
                continue;
            }
            $kb = max(1, (int) round($b / 1024));
            $lines[] = "[FAT_ASSET_CRITICAL] {$p} is te groot ({$kb} KB). Overweeg opsplitsing, tree-shaking of verwijder ongebruikte selectors — zie CURRENT_CODEBASE_BLUEPRINT.";
        }
        self::appendNotes($config, $lines);
    }

    public static function promptSection(Config $config): string
    {
        $bp = $config->get('evolution.blueprint', []);
        if (!is_array($bp) || !filter_var($bp['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $path = self::path();
        if (!is_file($path)) {
            return '';
        }
        $data = self::read($path);
        $notes = $data['notes'] ?? [];
        if (!is_array($notes) || $notes === []) {
            $lines = ["\n\nARCHITECTURAL_NOTES: (nog leeg — voeg ontdekkingen toe via raw_json.update_blueprint_notes [strings].)"];
        } else {
            $max = max(5, min(80, (int) ($bp['max_notes_in_prompt'] ?? 40)));
            $tail = array_slice($notes, -$max);
            $lines = ["\n\nARCHITECTURAL_NOTES (AI-kladblok — gebruik dit om kritieke verbindingen en verboden patronen tussen sessies vast te houden):"];
            foreach ($tail as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $t = trim((string) ($n['text'] ?? ''));
                if ($t === '') {
                    continue;
                }
                $lines[] = '  - ' . $t;
            }
            $lines[] = 'INSTRUCTIE: Noteer nieuwe risico\'s (cross-namespace, zware assets, centrale hubs) via "update_blueprint_notes" in je JSON-antwoord.';
        }

        $lessons = $data['lessons_from_master'] ?? [];
        if (is_array($lessons) && $lessons !== []) {
            $maxL = max(3, min(40, (int) ($bp['max_lessons_in_prompt'] ?? 15)));
            $tailL = array_slice($lessons, -$maxL);
            $lines[] = '';
            $lines[] = 'LESSONS_FROM_MASTER (langetermijn aforismen — gebruik dit om fouten niet te herhalen):';
            foreach ($tailL as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $t = trim((string) ($n['text'] ?? ''));
                if ($t === '') {
                    continue;
                }
                $lines[] = '  • ' . $t;
            }
            $lines[] = 'Voeg lessen toe via "update_lessons_from_master": [strings] in raw_json (bijv. na een Master-afwijzing).';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{notes: list<array{ts: string, text: string}>, updated_at?: string}
     */
    private static function read(string $path): array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['notes' => []];
        }
        $j = json_decode($raw, true);

        return is_array($j) ? $j : ['notes' => []];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            @file_put_contents($path, $json, LOCK_EX);
        }
    }

    /**
     * @param list<string> $lines
     */
    private static function appendHallOfWisdomJsonl(array $lines): void
    {
        if (!defined('BASE_PATH') || $lines === []) {
            return;
        }
        $path = BASE_PATH . '/data/evolution/hall_of_wisdom.jsonl';
        $buf = '';
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $buf .= json_encode(['ts' => gmdate('c'), 'kind' => 'lesson', 'text' => $line], JSON_UNESCAPED_UNICODE) . "\n";
        }
        if ($buf !== '') {
            @file_put_contents($path, $buf, FILE_APPEND | LOCK_EX);
        }
    }

    private static function path(): string
    {
        return BASE_PATH . '/' . self::FILE;
    }
}
