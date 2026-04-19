<?php

declare(strict_types=1);

namespace App\Core\Evolution\Affiliate;

use Psr\Container\ContainerInterface;

/**
 * OpportunityMonitor — Scant tech/B2B RSS-feeds op winstgevende affiliate kansen.
 *
 * Bronnen (publiek, geen auth vereist):
 *   - TechCrunch  : opkomende SaaS tools, funding nieuws
 *   - ProductHunt : trending products (dagelijks)
 *   - Hacker News : discussies over B2B problemen
 *   - AI News     : AI tool releases
 *
 * Werking:
 *   1. Haalt RSS feeds op (30 min cache)
 *   2. Gebruikt Claude 3.5 Haiku om te scoren op commercieel potentieel
 *   3. Slaat kansen op in affiliate_opportunities tabel
 *
 * Categorie-filter: Tech tools, SaaS, AI, Automatisering, B2B diensten.
 */
final class OpportunityMonitor
{
    private const FEEDS = [
        'techcrunch'   => 'https://techcrunch.com/feed/',
        'producthunt'  => 'https://www.producthunt.com/feed',
        'hnrss'        => 'https://hnrss.org/frontpage',
        'ainews'       => 'https://tldr.tech/ai/rss',
    ];

    private const CACHE_FILE = 'storage/evolution/affiliate/opportunity_cache.json';
    private const CACHE_TTL  = 1800; // 30 min
    private const TIMEOUT    = 10;

    private const COMMERCIAL_KEYWORDS = [
        'saas', 'tool', 'platform', 'automation', 'ai', 'artificial intelligence',
        'software', 'api', 'integration', 'b2b', 'productivity', 'workflow',
        'pricing', 'subscription', 'affiliate', 'commission', 'revenue', 'startup',
    ];

