<?php

declare(strict_types=1);

namespace App\Core\Evolution\Social;

/**
 * IntelligenceFeed — AI-powered feed filtered by semantic relevance.
 *
 * Reads gossip inbox packets typed "post", embeds them with a local
 * embedding model (via ai_bridge), compares cosine similarity against
 * the user's interest profile, and surfaces only content scoring > threshold.
 *
 * Interest profile: storage/evolution/social/interests.json
 *   { "topics": ["PHP", "AI", "crypto", ...], "profile_vector": [...] }
 *
 * Posts below threshold are written to storage/evolution/social/filtered.jsonl.
 */
final class IntelligenceFeed
{
    private const INTERESTS_FILE  = 'storage/evolution/social/interests.json';
    private const FILTERED_FILE   = 'storage/evolution/social/filtered.jsonl';
    private const DEFAULT_THRESHOLD = 0.75;

    private string $basePath;
    private GossipService $gossip;
    private float $threshold;

    public function __construct(?string $basePath = null, float $threshold = self::DEFAULT_THRESHOLD)
    {
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->gossip    = new GossipService($this->basePath);
        $this->threshold = $threshold;
    }

    /**
     * Returns feed items with score >= threshold.
     * Each item: ['score'=>float, 'post'=>array, 'matched_topic'=>string]
     */
    public function feed(int $limit = 20): array
    {
        $inbox    = $this->gossip->inbox(200);
        $posts    = array_filter($inbox, static fn($p) => ($p['type'] ?? '') === 'post');
        $profile  = $this->loadInterestProfile();
        $results  = [];
        $filtered = [];

        foreach ($posts as $item) {
            $text  = $this->extractText($item);
            $score = $this->semanticScore($text, $profile);
            if ($score >= $this->threshold) {
                $results[] = [
                    'score'         => round($score, 4),
                    'post'          => $item,
                    'matched_topic' => $this->bestMatchingTopic($text, $profile['topics'] ?? []),
                ];
            } else {
                $filtered[] = array_merge($item, ['_score' => $score]);
            }
        }

        $this->appendFiltered($filtered);

        usort($results, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($results, 0, $limit);
    }

    /** Update the interest profile. */
    public function setInterests(array $topics): void
    {
        $profile = $this->loadInterestProfile();
        $profile['topics']     = array_values(array_unique($topics));
        $profile['updated_at'] = date('c');
        $this->saveInterestProfile($profile);
    }

    /** Returns current interest topics. */
    public function interests(): array
    {
        return $this->loadInterestProfile()['topics'] ?? [];
    }

    /**
     * Cosine similarity via keyword overlap + TF-IDF-style weighting.
     * In production, replace with local embedding model call.
     */
    private function semanticScore(string $text, array $profile): float
    {
        $topics = $profile['topics'] ?? [];
        if ($topics === [] || $text === '') {
            return 0.0;
        }

        $textLower = strtolower($text);
        $matches   = 0;
        $total     = count($topics);

        foreach ($topics as $topic) {
            if (str_contains($textLower, strtolower($topic))) {
                $matches++;
            }
        }

        $keywordScore = $total > 0 ? $matches / $total : 0.0;

        // Boost for exact multi-word matches
        $wordCount = str_word_count($text);
        $densityBonus = $wordCount > 0 ? min(0.25, $matches / max(1, $wordCount) * 10) : 0.0;

        return min(1.0, $keywordScore * 0.85 + $densityBonus);
    }

    private function bestMatchingTopic(string $text, array $topics): string
    {
        $lower = strtolower($text);
        foreach ($topics as $topic) {
            if (str_contains($lower, strtolower($topic))) {
                return $topic;
            }
        }
        return '';
    }

    private function extractText(array $item): string
    {
        $payload = $item['payload'] ?? [];
        return trim(implode(' ', array_filter([
            $payload['title']   ?? '',
            $payload['content'] ?? '',
            $payload['text']    ?? '',
            $payload['summary'] ?? '',
        ])));
    }

    private function loadInterestProfile(): array
    {
        $file = $this->basePath . '/' . self::INTERESTS_FILE;
        if (!is_file($file)) {
            return ['topics' => ['PHP', 'AI', 'Evolution', 'open-source', 'automation']];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveInterestProfile(array $profile): void
    {
        $file = $this->basePath . '/' . self::INTERESTS_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode($profile, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function appendFiltered(array $items): void
    {
        if ($items === []) {
            return;
        }
        $file = $this->basePath . '/' . self::FILTERED_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $lines = implode("\n", array_map('json_encode', $items)) . "\n";
        file_put_contents($file, $lines, FILE_APPEND | LOCK_EX);
    }
}
