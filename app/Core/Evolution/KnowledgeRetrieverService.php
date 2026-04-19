<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Loads .skill files from storage/evolution/skills/ and injects relevant
 * knowledge as context into AI agent prompts (e.g. ArchitectChatService).
 *
 * Usage:
 *   $context = KnowledgeRetrieverService::retrieve(['architecture', 'database']);
 *   // Prepend $context to the system prompt
 */
final class KnowledgeRetrieverService
{
    private const SKILLS_DIR = '/var/www/html/data/neural/skills';
    private const FALLBACK_DIR_RELATIVE = 'data/neural/skills';
    private const INDEX_FILE = '/var/www/html/data/evolution/skill_tfidf_index.json';

    /**
     * Load all .skill files matching the given tags (or all if tags empty).
     * Returns a formatted context string ready for system prompt injection.
     *
     * @param string[] $tags Filter by tags; empty = return all skills
     */
    public static function retrieve(array $tags = []): string
    {
        $dir = self::resolveSkillsDir();
        if ($dir === null) {
            return '';
        }

        $files = glob($dir . '/*.skill') ?: [];
        if (empty($files)) {
            return '';
        }

        // Master merge file always loads first; individual source files are secondary
        $masterFile = $dir . '/evolution_v7_core_intelligence.skill';
        $files = array_filter($files, static fn(string $f) => $f !== $masterFile);
        sort($files);
        if (is_readable($masterFile)) {
            array_unshift($files, $masterFile);
        }

        $blocks = [];
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }

            $skill = json_decode($raw, true);
            if (!is_array($skill)) {
                continue;
            }

            if (!empty($tags)) {
                $skillTags = (array)($skill['tags'] ?? []);
                if (empty(array_intersect($tags, $skillTags))) {
                    continue;
                }
            }

