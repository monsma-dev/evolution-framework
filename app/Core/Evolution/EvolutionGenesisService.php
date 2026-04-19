<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Project Genesis: Git → timeline, composer churn, intent-log archeologie, tool-sporen (.cursor / .windsurf).
 * Schrijft storage/evolution/framework_genesis.json (+ optioneel docs/GENESIS_INDEX.md).
 */
final class EvolutionGenesisService
{
    public const OUTPUT_JSON = 'storage/evolution/framework_genesis.json';

    private const INTENT_LOG = 'storage/evolution/intent_log.jsonl';
    private const PROMPT_DNA_RULES = 'storage/evolution/prompt_dna_rules.jsonl';
    private const ARCHITECT_CHAT_REL = 'app/Core/Evolution/ArchitectChatService.php';

    public function __construct(private readonly ?Container $container = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function cfg(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $g = is_array($evo) ? ($evo['genesis_index'] ?? []) : [];

        return is_array($g) ? $g : [];
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function buildIndex(Config $config): array
    {
        $g = self::cfg($config);
        if (!filter_var($g['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'genesis_index disabled'];
        }

        $maxCommits = max(50, min(5000, (int)($g['max_git_commits'] ?? 500)));
        $intentTail = max(20, min(500, (int)($g['intent_log_tail_lines'] ?? 120)));

        $git = $this->gitCommits($maxCommits);
        $shortlog = $this->gitShortlogAuthors(40);
        $composerLog = $this->gitOnelinePaths(60, ['composer.json', 'composer.lock']);
        $architectLog = $this->gitOnelinePaths(30, [self::ARCHITECT_CHAT_REL]);
        $milestones = self::detectMilestones($git['commits'] ?? []);

        $intentDigest = self::digestIntentLog($intentTail);
        $promptDigest = self::digestPromptDnaRules();
        $tools = self::scanToolArtifacts();

        $head = $this->gitRevParse('HEAD');
        $branch = $this->gitRevParse('--abbrev-ref', 'HEAD');

        $data = [
            'generated_at' => gmdate('c'),
            'git' => [
                'head' => $head,
                'branch' => $branch,
                'error' => $git['error'] ?? null,
            ],
            'milestones' => $milestones,
            'commits_sample' => array_slice($git['commits'] ?? [], 0, min(80, count($git['commits'] ?? []))),
            'authors_top' => $shortlog['lines'] ?? [],
            'composer_history' => $composerLog['lines'] ?? [],
            'architect_chat_history' => $architectLog['lines'] ?? [],
            'intent_log' => $intentDigest,
            'prompt_dna_rules_tail' => $promptDigest,
            'tool_artifacts' => $tools,
            'summary' => self::buildSummaryParagraph($milestones, $intentDigest, $composerLog['lines'] ?? [], $tools),
        ];

        $path = BASE_PATH . '/' . self::OUTPUT_JSON;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $written = @file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
        if ($written === false) {
            return ['ok' => false, 'error' => 'cannot write framework_genesis.json'];
        }

        if (filter_var($g['write_markdown_doc'] ?? true, FILTER_VALIDATE_BOOL)) {
            $mdPath = trim((string)($g['markdown_path'] ?? 'docs/GENESIS_INDEX.md'));
            if ($mdPath !== '' && !str_contains($mdPath, '..')) {
                self::writeMarkdownDoc(BASE_PATH . '/' . ltrim($mdPath, '/'), $data);
            }
        }

        if ($this->container !== null && filter_var($g['hall_of_fame_milestone'] ?? true, FILTER_VALIDATE_BOOL)) {
            $hof = new EvolutionHallOfFameService($this->container);
            $hof->recordMilestone(
                'Project Genesis index bijgewerkt — Git + intent + composer historie geconsolideerd.',
                'genesis_index',
                ['commits_parsed' => count($git['commits'] ?? []), 'path' => self::OUTPUT_JSON]
            );
        }

        return ['ok' => true, 'data' => $data, 'path' => self::OUTPUT_JSON];
    }

    /**
     * Gecachte index voor API (zonder rebuild).
     *
     * @return array{ok: bool, data?: array<string, mixed>, stale?: bool}
     */
    public static function readCached(): array
    {
        $path = BASE_PATH . '/' . self::OUTPUT_JSON;
        if (!is_file($path)) {
            return ['ok' => false, 'stale' => true];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($j) ? ['ok' => true, 'data' => $j, 'stale' => false] : ['ok' => false, 'stale' => true];
    }

    public static function promptAppend(Config $config): string
    {
        $g = self::cfg($config);
        if (!filter_var($g['append_to_architect_prompt'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $cached = self::readCached();
        if (!($cached['ok'] ?? false) || !isset($cached['data']['summary'])) {
            return '';
        }
        $sum = (string) $cached['data']['summary'];
        $max = max(400, min(8000, (int)($g['max_prompt_chars'] ?? 3500)));
        if (mb_strlen($sum) > $max) {
            $sum = mb_substr($sum, 0, $max) . '…';
        }

        return <<<TXT


PROJECT_GENESIS (Git + intent + composer — zie ook storage/evolution/framework_genesis.json):
{$sum}

TXT;
    }

    /**
     * @param list<array{hash:string, ts:int, subject:string, author:string, email:string}> $commits
     * @return array<string, list<string>>
     */
    private static function detectMilestones(array $commits): array
    {
        $patterns = [
            'evolution' => '/\bevolution\b|Architect|shadow|Ghost|kill.switch/i',
            'synthesis' => '/Supreme\s*Synthesis|synthesis|consensus/i',
            'composer' => '/composer|vendor|package|autoload/i',
            'infra' => '/docker|franken|pulse|opcache|deploy/i',
            'windsurf_cursor' => '/windsurf|cursor|copilot/i',
        ];
        $out = [];
        foreach ($patterns as $k => $re) {
            $out[$k] = [];
        }
        foreach ($commits as $c) {
            $sub = (string)($c['subject'] ?? '');
            foreach ($patterns as $k => $re) {
                if (preg_match($re, $sub) === 1 && count($out[$k]) < 12) {
                    $out[$k][] = mb_substr($sub, 0, 160);
                }
            }
        }

        return $out;
    }

    /**
     * @return array{lines?: list<string>, error?: string}
     */
    private function gitOnelinePaths(int $max, array $paths): array
    {
        $args = array_merge(['log', '-n', (string) $max, '--oneline', '--'], $paths);
        $out = $this->runGit($args);
        if ($out === null) {
            return ['error' => 'git failed', 'lines' => []];
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out))));

        return ['lines' => $lines];
    }

    /**
     * @return array{lines?: list<string>, error?: string}
     */
    private function gitShortlogAuthors(int $max): array
    {
        $out = $this->runGit(['shortlog', '-sn', '--all', '-n', (string) $max]);
        if ($out === null) {
            return ['lines' => [], 'error' => 'git shortlog failed'];
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out))));

        return ['lines' => $lines];
    }

    /**
     * @return array{commits: list<array<string, mixed>>, error?: string}
     */
    private function gitCommits(int $max): array
    {
        $format = '%H|%ct|%s|%an|%ae';
        $out = $this->runGit(['log', '-n', (string) $max, '--pretty=format:' . $format]);
        if ($out === null) {
            return ['commits' => [], 'error' => 'git log failed (is this a git repo?)'];
        }
        $commits = [];
        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 5);
            if (count($parts) < 5) {
                continue;
            }
            $commits[] = [
                'hash' => $parts[0],
                'ts' => (int) $parts[1],
                'subject' => $parts[2],
                'author' => $parts[3],
                'email' => $parts[4],
            ];
        }

        return ['commits' => $commits];
    }

