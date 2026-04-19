<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * OraclePriceGuard — Anti-manipulatie dual-source price feed.
 *
 * Haalt ETH/EUR prijzen op van twee onafhankelijke bronnen:
 *   Primair:    CoinGecko (rest API, geen key)
 *   Secundair:  Kraken    (rest API, geen key) of Binance
 *
 * Safety regel:
 *   Als het prijsverschil tussen de twee bronnen > max_divergence_pct (standaard 2%),
 *   wordt ALL trading bevroren met een "Price Divergence Alert".
 *   Dit voorkomt handelen op gemanipuleerde of foutieve prijsdata.
 *
 * Flash-crash detectie:
 *   Als de prijs meer dan flash_crash_pct (standaard 8%) daalt tov het 1u gemiddelde,
 *   wordt trading ook bevroren ("Flash Crash Alert").
 */
final class OraclePriceGuard
{
    private const COINGECKO  = 'https://api.coingecko.com/api/v3/simple/price?ids=ethereum&vs_currencies=eur';
    private const KRAKEN     = 'https://api.kraken.com/0/public/Ticker?pair=ETHEUR';
    private const BINANCE    = 'https://api.binance.com/api/v3/ticker/price?symbol=ETHEUR';
    private const CACHE_TTL  = 60;
    private const TIMEOUT    = 6;

    private string $cacheDir;
    private float  $maxDivergencePct;
    private float  $flashCrashPct;

    public function __construct(array $config = [], ?string $basePath = null)
    {
        $base             = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->cacheDir   = $base . '/data/evolution/trading';
        $this->maxDivergencePct = (float)($config['max_divergence_pct'] ?? 2.0);
        $this->flashCrashPct    = (float)($config['flash_crash_pct']    ?? 8.0);

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0750, true);
        }
    }

    /**
     * Haal de consensus prijs op en voer oracle checks uit.
     *
     * @return array{
     *   ok: bool,
     *   price: float,
     *   sources: array,
     *   divergence_pct: float,
     *   alert: string|null,
     *   alert_type: string|null
     * }
     */
    public function check(): array
    {
        $cg = $this->fetchCoinGecko();
        $kr = $this->fetchKraken();

        $sources = ['coingecko' => $cg, 'kraken' => $kr];

        // Als beide bronnen falen → bevriest handelen
        if ($cg <= 0 && $kr <= 0) {
            return $this->alert('PRICE_FEED_DOWN', 'Beide prijsbronnen onbereikbaar', 0.0, $sources);
        }

        // Als één bron faalt → gebruik de andere, maar geef waarschuwing
        if ($cg <= 0 || $kr <= 0) {
            $price = max($cg, $kr);
            $this->cachePrice($price);
            return ['ok' => true, 'price' => $price, 'sources' => $sources,
                    'divergence_pct' => 0.0, 'alert' => null, 'alert_type' => null,
                    'warning' => 'Eén prijsbron onbereikbaar — gebruik enkel beschikbare bron'];
        }

        // Divergentie check
        $divergence = abs($cg - $kr) / min($cg, $kr) * 100;
        $consensus  = ($cg + $kr) / 2;

        if ($divergence > $this->maxDivergencePct) {
            return $this->alert(
                'PRICE_DIVERGENCE',
                sprintf('Prijsverschil %.2f%% tussen CoinGecko (€%.2f) en Kraken (€%.2f) > limiet %.1f%%',
                    $divergence, $cg, $kr, $this->maxDivergencePct),
                $consensus, $sources, $divergence
            );
        }

        // Flash-crash detectie: vergelijk met 1u gecachte prijs
        $cached = $this->loadCachedPrice(3600);
        if ($cached > 0 && $consensus < $cached * (1 - $this->flashCrashPct / 100)) {
            $drop = (1 - $consensus / $cached) * 100;
            return $this->alert(
                'FLASH_CRASH',
                sprintf('Flash crash detectie: prijs daalde %.2f%% in 1u (€%.2f → €%.2f). Limiet %.1f%%',
                    $drop, $cached, $consensus, $this->flashCrashPct),
                $consensus, $sources, $divergence
            );
        }

        $this->cachePrice($consensus);

        return [
            'ok'             => true,
            'price'          => round($consensus, 2),
            'sources'        => $sources,
            'divergence_pct' => round($divergence, 3),
            'alert'          => null,
            'alert_type'     => null,
        ];
    }

    private function fetchCoinGecko(): float
    {
        $resp = $this->fetch(self::COINGECKO);
        if (!$resp) {
            return 0.0;
        }
        $data = json_decode($resp, true);
        return (float)($data['ethereum']['eur'] ?? 0);
    }

    private function fetchKraken(): float
    {
        $resp = $this->fetch(self::KRAKEN);
        if (!$resp) {
            return $this->fetchBinance();
        }
        $data = json_decode($resp, true);
        // Kraken: result.XETHZEUR.c[0] = last trade price
        foreach ($data['result'] ?? [] as $ticker) {
            return (float)($ticker['c'][0] ?? 0);
        }
        return 0.0;
    }

    private function fetchBinance(): float
    {
        $resp = $this->fetch(self::BINANCE);
        if (!$resp) {
            return 0.0;
        }
        $data = json_decode($resp, true);
        return (float)($data['price'] ?? 0);
    }

    private function cachePrice(float $price): void
    {
        $file = $this->cacheDir . '/oracle_price_history.json';
        $history = [];
        if (is_file($file)) {
            $history = json_decode((string)file_get_contents($file), true) ?? [];
        }
        $history[] = ['ts' => time(), 'price' => $price];
        // Bewaar maximaal 24 uur (max 1440 per minuut)
        $cutoff = time() - 86400;
        $history = array_values(array_filter($history, fn($h) => $h['ts'] >= $cutoff));
        file_put_contents($file, json_encode($history), LOCK_EX);
    }

    private function loadCachedPrice(int $maxAgeSec): float
    {
        $file = $this->cacheDir . '/oracle_price_history.json';
        if (!is_file($file)) {
            return 0.0;
        }
        $history = json_decode((string)file_get_contents($file), true) ?? [];
        $cutoff  = time() - $maxAgeSec;
        $old     = array_filter($history, fn($h) => $h['ts'] <= $cutoff);
        if (empty($old)) {
            return 0.0;
        }
        return (float)end($old)['price'];
    }

    private function alert(string $type, string $msg, float $price, array $sources, float $divergence = 0.0): array
    {
        $alert = ['ts' => date('c'), 'type' => $type, 'message' => $msg, 'price' => $price];
        file_put_contents($this->cacheDir . '/oracle_alerts.log', json_encode($alert) . "\n", FILE_APPEND | LOCK_EX);
        return [
            'ok'             => false,
            'price'          => $price,
            'sources'        => $sources,
            'divergence_pct' => round($divergence, 3),
            'alert'          => $msg,
            'alert_type'     => $type,
        ];
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: EvolutionFramework/1.0'],
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($status === 200 && is_string($resp)) ? $resp : null;
    }
}
