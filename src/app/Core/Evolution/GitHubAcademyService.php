<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * GitHubAcademyService — GitHub Knowledge Bridge
 *
 * Haalt lessen (Markdown-bestanden) op uit een private of public GitHub-repo
 * en slaat ze lokaal op in storage/evolution/academy_cache/.
 *
 * Configuratie in evolution.json:
 *   "academy": {
 *     "github": {
 *       "enabled": true,
 *       "token": "ghp_...",
 *       "owner": "jouw-org",
 *       "repo":  "evolution-curriculum",
 *       "branch": "main",
 *       "sync_interval_hours": 24
 *     }
 *   }
 *
 * Topic-detectie: filename-matching op taak-tekst.
 * "optimaliseer JIT" → zoekt PHP_8.4_Performance.md, JIT*.md, PHP*.md
 */
final class GitHubAcademyService
{
    private const CACHE_DIR      = 'storage/evolution/academy_cache';
    private const TOPIC_MAP_FILE = 'storage/evolution/academy_cache/topic_index.json';
    private const SYNC_LOCK_FILE = 'storage/evolution/academy_cache/.last_sync';
    private const GITHUB_API     = 'https://api.github.com';

    // ── Public API ──────────────────────────────────────────────────────────────

    /**
     * JIT lesson lookup: vindt de meest relevante les voor een taakomschrijving.
     *
     * @param array<string, mixed> $config   evolution.json["academy"]["github"]
     * @return string|null  Volledige Markdown-tekst of null als niet gevonden
     */
    public static function findLesson(string $taskText, array $config): ?string
    {
        if (!($config['enabled'] ?? false)) {
            return null;
        }

        $topic = self::detectTopic($taskText);
        if ($topic === null) {
            return null;
        }

        return self::loadCachedLesson($topic) ?? self::fetchFromGitHub($topic, $config);
    }

    /**
     * Synchroniseer alle .md lessen uit de GitHub-repo naar de lokale cache.
     * Respecteert sync_interval_hours om GitHub rate-limits te sparen.
     *
     * @param array<string, mixed> $config
     */
    public static function syncCurriculum(array $config): array
    {
        if (!($config['enabled'] ?? false)) {
            return ['ok' => false, 'reason' => 'academy.github.enabled is false'];
        }

        $base     = self::basePath();
        $cacheDir = $base . '/' . self::CACHE_DIR;
        $lockFile = $base . '/' . self::SYNC_LOCK_FILE;

        // Sync-interval bewaken
        $intervalHours = (int)($config['sync_interval_hours'] ?? 24);
        if (is_file($lockFile)) {
            $lastSync = (int)@file_get_contents($lockFile);
            if (time() - $lastSync < $intervalHours * 3600) {
                return ['ok' => true, 'reason' => 'skipped (interval not elapsed)', 'synced' => 0];
            }
        }

        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }

        $owner  = $config['owner'] ?? '';
        $repo   = $config['repo']  ?? '';
        $branch = $config['branch'] ?? 'main';
        $token  = $config['token'] ?? '';

        if ($owner === '' || $repo === '') {
            return ['ok' => false, 'reason' => 'github owner/repo not configured'];
        }

        // Haal lijst van .md files op
        $files = self::apiGet("/repos/{$owner}/{$repo}/git/trees/{$branch}?recursive=1", $token);
        if (!isset($files['tree'])) {
            return ['ok' => false, 'reason' => 'github tree API failed'];
        }

        $synced  = 0;
        $index   = [];

        foreach ($files['tree'] as $node) {
            $path = (string)($node['path'] ?? '');
            if (!str_ends_with($path, '.md') || ($node['type'] ?? '') !== 'blob') {
                continue;
            }

            $content = self::fetchRawFile($owner, $repo, $path, $branch, $token);
            if ($content === null) {
                continue;
            }

            $slug     = self::pathToSlug($path);
            $cacheFile = $cacheDir . '/' . $slug . '.md';
            @file_put_contents($cacheFile, $content);

            // Topics in index: keywords uit bestandsnaam + eerste heading
            $keywords = self::extractKeywords($path, $content);
            $index[$slug] = ['file' => $cacheFile, 'keywords' => $keywords, 'synced_at' => time()];
            $synced++;
        }

        @file_put_contents($base . '/' . self::TOPIC_MAP_FILE, json_encode($index, JSON_PRETTY_PRINT));
        @file_put_contents($lockFile, (string)time());

        EvolutionLogger::log('academy', 'github_sync', ['synced' => $synced, 'repo' => "{$owner}/{$repo}"]);

        return ['ok' => true, 'synced' => $synced];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function listCachedLessons(array $config): array
    {
        $indexFile = self::basePath() . '/' . self::TOPIC_MAP_FILE;
        if (!is_file($indexFile)) {
            return [];
        }
        return json_decode((string)file_get_contents($indexFile), true) ?: [];
    }

    // ── Topic Detection ──────────────────────────────────────────────────────────

