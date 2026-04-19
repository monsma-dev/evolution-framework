<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * PriceFeedService — ETH/EUR spot + historie.
 *
 * Volgorde: CoinGecko → bij 0 of fout **Kraken public API** → laatst bekende prijs (persisted JSON, "DB-cache").
 * Geschikt voor EC2 waar CoinGecko soms rate-limit / blokkeert.
 */
final class PriceFeedService
{
    private const COINGECKO = 'https://api.coingecko.com/api/v3';
    private const KRAKEN    = 'https://api.kraken.com/0/public/Ticker';
    private const BINANCE   = 'https://api.binance.com/api/v3/ticker/price';
    private const CACHE_TTL = 60;
    private const TIMEOUT   = 10;

    private string $cacheDir;
    /** @var array<string, mixed> */
    private array $cfg;

    public function __construct(?string $basePath = null, array $priceFeedConfig = [])
    {
        $base           = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->cacheDir = $base . '/data/evolution/trading';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0750, true);
        }
        $this->cfg = $priceFeedConfig;
    }

    /** Current ETH price in EUR (cached 60s). Kraken fallback + last-known file if all APIs fail. */
    public function getCurrentPrice(string $coin = 'ethereum', string $vs = 'eur'): float
    {
        $cacheFile = $this->cacheDir . "/price_{$coin}_{$vs}.json";
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (isset($cached['price']) && (float)$cached['price'] > 0) {
                return (float)$cached['price'];
            }
        }

        $price = $this->fetchCoinGeckoSimple($coin, $vs);
        $src   = 'coingecko';

        if ($price <= 0) {
            $price = $this->fetchKrakenEthEur();
            $src   = 'kraken';
        }
        if ($price <= 0) {
            $price = $this->fetchBinanceEthEur();
            $src   = 'binance';
        }
        if ($price <= 0) {
            $price = $this->readLastKnownGoodPrice($coin, $vs);
            $src   = 'last_known';
        }

        $fallback = (float)($this->cfg['fallback_price_eur'] ?? 0.0);
        if ($price <= 0 && $fallback > 0) {
            $price = $fallback;
            $src   = 'config_fallback';
        }

        if ($price > 0) {
            file_put_contents($cacheFile, json_encode(['price' => $price, 'ts' => time(), 'source' => $src]), LOCK_EX);
            $this->persistLastKnownGood($coin, $vs, $price, $src);
        }

        return $price;
    }

    /**
     * Hourly price history — CoinGecko eerst; bij leeg resultaat: synthetisch uit spot (Kraken) voor scenario's.
     */
    public function getPriceHistory(string $coin = 'ethereum', string $vs = 'eur', int $days = 3): array
    {
        $cacheFile = $this->cacheDir . "/history_{$coin}_{$vs}_{$days}d.json";
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && count($cached) > 10) {
                return $cached;
            }
        }

        $url  = self::COINGECKO . "/coins/{$coin}/market_chart?vs_currency={$vs}&days={$days}&interval=hourly";
        $resp = $this->fetch($url);
        $prices = [];
        if ($resp) {
            $data = json_decode($resp, true);
            foreach (($data['prices'] ?? []) as $row) {
                if (is_array($row) && count($row) >= 2) {
                    $prices[] = ['ts' => (int)($row[0] / 1000), 'price' => (float)$row[1]];
                }
            }
        }

        if (count($prices) > 10) {
            file_put_contents($cacheFile, json_encode($prices), LOCK_EX);
            return $prices;
        }

        $spot = $this->getCurrentPrice($coin, $vs);
        if ($spot > 0) {
            return $this->syntheticHistoryFromSpot($spot, max(24, $days * 24));
        }

        return [];
    }

    /**
     * 15-minuten prijsgeschiedenis (laatste 24 uur).
     */
    public function get15mPriceHistory(string $coin = 'ethereum', string $vs = 'eur'): array
    {
        $cacheFile = $this->cacheDir . "/history15m_{$coin}_{$vs}.json";
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 120) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && count($cached) > 10) {
                return $cached;
            }
        }

        $url  = self::COINGECKO . "/coins/{$coin}/market_chart?vs_currency={$vs}&days=1&interval=5m";
        $resp = $this->fetch($url);
        $prices = [];
        if ($resp) {
            $data = json_decode($resp, true);
            foreach (($data['prices'] ?? []) as $row) {
                if (is_array($row) && count($row) >= 2) {
                    $prices[] = ['ts' => (int)($row[0] / 1000), 'price' => (float)$row[1]];
                }
            }
        }
        if (count($prices) > 10) {
            file_put_contents($cacheFile, json_encode($prices), LOCK_EX);
            return $prices;
        }

        $spot = $this->getCurrentPrice($coin, $vs);
        if ($spot > 0) {
            return $this->syntheticHistoryFromSpot($spot, 96);
        }

        return [];
    }

    /**
     * Get market stats: 24h change %, volume, market cap.
     */
    public function getMarketStats(string $coin = 'ethereum', string $vs = 'eur'): array
    {
        $url  = self::COINGECKO . "/coins/{$coin}?localization=false&tickers=false&community_data=false&developer_data=false";
        $resp = $this->fetch($url);
        if (!$resp) {
            $p = $this->getCurrentPrice($coin, $vs);
            return $p > 0 ? ['price' => $p, 'change_24h_pct' => 0.0, 'high_24h' => $p, 'low_24h' => $p, 'volume_24h' => 0.0, 'market_cap' => 0.0] : [];
        }
        $data = json_decode($resp, true);
        $md   = $data['market_data'] ?? [];
        return [
            'price'           => (float)($md['current_price'][$vs]   ?? 0),
            'change_24h_pct'  => round((float)($md['price_change_percentage_24h'] ?? 0), 2),
            'high_24h'        => (float)($md['high_24h'][$vs]         ?? 0),
            'low_24h'         => (float)($md['low_24h'][$vs]          ?? 0),
            'volume_24h'      => (float)($md['total_volume'][$vs]     ?? 0),
            'market_cap'      => (float)($md['market_cap'][$vs]       ?? 0),
        ];
    }

    private function fetchCoinGeckoSimple(string $coin, string $vs): float
    {
        $url  = self::COINGECKO . "/simple/price?ids={$coin}&vs_currencies={$vs}";
        $resp = $this->fetch($url);
        if (!$resp) {
            return 0.0;
        }
        $data = json_decode($resp, true);
        return (float)($data[$coin][$vs] ?? 0);
    }

    /** Kraken XETHZEUR — stabiel op AWS. */
    private function fetchKrakenEthEur(): float
    {
        $pair = (string)($this->cfg['kraken_pair'] ?? 'XETHZEUR');
        $url  = self::KRAKEN . '?pair=' . rawurlencode($pair);
        $resp = $this->fetch($url);
        if (!$resp) {
            return 0.0;
        }
        $data = json_decode($resp, true);
        foreach ($data['result'] ?? [] as $ticker) {
            if (is_array($ticker)) {
                return (float)($ticker['c'][0] ?? 0);
            }
        }
        return 0.0;
    }

    private function fetchBinanceEthEur(): float
    {
        $url  = self::BINANCE . '?symbol=ETHEUR';
        $resp = $this->fetch($url);
        if (!$resp) {
            return 0.0;
        }
        $data = json_decode($resp, true);
        return (float)($data['price'] ?? 0);
    }

    /** Persisted last good price (survives process restarts). */
    private function persistLastKnownGood(string $coin, string $vs, float $price, string $source): void
    {
        $file = $this->cacheDir . '/price_last_known.json';
        $row  = [
            'coin'      => $coin,
            'vs'        => $vs,
            'price'     => $price,
            'ts'        => time(),
            'source'    => $source,
            'saved_at'  => gmdate('c'),
        ];
        file_put_contents($file, json_encode($row, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function readLastKnownGoodPrice(string $coin, string $vs): float
    {
        $file = $this->cacheDir . '/price_last_known.json';
        if (!is_readable($file)) {
            return 0.0;
        }
        $raw = json_decode((string)file_get_contents($file), true);
        if (!is_array($raw)) {
            return 0.0;
        }
        $p = (float)($raw['price'] ?? 0);
        $ts = (int)($raw['ts'] ?? 0);
        $maxAge = max(3600, (int)($this->cfg['last_known_max_age_seconds'] ?? 604800));
        if ($p <= 0 || $ts <= 0 || (time() - $ts) > $maxAge) {
            return 0.0;
        }
        if (strtolower((string)($raw['vs'] ?? 'eur')) !== strtolower($vs)) {
            return 0.0;
        }
        if (($raw['coin'] ?? '') !== '' && ($raw['coin'] ?? '') !== $coin) {
            return 0.0;
        }

        return $p;
    }

    /**
     * @return list<array{ts: int, price: float}>
     */
    private function syntheticHistoryFromSpot(float $spot, int $points): array
    {
        $out   = [];
        $now   = time();
        $step  = 3600;
        $jitter = 0.002;
        for ($i = $points; $i >= 0; $i--) {
            $noise = 1.0 + (mt_rand(-1000, 1000) / 1_000_000.0) * $jitter * 100;
            $out[] = ['ts' => $now - $i * $step, 'price' => max(1.0, $spot * $noise)];
        }
        return $out;
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: EvolutionFramework/2.0 (price-feed; +https://github.com)',
            ],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // PHP 8.5+: curl_close() is deprecated (no-op since 8.0); let the handle go out of scope.
        return ($status === 200 && is_string($resp) && $resp !== '') ? $resp : null;
    }
}
