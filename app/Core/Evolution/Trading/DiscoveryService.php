<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * DiscoveryService — Scant de markt op kansen buiten ETH/EUR.
 *
 * Fase 1 (Nu):     Alleen ETH traden — discovery geeft suggesties maar voert niet uit.
 * Fase 2 (na €50 winst):  Blue Chip tokens (WBTC, LINK, UNI) worden bijgehouden.
 * Fase 3 (na €200 winst): Zelfstandig nieuwe tokens analyseren via de Judge Agent.
 *
 * Bronnen:
 *   - DEX Screener API: top Uniswap tokens op volume/trending
 *   - CoinGecko: trending coins + sentiment-score per coin
 *
 * Resultaat: array van kandidaten gesorteerd op score (sentiment × momentum).
 */
final class DiscoveryService
{
    private const DEX_API_TRENDING  = 'https://api.dexscreener.com/token-profiles/latest/v1';
    private const GCK_TRENDING      = 'https://api.coingecko.com/api/v3/search/trending';
    private const GCK_TOP           = 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=eur&order=volume_desc&per_page=50&page=1&sparkline=false&price_change_percentage=24h';
    private const CACHE_TTL         = 600;
    private const TIMEOUT           = 10;

    private const BLUE_CHIP_TOKENS  = ['WBTC', 'LINK', 'UNI', 'AAVE', 'MKR', 'SNX', 'CRV', 'LDO'];

    private string $cacheDir;
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->cacheDir  = $this->basePath . '/data/evolution/trading';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0750, true);
        }
    }

    /**
     * Geeft top-10 token-kandidaten terug gesorteerd op gecombineerde score.
     *
     * @param  string $phase  'phase1' | 'phase2' | 'phase3'
     * @return array<int, array{symbol: string, name: string, price_eur: float, change_24h: float, volume_eur: float, score: float, source: string}>
     */
    public function discover(string $phase = 'phase1'): array
    {
        $candidates = [];

        // ── CoinGecko top 50 op volume ────────────────────────────────────
        $topCoins = $this->fetchTopCoins();
        foreach ($topCoins as $coin) {
            $symbol    = strtoupper((string)($coin['symbol'] ?? ''));
            $change24h = (float)($coin['price_change_percentage_24h'] ?? 0);
            $volume    = (float)($coin['total_volume'] ?? 0);

            if ($phase === 'phase1' && $symbol !== 'ETH') {
                continue;
            }
            if ($phase === 'phase2' && !in_array($symbol, self::BLUE_CHIP_TOKENS, true) && $symbol !== 'ETH') {
                continue;
            }

            $score = $this->scoreCandidate($change24h, $volume, 0.0);
            $candidates[] = [
                'symbol'     => $symbol,
                'name'       => (string)($coin['name'] ?? $symbol),
                'price_eur'  => round((float)($coin['current_price'] ?? 0), 6),
                'change_24h' => round($change24h, 2),
                'volume_eur' => round($volume, 2),
                'score'      => round($score, 4),
                'source'     => 'coingecko_top50',
            ];
        }

        // ── CoinGecko trending ────────────────────────────────────────────
        if ($phase !== 'phase1') {
            $trending = $this->fetchTrendingCoins();
            foreach ($trending as $item) {
                $coin   = $item['item'] ?? [];
                $symbol = strtoupper((string)($coin['symbol'] ?? ''));
                if ($phase === 'phase2' && !in_array($symbol, self::BLUE_CHIP_TOKENS, true)) {
                    continue;
                }
                $candidates[] = [
                    'symbol'     => $symbol,
                    'name'       => (string)($coin['name'] ?? $symbol),
                    'price_eur'  => 0.0,
                    'change_24h' => 0.0,
                    'volume_eur' => 0.0,
                    'score'      => 0.5,
                    'source'     => 'coingecko_trending',
                ];
            }
        }

        // ── DEX Screener: nieuwste token-profielen (Fase 3) ───────────────
        if ($phase === 'phase3') {
            $dexTokens = $this->fetchDexScreenerTrending();
            foreach ($dexTokens as $t) {
                $candidates[] = [
                    'symbol'     => strtoupper((string)($t['tokenAddress'] ?? '?')),
                    'name'       => (string)($t['description'] ?? 'Unknown'),
                    'price_eur'  => 0.0,
                    'change_24h' => 0.0,
                    'volume_eur' => 0.0,
                    'score'      => 0.3,
                    'source'     => 'dexscreener',
                ];
            }
        }

        // Dedupliceer op symbol, sorteer op score desc, top 10
        $seen   = [];
        $unique = [];
        foreach ($candidates as $c) {
            if (!isset($seen[$c['symbol']])) {
                $seen[$c['symbol']] = true;
                $unique[] = $c;
            }
        }
        usort($unique, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($unique, 0, 10);
    }

    /**
     * Bepaalt de handels-fase op basis van gerealiseerde winst.
     */
    public function currentPhase(float $realizedPnlEur): string
    {
        if ($realizedPnlEur >= 200) {
            return 'phase3';
        }
        if ($realizedPnlEur >= 50) {
            return 'phase2';
        }
        return 'phase1';
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function fetchTopCoins(): array
    {
        $cacheFile = $this->cacheDir . '/discovery_top50.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && count($cached) > 5) {
                return $cached;
            }
        }
        $resp = $this->fetch(self::GCK_TOP);
        if (!$resp) {
            return [];
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return [];
        }
        file_put_contents($cacheFile, json_encode($data), LOCK_EX);
        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchTrendingCoins(): array
    {
        $cacheFile = $this->cacheDir . '/discovery_trending.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $resp = $this->fetch(self::GCK_TRENDING);
        if (!$resp) {
            return [];
        }
        $data  = json_decode($resp, true);
        $coins = (array)($data['coins'] ?? []);
        file_put_contents($cacheFile, json_encode($coins), LOCK_EX);
        return $coins;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchDexScreenerTrending(): array
    {
        $cacheFile = $this->cacheDir . '/discovery_dex.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $resp = $this->fetch(self::DEX_API_TRENDING);
        if (!$resp) {
            return [];
        }
        $data = json_decode($resp, true);
        $list = is_array($data) ? array_slice($data, 0, 20) : [];
        file_put_contents($cacheFile, json_encode($list), LOCK_EX);
        return $list;
    }

    private function scoreCandidate(float $change24h, float $volumeEur, float $sentimentBonus): float
    {
        $momentumScore  = min(1.0, max(0.0, ($change24h + 10) / 20));
        $volumeScore    = min(1.0, log10(max(1.0, $volumeEur / 1_000_000)) / 4);
        return ($momentumScore * 0.5) + ($volumeScore * 0.3) + ($sentimentBonus * 0.2);
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: EvolutionFramework/1.0'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($status === 200 && is_string($resp)) ? $resp : null;
    }
}