            $id = (string)($skill['skill_id'] ?? basename($file, '.skill'));
            $blocks[] = self::formatSkill($id, $skill);
        }

        if (empty($blocks)) {
            return '';
        }

        return "\n\n--- FRAMEWORK KNOWLEDGE BASE ---\n" . implode("\n\n", $blocks) . "\n--- END KNOWLEDGE BASE ---\n";
    }

    /**
     * Quick single-skill loader by ID.
     */
    public static function skill(string $id): array
    {
        $dir = self::resolveSkillsDir();
        if ($dir === null) {
            return [];
        }

        $path = $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '', $id) . '.skill';
        if (!is_readable($path)) {
            return [];
        }

        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private static function formatSkill(string $id, array $skill): string
    {
        $knowledge = $skill['knowledge'] ?? $skill;
        return "SKILL [{$id}] (v" . ($skill['version'] ?? '1.0') . "):\n" . json_encode($knowledge, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Inject relevant past failures from Consensus Memory into an AI prompt.
     * Call this BEFORE every AI task to prevent agents repeating known mistakes.
     *
     * @param  string $task   Current task description for relevance scoring
     * @param  int    $limit  Max entries (default 8)
     */
    public static function retrieveFailedAttempts(string $task = '', int $limit = 8): string
    {
        // Avoid hard dependency — graceful if class not yet deployed
        if (!class_exists('App\Core\Evolution\EvolutionConsensusMemory')) {
            return '';
        }
        return \App\Core\Evolution\EvolutionConsensusMemory::retrieve(
            $task,
            [
                \App\Core\Evolution\EvolutionConsensusMemory::TYPE_SIMULATION_FAIL,
                \App\Core\Evolution\EvolutionConsensusMemory::TYPE_JURY_REJECTION,
                \App\Core\Evolution\EvolutionConsensusMemory::TYPE_COVE_CRITIQUE,
            ],
            $limit
        );
    }

    /**
     * Semantic retrieval via TF-IDF cosine similarity.
     * Returns top-K skills most relevant to the query, regardless of tags.
     * Falls back to tag-based retrieve() if no index exists.
     *
     * @param  string $query  Free-text query, e.g. "payment token storage security"
     * @param  int    $topK   Number of skills to return (default 5)
     */
    public static function retrieveSemantic(string $query, int $topK = 5): string
    {
        $index = self::loadIndex();

        if ($index === null) {
            // No index yet — fall back to tag-based, but extract likely tags from query
            $guessedTags = self::guessTagsFromQuery($query);
            return self::retrieve($guessedTags);
        }

        $queryVec  = self::tfidfVector(self::tokenize($query), $index['idf']);
        $scores    = [];

        foreach ($index['documents'] as $skillId => $doc) {
            $scores[$skillId] = self::cosineSimilarity($queryVec, $doc['tfidf']);
        }

        arsort($scores);
        $topSkillIds = array_keys(array_slice($scores, 0, max(1, $topK), true));

        $dir = self::resolveSkillsDir();
        if ($dir === null) {
            return '';
        }

        $blocks = [];
        foreach ($topSkillIds as $skillId) {
            $path = $dir . '/' . $skillId . '.skill';
            if (!is_readable($path)) {
                continue;
            }
            $skill = json_decode((string)file_get_contents($path), true);
            if (!is_array($skill)) {
                continue;
            }
            $score = round((float)($scores[$skillId] ?? 0), 3);
            $blocks[] = "SKILL [{$skillId}] (relevance={$score}):\n" . json_encode($skill['knowledge'] ?? $skill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (empty($blocks)) {
            return self::retrieve();
        }

        return "\n\n--- FRAMEWORK KNOWLEDGE BASE (semantic: \"{$query}\") ---\n" . implode("\n\n", $blocks) . "\n--- END KNOWLEDGE BASE ---\n";
    }

    /**
     * Build or rebuild the TF-IDF index from all current .skill files.
     * Saves to storage/evolution/skill_tfidf_index.json.
     * Run after adding/updating skills: php ai_bridge.php evolve:create-skill build-index
     *
     * @return array{skills: int, terms: int, path: string}
     */
    public static function buildSemanticIndex(): array
    {
        $dir = self::resolveSkillsDir();
        if ($dir === null) {
            return ['skills' => 0, 'terms' => 0, 'path' => ''];
        }

        $files = glob($dir . '/*.skill') ?: [];
        $corpus = [];  // skillId → token list

        foreach ($files as $file) {
            if (str_starts_with(basename($file), '_')) {
                continue;
            }
            $skill = json_decode((string)@file_get_contents($file), true);
            if (!is_array($skill)) {
                continue;
            }
            $skillId   = basename($file, '.skill');
            $corpus[$skillId] = self::tokenize(self::extractText($skill));
        }

        if (empty($corpus)) {
            return ['skills' => 0, 'terms' => 0, 'path' => ''];
        }

        // Compute IDF across corpus
        $docCount = count($corpus);
        $df = [];  // term → number of documents containing it
        foreach ($corpus as $tokens) {
            foreach (array_unique($tokens) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $idf = [];
        foreach ($df as $term => $freq) {
            $idf[$term] = log(($docCount + 1) / ($freq + 1)) + 1.0;  // smoothed IDF
        }

        // Compute TF-IDF vectors per document
        $documents = [];
        foreach ($corpus as $skillId => $tokens) {
            $tf = array_count_values($tokens);
            $total = count($tokens);
            $tfidf = [];
            foreach ($tf as $term => $count) {
                if (isset($idf[$term])) {
                    $tfidf[$term] = ($count / $total) * $idf[$term];
                }
            }
            // Normalize vector
            $norm = sqrt(array_sum(array_map(static fn(float $v) => $v * $v, $tfidf)));
            if ($norm > 0) {
                $tfidf = array_map(static fn(float $v) => $v / $norm, $tfidf);
            }
            $documents[$skillId] = ['tfidf' => $tfidf, 'token_count' => $total];
        }

        $index = [
            'built_at'  => gmdate('c'),
            'idf'       => $idf,
            'documents' => $documents,
        ];

        $indexPath = self::resolveIndexPath();
        $indexDir  = dirname($indexPath);
        if (!is_dir($indexDir)) {
            @mkdir($indexDir, 0755, true);
        }
        file_put_contents($indexPath, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['skills' => count($documents), 'terms' => count($idf), 'path' => $indexPath];
    }

    // ── TF-IDF internals ─────────────────────────────────────────────────────

    /**
     * Tokenize text: lowercase, split on non-alphanumeric, remove stop words & short tokens.
     *
     * @return string[]
     */
    private static function tokenize(string $text): array
    {
        $text   = strtolower($text);
        $tokens = preg_split('/[^a-z0-9_]+/', $text) ?: [];
        $stop   = ['the', 'and', 'or', 'in', 'is', 'it', 'of', 'to', 'for', 'a', 'an', 'be',
                   'use', 'this', 'that', 'with', 'from', 'not', 'on', 'at', 'by', 'are',
                   'can', 'if', 'do', 'as', 'its', 'was', 'has', 'but', 'via', 'when',
                   'will', 'all', 'no', 'so', 'any', 'new', 'php', 'true', 'false', 'null'];
        return array_values(array_filter(
            $tokens,
            static fn(string $t) => strlen($t) >= 3 && !in_array($t, $stop, true)
        ));
    }

    /**
     * Extract flat text from a skill array (core_philosophy, forbidden patterns, etc.).
     */
    private static function extractText(array $skill): string
    {
        $parts = [];
        // Top-level fields
        foreach (['skill_id', 'authority', 'tags'] as $k) {
            if (isset($skill[$k])) {
                $parts[] = is_array($skill[$k]) ? implode(' ', $skill[$k]) : (string)$skill[$k];
            }
        }
        $k = is_array($skill['knowledge'] ?? null) ? $skill['knowledge'] : [];

        // core_philosophy
        if (isset($k['core_philosophy'])) { $parts[] = (string)$k['core_philosophy']; }

        // canonical_example
        $ce = $k['canonical_example'] ?? [];
        if (is_array($ce)) {
            foreach (['pattern_name', 'why', 'code'] as $f) {
                if (isset($ce[$f])) { $parts[] = (string)$ce[$f]; }
            }
        }

        // forbidden_patterns
        foreach ((array)($k['forbidden_patterns'] ?? []) as $fp) {
            if (is_array($fp)) {
                $parts[] = implode(' ', array_filter([(string)($fp['bad'] ?? ''), (string)($fp['why'] ?? ''), (string)($fp['fix'] ?? '')]));
            }
        }

        // anti_patterns, silent_failure_catalog
        foreach ((array)($k['anti_patterns'] ?? []) as $ap) {
            $parts[] = (string)$ap;
        }
        foreach ((array)($k['silent_failure_catalog'] ?? []) as $entry) {
            if (is_array($entry)) {
                $parts[] = implode(' ', array_filter([(string)($entry['what'] ?? ''), (string)($entry['symptom'] ?? '')]));
            }
        }

        // Fallback: JSON-encode the whole knowledge block
        if (empty($parts)) {
            $parts[] = json_encode($k) ?: '';
        }

        return implode(' ', $parts);
    }

    /**
     * Build a TF-IDF vector for a query using a pre-computed IDF map.
     *
     * @param  string[]             $queryTokens
     * @param  array<string, float> $idf
     * @return array<string, float>
     */
    private static function tfidfVector(array $queryTokens, array $idf): array
    {
        if (empty($queryTokens)) {
            return [];
        }
        $tf     = array_count_values($queryTokens);
        $total  = count($queryTokens);
        $vec    = [];
        foreach ($tf as $term => $count) {
            if (isset($idf[$term])) {
                $vec[$term] = ($count / $total) * $idf[$term];
            }
        }
        $norm = sqrt(array_sum(array_map(static fn(float $v) => $v * $v, $vec)));
        if ($norm > 0) {
            $vec = array_map(static fn(float $v) => $v / $norm, $vec);
        }
        return $vec;
    }

    /**
     * Cosine similarity between two TF-IDF vectors (both pre-normalized).
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $term => $va) {
            if (isset($b[$term])) {
                $dot += $va * $b[$term];
            }
        }
        return $dot;  // vectors are pre-normalized, so dot product = cosine similarity
    }

    /**
     * Guess tags from a free-text query as a fallback when no index is built.
     *
     * @return string[]
     */
    private static function guessTagsFromQuery(string $query): array
    {
        $q    = strtolower($query);
        $map  = [
            'sql'        => ['sql', 'query', 'select', 'insert', 'update', 'delete', 'database', 'pdo'],
            'security'   => ['security', 'auth', 'token', 'password', 'csrf', 'xss', 'injection', 'payment'],
            'cache'      => ['cache', 'redis', 'memcache', 'invalidat'],
            'logging'    => ['log', 'monitor', 'telemetry', 'audit'],
            'di'         => ['inject', 'container', 'service', 'depend'],
            'twig'       => ['twig', 'template', 'view', 'render', 'frontend'],
            'performance'=> ['performance', 'slow', 'n+1', 'index', 'optimiz'],
            'error'      => ['error', 'exception', 'catch', 'throw', 'fatal', 'edge'],
        ];

        $tags = [];
        foreach ($map as $tag => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($q, $kw)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }
        return array_unique($tags);
    }

    private static function loadIndex(): ?array
    {
        $path = self::resolveIndexPath();
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private static function resolveIndexPath(): string
    {
        if (str_starts_with(self::INDEX_FILE, '/var/www/html') && is_dir('/var/www/html')) {
            return self::INDEX_FILE;
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        return rtrim($base, '/') . '/storage/evolution/skill_tfidf_index.json';
    }

    private static function resolveSkillsDir(): ?string
    {
        if (is_dir(self::SKILLS_DIR)) {
            return self::SKILLS_DIR;
        }

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $rel  = rtrim($base, '/') . '/' . self::FALLBACK_DIR_RELATIVE;
        return is_dir($rel) ? $rel : null;
    }
}
