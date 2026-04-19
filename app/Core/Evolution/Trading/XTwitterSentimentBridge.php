<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Config;

/**
 * Koppelt social (X/Twitter) sentiment-signalen aan trading-context (Base / actieve EVM-keten).
 *
 * Zonder X API: Tavily-web_search (evolution.web_search) voor "Ethereum X Twitter sentiment" als fallback.
 * Optionele env: X_BEARER_TOKEN (niet verplicht).
 */
final class XTwitterSentimentBridge
{
    private const CACHE_FILE = 'storage/evolution/trading/x_sentiment_bridge.json';
    private const CACHE_TTL  = 1800;

    public function __construct(
        private readonly Config $config,
        private readonly string $basePath
    ) {
    }

    /**
     * @return array{enabled: bool, score: float, label: string, source: string, chain_id: int|null, note: string, ts: string}
     */
    public function snapshot(): array
    {
        $tr = $this->config->get('evolution.trading', []);
        $xb = is_array($tr) ? ($tr['x_sentiment_bridge'] ?? []) : [];
        $enabled = filter_var($xb['enabled'] ?? true, FILTER_VALIDATE_BOOL);
        $chainId = is_array($tr) && isset($tr['evm']['chain_id']) ? (int)$tr['evm']['chain_id'] : null;

        if (!$enabled) {
            return [
                'enabled' => false,
                'score' => 0.0,
                'label' => 'uit',
                'source' => 'disabled',
                'chain_id' => $chainId,
                'note' => 'evolution.trading.x_sentiment_bridge.enabled = false',
                'ts' => gmdate('c'),
            ];
        }

        $cached = $this->loadCache();
        if ($cached !== null) {
            return array_merge($cached, ['cached' => true]);
        }

        $bearer = trim((string)(getenv('X_BEARER_TOKEN') ?: ''));
        if ($bearer !== '') {
            $out = $this->fetchViaRulesPlaceholder($bearer, $chainId);
            $this->saveCache($out);

            return $out;
        }

        $out = $this->fetchViaTavilyFallback($chainId);
        $this->saveCache($out);

        return $out;
    }

    /**
     * Score voor governance/tick: -1..1
     */
    public function scoreForTrading(): float
    {
        $s = $this->snapshot();

        return (float)($s['score'] ?? 0.0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCache(): ?array
    {
        $p = $this->basePath . '/' . self::CACHE_FILE;
        if (!is_file($p)) {
            return null;
        }
        $j = json_decode((string)file_get_contents($p), true);
        if (!is_array($j) || !isset($j['ts_unix'])) {
            return null;
        }
        if ((time() - (int)$j['ts_unix']) > self::CACHE_TTL) {
            return null;
        }
        unset($j['ts_unix']);

        return $j;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveCache(array $data): void
    {
        $dir = dirname($this->basePath . '/' . self::CACHE_FILE);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $data['ts_unix'] = time();
        @file_put_contents(
            $this->basePath . '/' . self::CACHE_FILE,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @return array{enabled: bool, score: float, label: string, source: string, chain_id: int|null, note: string, ts: string}
     */
    private function fetchViaRulesPlaceholder(string $bearer, ?int $chainId): array
    {
        unset($bearer);

        return [
            'enabled' => true,
            'score' => 0.0,
            'label' => 'neutraal',
            'source' => 'x_api_pending',
            'chain_id' => $chainId,
            'note' => 'X_BEARER_TOKEN gezet — volledige X API v2-stream is nog niet geïmplementeerd; gebruik Tavily zonder token of breid endpoint uit.',
            'ts' => gmdate('c'),
        ];
    }

    /**
     * @return array{enabled: bool, score: float, label: string, source: string, chain_id: int|null, note: string, ts: string}
     */
    private function fetchViaTavilyFallback(?int $chainId): array
    {
        $key = trim((string)$this->config->get('evolution.web_search.api_key', ''));
        if ($key === '') {
            return [
                'enabled' => true,
                'score' => 0.0,
                'label' => 'geen data',
                'source' => 'none',
                'chain_id' => $chainId,
                'note' => 'Geen X_BEARER_TOKEN en geen Tavily (evolution.web_search.api_key) — zet één van beide voor live social sentiment.',
                'ts' => gmdate('c'),
            ];
        }

        $q = 'Ethereum ETH crypto sentiment Twitter X today';
        $url = 'https://api.tavily.com/search';
        try {
            $body = json_encode([
                'api_key' => $key,
                'query' => $q,
                'max_results' => 5,
                'search_depth' => 'basic',
            ], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'enabled' => true,
                'score' => 0.0,
                'label' => 'fout',
                'source' => 'tavily_encode',
                'chain_id' => $chainId,
                'note' => 'JSON encode failed',
                'ts' => gmdate('c'),
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 12,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            return [
                'enabled' => true,
                'score' => 0.0,
                'label' => 'fout',
                'source' => 'tavily_error',
                'chain_id' => $chainId,
                'note' => 'Tavily-request mislukt',
                'ts' => gmdate('c'),
            ];
        }

        $j = json_decode($raw, true);
        $blob = '';
        if (is_array($j)) {
            foreach (($j['results'] ?? []) as $r) {
                if (is_array($r)) {
                    $blob .= ' ' . (string)($r['content'] ?? '') . ' ' . (string)($r['title'] ?? '');
                }
            }
        }
        $blob = strtolower($blob);
        $bull = substr_count($blob, 'bull') + substr_count($blob, 'rally') + substr_count($blob, 'upside');
        $bear = substr_count($blob, 'bear') + substr_count($blob, 'crash') + substr_count($blob, 'down');

        $score = 0.0;
        if ($bull + $bear > 0) {
            $score = ($bull - $bear) / ($bull + $bear);
            $score = max(-1.0, min(1.0, $score));
        }
        $label = $score > 0.15 ? 'bullish' : ($score < -0.15 ? 'bearish' : 'neutraal');

        return [
            'enabled' => true,
            'score' => round($score, 4),
            'label' => $label,
            'source' => 'tavily_x_fallback',
            'chain_id' => $chainId,
            'note' => 'Geaggregeerd uit web-zoekresultaten (X/Twitter-namen in snippets) — geen officiële X API.',
            'ts' => gmdate('c'),
        ];
    }
}
