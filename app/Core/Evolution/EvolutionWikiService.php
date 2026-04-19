<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Auto-append human-readable evolution notes to docs/EVOLUTION.md from IntentLog entries.
 */
final class EvolutionWikiService
{
    private const DEFAULT_DOC = 'docs/EVOLUTION.md';

    /**
     * @param array<string, mixed> $intentRow full IntentLog row { ts, kind, payload }
     */
    public static function appendFromIntent(Config $config, array $intentRow): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string)($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            $header = "# Evolution wiki (auto-generated)\n\n";
            $header .= "Dit document wordt aangevuld wanneer de Architect een goedgekeurd think-step plan vastlegt.\n\n---\n\n";
            @file_put_contents($path, $header);
        }

        $ts = (string)($intentRow['ts'] ?? gmdate('c'));
        $kind = (string)($intentRow['kind'] ?? 'intent');
        $payload = $intentRow['payload'] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }

        $plan = $payload['strategy_plan'] ?? null;
        $summary = trim((string)($payload['phase1_summary'] ?? ''));
        $peer = $payload['peer_review'] ?? null;

        $lines = ["## {$ts} — {$kind}", ''];
        if ($summary !== '') {
            $lines[] = '**Samenvatting:** ' . $summary;
            $lines[] = '';
        }
        if (is_array($plan)) {
            $steps = $plan['steps'] ?? [];
            if (is_array($steps) && $steps !== []) {
                $lines[] = '**Stappen (strategy_plan):**';
                foreach (array_slice($steps, 0, 12) as $st) {
                    if (!is_array($st)) {
                        continue;
                    }
                    $t = (string)($st['target_fqcn'] ?? $st['target'] ?? '?');
                    $k = (string)($st['change_kind'] ?? '');
                    $r = (string)($st['rationale'] ?? '');
                    $lines[] = '- ' . $t . ($k !== '' ? " ({$k})" : '') . ($r !== '' ? ': ' . $r : '');
                }
                $lines[] = '';
            }
        }
        if (is_array($peer)) {
            $lines[] = '**Peer review:** ' . (filter_var($peer['approved'] ?? false, FILTER_VALIDATE_BOOL) ? 'goedgekeurd' : 'afgewezen');
            if (!empty($peer['summary'])) {
                $lines[] = '> ' . trim((string)$peer['summary']);
            }
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';

        @file_put_contents($path, implode("\n", $lines), FILE_APPEND | LOCK_EX);
    }

    /**
     * Append a consensus / courtroom entry under "## Architectural Decisions" in the configured wiki markdown.
     *
     * @param array<string, mixed> $payload
     */
    public static function appendArchitecturalDecision(Config $config, string $title, array $payload): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string) ($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            $header = "# Evolution wiki (auto-generated)\n\n";
            @file_put_contents($path, $header);
        }

        $raw = @file_get_contents($path);
        $body = is_string($raw) ? $raw : '';
        $sectionTitle = '## Architectural Decisions';
        if (!str_contains($body, $sectionTitle)) {
            $body = rtrim($body) . "\n\n" . $sectionTitle . "\n\n";
        }

        $ts = gmdate('c');
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $block = "### {$title}\n\n- **Time (UTC):** {$ts}\n\n```json\n"
            . ($json !== false ? $json : '{}')
            . "\n```\n\n---\n\n";

        @file_put_contents($path, rtrim($body) . "\n\n" . $block, LOCK_EX);
    }

    /**
     * Cognitive Study Mode — deep scans & improvement backlog for the night crew (Squire + wiki).
     *
     * @param list<string> $improvement_bullets
     */
    public static function appendCognitiveStudyLog(Config $config, string $title, string $summary, array $improvement_bullets = []): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string) ($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            @file_put_contents($path, "# Evolution wiki (auto-generated)\n\n");
        }

        $raw = @file_get_contents($path);
        $body = is_string($raw) ? $raw : '';
        $section = '## Cognitive Study Log';
        if (!str_contains($body, $section)) {
            $body = rtrim($body) . "\n\n" . $section . "\n\n";
        }

        $ts = gmdate('c');
        $lines = ["### {$title}", '', '- **Time (UTC):** ' . $ts, ''];
        if ($summary !== '') {
            $lines[] = $summary;
            $lines[] = '';
        }
        if ($improvement_bullets !== []) {
            $lines[] = '**Improvement points (next night shift):**';
            foreach (array_slice($improvement_bullets, 0, 40) as $b) {
                $lines[] = '- ' . trim((string) $b);
            }
            $lines[] = '';
        }
        $lines[] = '---';
        $lines[] = '';

        @file_put_contents($path, rtrim($body) . "\n\n" . implode("\n", $lines), LOCK_EX);
    }

    /**
     * Zen mode — aesthetic / sandbox harmonization line for the wiki.
     */
    public static function appendZenHarmonyLine(Config $config, string $note = ''): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string) ($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            @file_put_contents($path, "# Evolution wiki (auto-generated)\n\n");
        }

        $raw = @file_get_contents($path);
        $body = is_string($raw) ? $raw : '';
        $section = '## Evolution Zen';
        if (!str_contains($body, $section)) {
            $body = rtrim($body) . "\n\n" . $section . "\n\n";
        }

        $ts = gmdate('c');
        $text = $note !== '' ? $note : 'Framework is in Zen-state: Harmonizing DNA.';
        $block = "### {$ts}\n\n> {$text}\n\n---\n\n";

        @file_put_contents($path, rtrim($body) . "\n\n" . $block, LOCK_EX);
    }

    /**
     * Squire: documentation / index readiness so agents are up-to-speed after sundown.
     */
    public static function appendSquireReadiness(Config $config, string $summaryMarkdown): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string) ($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            @file_put_contents($path, "# Evolution wiki (auto-generated)\n\n");
        }

        $raw = @file_get_contents($path);
        $body = is_string($raw) ? $raw : '';
        $section = '## Squire — documentation readiness';
        if (!str_contains($body, $section)) {
            $body = rtrim($body) . "\n\n" . $section . "\n\n";
        }

        $ts = gmdate('c');
        $block = "### {$ts}\n\n" . trim($summaryMarkdown) . "\n\n---\n\n";

        @file_put_contents($path, rtrim($body) . "\n\n" . $block, LOCK_EX);
    }

    /**
     * Mentor program — Junior / Architect peer-review training rows (wiki canon for next cycles).
     *
     * @param array<string, mixed> $meta
     */
    public static function appendMentorTrainingData(Config $config, string $title, array $meta): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string) ($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            @file_put_contents($path, "# Evolution wiki (auto-generated)\n\n");
        }

        $raw = @file_get_contents($path);
        $body = is_string($raw) ? $raw : '';
        $section = '## Mentor Training Data';
        if (!str_contains($body, $section)) {
            $body = rtrim($body) . "\n\n" . $section . "\n\n";
        }

        $ts = gmdate('c');
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $block = "### {$title}\n\n- **Time (UTC):** {$ts}\n\n```json\n"
            . ($json !== false ? $json : '{}')
            . "\n```\n\n---\n\n";

        @file_put_contents($path, rtrim($body) . "\n\n" . $block, LOCK_EX);
    }

    public static function appendObservatoryTrendBrief(Config $config, string $markdownBody): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string) ($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            @file_put_contents($path, "# Evolution wiki (auto-generated)\n\n");
        }

        $raw = @file_get_contents($path);
        $body = is_string($raw) ? $raw : '';
        $section = '## Observatory — Trend telescope & Future Tech';
        if (!str_contains($body, $section)) {
            $body = rtrim($body) . "\n\n" . $section . "\n\n";
        }

        $ts = gmdate('c');
        $block = "### {$ts}\n\n" . trim($markdownBody) . "\n\n---\n\n";

        @file_put_contents($path, rtrim($body) . "\n\n" . $block, LOCK_EX);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function appendEleganceGalleryEntry(Config $config, string $title, string $code, float $score, array $meta = []): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string) ($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            @file_put_contents($path, "# Evolution wiki (auto-generated)\n\n");
        }

        $raw = @file_get_contents($path);
        $body = is_string($raw) ? $raw : '';
        $section = '## Elegance Gallery — Master-Sensei picks (10/10)';
        if (!str_contains($body, $section)) {
            $body = rtrim($body) . "\n\n" . $section . "\n\n";
        }

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $block = "### {$title}\n\n- **Score:** {$score}/10\n\n```php\n" . $code . "\n```\n\n";
        if (is_string($metaJson) && $metaJson !== '{}' && $metaJson !== '[]') {
            $block .= "\n```json\n" . $metaJson . "\n```\n";
        }
        $block .= "\n---\n\n";

        @file_put_contents($path, rtrim($body) . "\n\n" . $block, LOCK_EX);
    }

    public static function appendGovernorLoungePostcard(Config $config, string $fromRole, string $body, string $kind = 'note'): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string) ($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            @file_put_contents($path, "# Evolution wiki (auto-generated)\n\n");
        }

        $raw = @file_get_contents($path);
        $bodyMd = is_string($raw) ? $raw : '';
        $section = "## Governor's Lounge — ansichtkaarten";
        if (!str_contains($bodyMd, $section)) {
            $bodyMd = rtrim($bodyMd) . "\n\n" . $section . "\n\n";
        }

        $ts = gmdate('c');
        $block = "### {$ts} — {$fromRole} ({$kind})\n\n> " . str_replace("\n", "\n> ", trim($body)) . "\n\n---\n\n";

        @file_put_contents($path, rtrim($bodyMd) . "\n\n" . $block, LOCK_EX);

        $loungeMd = 'docs/GOVERNOR_LOUNGE.md';
        $lp = BASE_PATH . '/' . $loungeMd;
        $ldir = dirname($lp);
        if (!is_dir($ldir)) {
            @mkdir($ldir, 0755, true);
        }
        if (!is_file($lp)) {
            @file_put_contents($lp, "# Governor's Lounge\n\nReadable copy of postcards (see also storage/evolution/governor_lounge.jsonl).\n\n---\n\n");
        }
        @file_put_contents($lp, $block, FILE_APPEND | LOCK_EX);
    }

    public static function appendTimeCapsuleIndex(Config $config, string $sealedFile, string $sealedAtUtc): void
    {
        $w = self::cfg($config);
        if ($w === null || !filter_var($w['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rel = trim((string) ($w['markdown_path'] ?? self::DEFAULT_DOC));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $path = BASE_PATH . '/' . ltrim($rel, '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_file($path)) {
            @file_put_contents($path, "# Evolution wiki (auto-generated)\n\n");
        }

        $raw = @file_get_contents($path);
        $body = is_string($raw) ? $raw : '';
        $section = '## Evolution Time-Capsule index';
        if (!str_contains($body, $section)) {
            $body = rtrim($body) . "\n\n" . $section . "\n\n";
        }

        $line = '- **' . $sealedAtUtc . '** — `' . $sealedFile . "`\n";
        @file_put_contents($path, rtrim($body) . "\n\n" . $line, LOCK_EX);
    }

    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $w = is_array($evo) ? ($evo['evolution_wiki'] ?? null) : null;

        return is_array($w) ? $w : null;
    }
}