    private ?ContainerInterface $container;
    private string              $basePath;
    private ?\PDO               $db;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null, ?\PDO $db = null)
    {
        $this->container = $container;
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->db        = $db;
    }

    /**
     * Scan feeds en sla nieuwe kansen op.
     *
     * @return array{found: int, scored: int, saved: int, cost_eur: float}
     */
    public function scan(bool $forceRefresh = false): array
    {
        $items = $this->fetchAllFeeds($forceRefresh);
        if (empty($items)) {
            return ['found' => 0, 'scored' => 0, 'saved' => 0, 'cost_eur' => 0.0];
        }

        $totalCost = 0.0;
        $scored    = 0;
        $saved     = 0;

        foreach (array_slice($items, 0, 20) as $item) {
            $analysis = $this->analyzeWithHaiku($item);
            $totalCost += $analysis['cost_eur'] ?? 0.0;

            if (($analysis['potential_value'] ?? 0) >= 5) {
                $scored++;
                if ($this->saveOpportunity($item, $analysis)) {
                    $saved++;
                }
            }
        }

        return [
            'found'    => count($items),
            'scored'   => $scored,
            'saved'    => $saved,
            'cost_eur' => round($totalCost, 4),
        ];
    }

    /**
     * Haal recente kansen op uit de cache voor Telegram/status.
     */
    public function getTopOpportunities(int $limit = 5): array
    {
        if ($this->db === null) {
            return $this->loadFromCache();
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, title, niche, potential_value, category, status, discovered_at
                 FROM affiliate_opportunities
                 ORDER BY potential_value DESC, discovered_at DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    private function fetchAllFeeds(bool $forceRefresh): array
    {
        $cacheFile = $this->basePath . '/' . self::CACHE_FILE;
        if (!$forceRefresh && is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && (time() - (int)($cached['_ts'] ?? 0)) < self::CACHE_TTL) {
                return $cached['items'] ?? [];
            }
        }

        $allItems = [];
        foreach (self::FEEDS as $source => $url) {
            $items = $this->parseFeed($url, $source);
            $allItems = array_merge($allItems, $items);
        }

        // Filter op commerciële keywords
        $filtered = array_filter($allItems, fn($i) => $this->isCommerciallyRelevant($i));
        $items    = array_values($filtered);

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($cacheFile, json_encode(['_ts' => time(), 'items' => $items]));

        return $items;
    }

    private function parseFeed(string $url, string $source): array
    {
        $xml = $this->httpGet($url);
        if ($xml === null) {
            return [];
        }

        $items = [];
        try {
            $doc = @simplexml_load_string($xml);
            if ($doc === false) {
                return [];
            }
            $entries = $doc->channel->item ?? $doc->entry ?? [];
            foreach ($entries as $entry) {
                $title   = (string)($entry->title ?? '');
                $link    = (string)($entry->link ?? $entry->id ?? '');
                $desc    = strip_tags((string)($entry->description ?? $entry->summary ?? ''));
                $pubDate = (string)($entry->pubDate ?? $entry->published ?? '');

                if ($title !== '') {
                    $items[] = [
                        'title'   => $title,
                        'url'     => $link,
                        'summary' => mb_substr($desc, 0, 400),
                        'source'  => $source,
                        'date'    => $pubDate,
                    ];
                }
            }
        } catch (\Throwable) {
        }

        return array_slice($items, 0, 10);
    }

    private function isCommerciallyRelevant(array $item): bool
    {
        $text = strtolower($item['title'] . ' ' . $item['summary']);
        foreach (self::COMMERCIAL_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Analyseer item met Claude 3.5 Haiku voor snelle scoring.
     *
     * @return array{niche: string, potential_value: int, category: string, reason: string, cost_eur: float}
     */
    private function analyzeWithHaiku(array $item): array
    {
        $default = ['niche' => 'tech', 'potential_value' => 5, 'category' => 'tech_tool', 'reason' => 'Auto-score', 'cost_eur' => 0.0];

        if ($this->container === null) {
            return $default;
        }

        try {
            $llm    = new \App\Domain\AI\LlmClient($this->container);
            $prompt = "Analyseer dit tech/B2B nieuws item op affiliate-potentieel:\n\n"
                    . "Titel: {$item['title']}\n"
                    . "Samenvatting: {$item['summary']}\n\n"
                    . "Geef PRECIES:\n"
                    . "NICHE: [bijv. ai-tools, saas, automatisering, e-commerce]\n"
                    . "SCORE: [1-10, waarbij 8+ = hoog affiliate potentieel]\n"
                    . "CATEGORIE: [tech_tool|saas|b2b_service|affiliate_product|other]\n"
                    . "REDEN: [één zin waarom]";

            $result  = $llm->callModel('claude-3-5-haiku-20241022', 'Je bent een affiliate marketing expert.', $prompt);
            $text    = (string)($result['content'] ?? '');
            $cost    = (float)($result['cost_eur'] ?? 0.001);

            $niche    = $this->extract($text, 'NICHE', 'tech');
            $score    = (int)$this->extract($text, 'SCORE', '5');
            $category = $this->extract($text, 'CATEGORIE', 'other');
            $reason   = $this->extract($text, 'REDEN', '');

            return [
                'niche'           => strtolower(preg_replace('/[^a-z0-9\-]/', '', strtolower($niche)) ?: 'tech'),
                'potential_value' => max(1, min(10, $score)),
                'category'        => in_array($category, ['tech_tool', 'saas', 'b2b_service', 'affiliate_product', 'other']) ? $category : 'other',
                'reason'          => $reason,
                'cost_eur'        => $cost,
            ];
        } catch (\Throwable) {
            return $default;
        }
    }

    private function saveOpportunity(array $item, array $analysis): bool
    {
        if ($this->db === null) {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO affiliate_opportunities
                 (public_id, title, summary, source_url, source_name, niche, potential_value, category, ai_analysis, discovered_at)
                 VALUES (:pid, :title, :summary, :url, :source, :niche, :val, :cat, :ai, NOW())'
            );
            $stmt->execute([
                ':pid'     => $this->makePublicId(),
                ':title'   => mb_substr($item['title'], 0, 500),
                ':summary' => mb_substr($item['summary'] ?? '', 0, 1000),
                ':url'     => mb_substr($item['url'] ?? '', 0, 1000),
                ':source'  => $item['source'] ?? '',
                ':niche'   => $analysis['niche'],
                ':val'     => $analysis['potential_value'],
                ':cat'     => $analysis['category'],
                ':ai'      => json_encode($analysis),
            ]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function extract(string $text, string $key, string $default): string
    {
        if (preg_match('/^' . $key . ':\s*(.+)/mi', $text, $m)) {
            return trim($m[1]);
        }
        return $default;
    }

    private function makePublicId(): string
    {
        return strtolower(bin2hex(random_bytes(13)));
    }

    private function loadFromCache(): array
    {
        $file = $this->basePath . '/' . self::CACHE_FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return array_slice($data['items'] ?? [], 0, 5);
    }

    private function httpGet(string $url): ?string
    {
        $ctx = stream_context_create(['http' => [
            'timeout'       => self::TIMEOUT,
            'ignore_errors' => true,
            'method'        => 'GET',
            'header'        => 'User-Agent: EvolutionFramework/1.0 (+https://github.com/)',
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        return $raw !== false ? $raw : null;
    }
}