    private function gitRevParse(string ...$revArgs): string
    {
        $out = $this->runGit(array_merge(['rev-parse'], $revArgs));
        if ($out === null) {
            return '';
        }

        return trim($out);
    }

    /**
     * @param list<string> $args arguments after `git -C repo`
     */
    private function runGit(array $args): ?string
    {
        $repo = BASE_PATH;
        if (!is_dir($repo . '/.git')) {
            return null;
        }
        $cmd = array_merge(['git', '-C', $repo], $args);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, $repo);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 && ($stdout === false || $stdout === '')) {
            return null;
        }

        return is_string($stdout) ? $stdout : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function digestIntentLog(int $tailLines): array
    {
        $path = BASE_PATH . '/' . self::INTENT_LOG;
        if (!is_file($path)) {
            return ['ok' => false, 'lines_total' => 0, 'kinds' => [], 'sample' => []];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return ['ok' => false, 'lines_total' => 0, 'kinds' => [], 'sample' => []];
        }
        $slice = array_slice($lines, -$tailLines);
        $kinds = [];
        $sample = [];
        foreach ($slice as $line) {
            $j = json_decode((string) $line, true);
            if (!is_array($j)) {
                continue;
            }
            $k = (string)($j['kind'] ?? '?');
            $kinds[$k] = ($kinds[$k] ?? 0) + 1;
            if (count($sample) < 8) {
                $sample[] = [
                    'ts' => $j['ts'] ?? '',
                    'kind' => $k,
                    'hint' => mb_substr(json_encode($j['payload'] ?? [], JSON_UNESCAPED_UNICODE), 0, 200),
                ];
            }
        }

        return [
            'ok' => true,
            'lines_total' => count($lines),
            'tail_analyzed' => count($slice),
            'kinds' => $kinds,
            'sample' => $sample,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function digestPromptDnaRules(): array
    {
        $path = BASE_PATH . '/' . self::PROMPT_DNA_RULES;
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $out = [];
        foreach (array_slice($lines, -15) as $line) {
            $j = json_decode((string) $line, true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function scanToolArtifacts(): array
    {
        $base = BASE_PATH;
        $out = [
            'cursor_rules' => self::dirBrief($base . '/.cursor'),
            'windsurf_rules' => self::dirBrief($base . '/.windsurf'),
            'cursorrules_file' => is_file($base . '/.cursorrules') ? ['bytes' => filesize($base . '/.cursorrules')] : null,
        ];

        return $out;
    }

    /**
     * @return array{files: int, sample: list<string>}|null
     */
    private static function dirBrief(string $dir): ?array
    {
        if (!is_dir($dir)) {
            return null;
        }
        $files = 0;
        $sample = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $files++;
                if (count($sample) < 12) {
                    $sample[] = str_replace($dir . '/', '', $f->getPathname());
                }
            }
        }

        return ['files' => $files, 'sample' => $sample];
    }

    /**
     * @param array<string, list<string>> $milestones
     * @param array<string, mixed> $intentDigest
     * @param list<string> $composerLines
     * @param array<string, mixed> $tools
     */
    private static function buildSummaryParagraph(
        array $milestones,
        array $intentDigest,
        array $composerLines,
        array $tools
    ): string {
        $parts = [
            'Framework Genesis: archeologische index van Git (onderwerp-hits voor evolution/synthesis/composer/infra), '
            . 'plus recente intent-log kinds en composer.json/lock geschiedenis voor Library Scout context.',
        ];
        foreach ($milestones as $label => $subs) {
            if ($subs !== []) {
                $parts[] = ucfirst((string) $label) . ' thema’s in commits (voorbeelden): ' . implode(' | ', array_slice($subs, 0, 3));
            }
        }
        if (!empty($intentDigest['kinds']) && is_array($intentDigest['kinds'])) {
            $parts[] = 'Intent-log (recent): ' . json_encode($intentDigest['kinds'], JSON_UNESCAPED_UNICODE);
        }
        if ($composerLines !== []) {
            $parts[] = 'Composer recent: ' . implode('; ', array_slice($composerLines, 0, 5));
        }
        if (($tools['cursor_rules']['files'] ?? 0) > 0 || ($tools['windsurf_rules']['files'] ?? 0) > 0) {
            $parts[] = 'Tooling: .cursor en/of .windsurf regels aanwezig — zie tool_artifacts in framework_genesis.json.';
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeMarkdownDoc(string $absolutePath, array $data): void
    {
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $lines = [
            '# Framework Genesis (auto-generated)',
            '',
            'Gegenereerd: ' . ($data['generated_at'] ?? ''),
            '',
            '## Samenvatting',
            '',
            '```',
            (string)($data['summary'] ?? ''),
            '```',
            '',
            '## Git',
            '',
            '- HEAD: `' . ($data['git']['head'] ?? '') . '`',
            '- Branch: `' . ($data['git']['branch'] ?? '') . '`',
            '',
            '## Milestone-thema’s (commit-onderwerpen)',
            '',
        ];
        foreach ($data['milestones'] ?? [] as $k => $subs) {
            if (!is_array($subs) || $subs === []) {
                continue;
            }
            $lines[] = '### ' . $k;
            foreach ($subs as $s) {
                $lines[] = '- ' . $s;
            }
            $lines[] = '';
        }
        $lines[] = '## Composer / lock (recente logregels)';
        $lines[] = '';
        foreach (array_slice($data['composer_history'] ?? [], 0, 40) as $ln) {
            $lines[] = '- ' . $ln;
        }
        $lines[] = '';
        $lines[] = '## ArchitectChatService (recente wijzigingen)';
        $lines[] = '';
        foreach (array_slice($data['architect_chat_history'] ?? [], 0, 25) as $ln) {
            $lines[] = '- ' . $ln;
        }
        $lines[] = '';
        $lines[] = 'Bron: `storage/evolution/framework_genesis.json` — herbouw via `php ai_bridge.php evolution:genesis-index` of admin API.';
        $lines[] = '';

        @file_put_contents($absolutePath, implode("\n", $lines));
    }
}
