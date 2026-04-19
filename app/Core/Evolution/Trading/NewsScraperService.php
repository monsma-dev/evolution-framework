<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * NewsScraperService — Haalt crypto/mining nieuwskoppen op via RSS.
 *
 * Bronnen: CoinTelegraph + Mining.com
 * Filter:  ETH, Ethereum, Mining, Supply, Shortage
 * Cache:   storage/evolution/trading/news_cache.json (30 min TTL)
 */
final class NewsScraperService
{
    private const FEEDS = [
        'cointelegraph' => 'https://cointelegraph.com/rss',
        'mining'        => 'https://mining.com/feed/',
    ];

    private const KEYWORDS = ['ETH', 'Ethereum', 'Mining', 'Supply', 'Shortage'];
    private const CACHE_FILE = 'storage/evolution/trading/news_cache.json';
    private const CACHE_TTL  = 1800; // 30 minuten
    private const TIMEOUT    = 8;

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Geeft gefilterde headlines terug: [['title', 'source', 'pubDate', 'link'], ...]
     * Gebruikt cache als die nog vers is.
     */
    public function fetchHeadlines(bool $forceRefresh = false): array
    {
        $cached = $this->loadCache();
        if (!$forceRefresh && $cached !== null) {
            return $cached;
        }

        $headlines = [];
        foreach (self::FEEDS as $source => $url) {
            $items = $this->fetchFeed($url, $source);
            $headlines = array_merge($headlines, $items);
        }

        $this->saveCache($headlines);
        return $headlines;
    }

    private function fetchFeed(string $url, string $source): array
    {
        $xml = $this->httpGet($url);
        if ($xml === null) {
            return [];
        }

        try {
            $feed  = new \SimpleXMLElement($xml);
            $items = $feed->channel->item ?? $feed->item ?? [];
        } catch (\Throwable) {
            return [];
        }

        $results = [];
        foreach ($items as $item) {
            $title = trim((string)($item->title ?? ''));
            if ($title === '' || !$this->matchesKeyword($title)) {
                continue;
            }
            $results[] = [
                'title'   => $title,
                'source'  => $source,
                'pubDate' => trim((string)($item->pubDate ?? '')),
                'link'    => trim((string)($item->link ?? '')),
            ];
        }

        return $results;
    }

    private function matchesKeyword(string $text): bool
    {
        foreach (self::KEYWORDS as $kw) {
            if (stripos($text, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    private function loadCache(): ?array
    {
        $file = $this->basePath . '/' . self::CACHE_FILE;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || (time() - (int)($data['ts'] ?? 0)) > self::CACHE_TTL) {
            return null;
        }
        return (array)($data['headlines'] ?? []);
    }

    private function saveCache(array $headlines): void
    {
        $dir = dirname($this->basePath . '/' . self::CACHE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents(
            $this->basePath . '/' . self::CACHE_FILE,
            json_encode(['ts' => time(), 'headlines' => $headlines]),
            LOCK_EX
        );
    }

    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; EvolutionAgent/1.0)',
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($status === 200 && is_string($resp) && $resp !== '') ? $resp : null;
    }
}