    /**
     * Detecteer het meest relevante curriculum-topic uit een taakomschrijving.
     */
    public static function detectTopic(string $taskText): ?string
    {
        $lower = strtolower($taskText);

        $topicKeywords = [
            'php_jit'         => ['jit', 'opcache', 'php 8.4', 'php8.4', 'performance', 'preloading'],
            'php_readonly'    => ['readonly', 'immutable', 'clone with', 'value object'],
            'php_fibers'      => ['fiber', 'async', 'concurrent', 'coroutine'],
            'tailwind'        => ['tailwind', 'css token', 'design system', 'utility class', 'responsive'],
            'twig'            => ['twig', 'template', 'extends', 'block', 'macro'],
            'mysql_query'     => ['query optimiz', 'index', 'explain', 'slow query', 'n+1'],
            'rust_safety'     => ['rust', 'borrow checker', 'ownership', 'lifetime', 'memory safe'],
            'redis'           => ['redis', 'cache invalidat', 'session store', 'pub/sub'],
            'pipeline'        => ['pipeline', 'middleware', 'step', 'context object'],
            'security'        => ['xss', 'csrf', 'injection', 'sanitize', 'escape', 'csp', 'nonce'],
            'ai_prompting'    => ['prompt engineer', 'system prompt', 'llm', 'token limit', 'context window'],
        ];

        $best      = null;
        $bestScore = 0;

        foreach ($topicKeywords as $topic => $keywords) {
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $topic;
            }
        }

        return $bestScore >= 1 ? $best : null;
    }

    // ── Private helpers ──────────────────────────────────────────────────────────

    private static function loadCachedLesson(string $topic): ?string
    {
        $base      = self::basePath();
        $indexFile = $base . '/' . self::TOPIC_MAP_FILE;

        if (!is_file($indexFile)) {
            return null;
        }

        $index = json_decode((string)file_get_contents($indexFile), true) ?: [];

        // Directe slug match
        if (isset($index[$topic])) {
            $file = $index[$topic]['file'] ?? '';
            return is_file($file) ? (string)file_get_contents($file) : null;
        }

        // Fuzzy: zoek op keywords
        foreach ($index as $slug => $meta) {
            $keywords = (array)($meta['keywords'] ?? []);
            foreach ($keywords as $kw) {
                if (str_contains(strtolower($kw), $topic) || str_contains($topic, strtolower($kw))) {
                    $file = $meta['file'] ?? '';
                    return is_file($file) ? (string)file_get_contents($file) : null;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function fetchFromGitHub(string $topic, array $config): ?string
    {
        $owner  = $config['owner'] ?? '';
        $repo   = $config['repo']  ?? '';
        $branch = $config['branch'] ?? 'main';
        $token  = $config['token'] ?? '';

        if ($owner === '' || $repo === '') {
            return null;
        }

        // Zoek bestand op naam in de repo
        $searchUrl = "/search/code?q={$topic}+repo:{$owner}/{$repo}+extension:md";
        $result    = self::apiGet($searchUrl, $token);
        $items     = $result['items'] ?? [];

        if (empty($items)) {
            return null;
        }

        $path    = $items[0]['path'] ?? '';
        $content = self::fetchRawFile($owner, $repo, $path, $branch, $token);

        if ($content !== null) {
            // Cache lokaal
            $base      = self::basePath();
            $cacheDir  = $base . '/' . self::CACHE_DIR;
            if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
            $slug = self::pathToSlug($path);
            @file_put_contents($cacheDir . '/' . $slug . '.md', $content);
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private static function apiGet(string $path, string $token): array
    {
        $url = self::GITHUB_API . $path;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", array_filter([
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                    'User-Agent: EvolutionAcademy/1.0',
                    $token !== '' ? "Authorization: Bearer {$token}" : '',
                ])),
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    private static function fetchRawFile(
        string $owner,
        string $repo,
        string $path,
        string $branch,
        string $token,
    ): ?string {
        $url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$path}";
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", array_filter([
                    'User-Agent: EvolutionAcademy/1.0',
                    $token !== '' ? "Authorization: Bearer {$token}" : '',
                ])),
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        return (is_string($raw) && strlen($raw) > 10) ? $raw : null;
    }

    /**
     * @return list<string>
     */
    private static function extractKeywords(string $path, string $content): array
    {
        $keywords = [];

        // Uit bestandsnaam: PHP_8.4_Performance → ['php', '8.4', 'performance']
        $name   = strtolower(pathinfo($path, PATHINFO_FILENAME));
        $parts  = preg_split('/[_\-\s\.]+/', $name) ?: [];
        foreach ($parts as $part) {
            if (strlen($part) > 2) {
                $keywords[] = $part;
            }
        }

        // Eerste # heading uit markdown
        if (preg_match('/^#+\s+(.+)$/m', $content, $m)) {
            $title = strtolower(trim($m[1]));
            foreach (preg_split('/\s+/', $title) ?: [] as $word) {
                if (strlen($word) > 3) {
                    $keywords[] = $word;
                }
            }
        }

        return array_values(array_unique($keywords));
    }

    private static function pathToSlug(string $path): string
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        return strtolower((string)preg_replace('/[^a-z0-9_\-]/i', '_', $name));
    }

    private static function basePath(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
    }
}
