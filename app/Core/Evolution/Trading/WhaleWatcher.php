<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * WhaleWatcher — Scant grote walvis-transacties op Base netwerk.
 *
 * Gebruikt de Basescan API om recente grote ETH-overdrachten te detecteren.
 * Als grote walvissen netto aan het DUMPEN zijn in de laatste 10 minuten,
 * blokkeert de Validator een BUY-trade.
 *
 * Configuratie:
 *   BASESCAN_API_KEY env variabele (gratis tier: 5 req/s)
 *   OF trading.whale_watcher.api_key in evolution.json
 *
 * Graceful degradation: als geen API key → NEUTRAL verdict, trade gaat door.
 *
 * Basescan API: https://basescan.org/apis
 */
final class WhaleWatcher
{
    private const API_BASE     = 'https://api.basescan.org/api';
    private const MIN_VALUE_ETH = 5.0;   // Minimale transactie-waarde om als "walvis" te tellen
    private const WINDOW_SECS  = 600;    // Laatste 10 minuten
    private const CACHE_TTL    = 120;    // Cache 2 minuten (Basescan rate limit)
    private const CACHE_FILE   = 'storage/evolution/trading/whale_cache.json';
    private const WETH_ADDRESS = '0x4200000000000000000000000000000000000006';

    private string  $apiKey;
    private string  $basePath;
    private float   $minValueEth;

    public function __construct(?string $basePath = null, ?string $apiKey = null)
    {
        $this->basePath    = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->apiKey      = $apiKey
            ?? trim((string)(getenv('BASESCAN_API_KEY') ?: ''))
            ?: $this->readKeyFromConfig();
        $this->minValueEth = self::MIN_VALUE_ETH;
    }

    /**
     * Analyseer recente walvis-bewegingen op Base.
     *
     * @param  float $currentPriceEur  Huidige ETH/EUR voor EUR-conversie
     * @return array{ok: bool, verdict: 'BULLISH'|'BEARISH'|'NEUTRAL', buy_volume_eth: float,
     *               sell_volume_eth: float, net_eth: float, tx_count: int, reason: string}
     */
    public function analyze(float $currentPriceEur = 0.0): array
    {
        $neutral = [
            'ok'             => true,
            'verdict'        => 'NEUTRAL',
            'buy_volume_eth' => 0.0,
            'sell_volume_eth'=> 0.0,
            'net_eth'        => 0.0,
            'tx_count'       => 0,
            'reason'         => 'WhaleWatcher: geen API sleutel — NEUTRAL (trade gaat door)',
        ];

        if ($this->apiKey === '') {
            return $neutral;
        }

        // ── Check cache ───────────────────────────────────────────────────
        $cached = $this->loadCache();
        if ($cached !== null) {
            return $cached;
        }

        try {
            $txList = $this->fetchRecentLargeTx();
        } catch (\Throwable $e) {
            return array_merge($neutral, ['reason' => 'WhaleWatcher: API fout — ' . $e->getMessage()]);
        }

        if (empty($txList)) {
            $result = array_merge($neutral, ['reason' => 'WhaleWatcher: geen grote transacties gevonden']);
            $this->saveCache($result);
            return $result;
        }

        // ── Analyseer flows: in = BULLISH (walvissen kopen ETH), out = BEARISH ──
        $buyVolume  = 0.0; // ETH inkomend in grote wallets (accumulatie = bullish voor prijs)
        $sellVolume = 0.0; // ETH uitgaand (distributie = bearish voor prijs)
        $txCount    = 0;

        $cutoff = time() - self::WINDOW_SECS;
        foreach ($txList as $tx) {
            if ((int)($tx['timeStamp'] ?? 0) < $cutoff) {
                continue;
            }
            $valueEth = (float)($tx['value'] ?? 0) / 1e18;
            if ($valueEth < $this->minValueEth) {
                continue;
            }
            $txCount++;
            // Heuristiek: transacties naar DEX-contracten = dump (sell pressure)
            $to = strtolower((string)($tx['to'] ?? ''));
            if ($this->isDexContract($to)) {
                $sellVolume += $valueEth;
            } else {
                $buyVolume += $valueEth;
            }
        }

        $netEth  = $buyVolume - $sellVolume;
        $verdict = 'NEUTRAL';
        if ($sellVolume > $buyVolume * 1.5) {
            $verdict = 'BEARISH';
        } elseif ($buyVolume > $sellVolume * 1.2) {
            $verdict = 'BULLISH';
        }

        $priceStr = $currentPriceEur > 0
            ? sprintf(' (€%.0f/ETH)', $currentPriceEur)
            : '';

        $result = [
            'ok'              => $verdict !== 'BEARISH',
            'verdict'         => $verdict,
            'buy_volume_eth'  => round($buyVolume, 4),
            'sell_volume_eth' => round($sellVolume, 4),
            'net_eth'         => round($netEth, 4),
            'tx_count'        => $txCount,
            'reason'          => match ($verdict) {
                'BEARISH' => sprintf(
                    'WhaleWatcher ⛔ BEARISH: walvissen dumpen %.2f ETH%s in %ds — BUY geblokkeerd',
                    $sellVolume, $priceStr, self::WINDOW_SECS
                ),
                'BULLISH' => sprintf(
                    'WhaleWatcher ✅ BULLISH: walvissen accumuleren %.2f ETH%s — bevestigt BUY',
                    $buyVolume, $priceStr
                ),
                default => sprintf(
                    'WhaleWatcher 🟡 NEUTRAL: %d grote transacties, netto %.2f ETH%s',
                    $txCount, $netEth, $priceStr
                ),
            },
        ];

        $this->saveCache($result);
        return $result;
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    private function fetchRecentLargeTx(): array
    {
        $url = sprintf(
            '%s?module=account&action=txlist&address=%s&sort=desc&page=1&offset=50&apikey=%s',
            self::API_BASE,
            self::WETH_ADDRESS,
            rawurlencode($this->apiKey)
        );

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => 6,
            'ignore_errors' => true,
            'header'        => 'User-Agent: EvolutionTradingBot/1.0',
        ]]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('Basescan API niet bereikbaar');
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || ($json['status'] ?? '0') !== '1') {
            return [];
        }

        return (array)($json['result'] ?? []);
    }

    /** Bekende DEX/swap contract adressen op Base. */
    private function isDexContract(string $address): bool
    {
        static $dexContracts = [
            '0x4752ba5dbc23f44d87826276bf6fd6b1c372ad24', // Uniswap V2 Router
            '0x2626164c30d4238f701285227345450dd0d443fb', // Uniswap V3 Router
            '0x03af20bdaaffb4cc0a521796a223f7d85e2aac31', // Aerodrome
            '0x420dd381b31aef6683db6b902084cb0ffece3a39', // BaseSwap Router
        ];
        return in_array($address, $dexContracts, true);
    }

    private function loadCache(): ?array
    {
        $file = $this->basePath . '/' . self::CACHE_FILE;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || (time() - (int)($data['_cached_at'] ?? 0)) > self::CACHE_TTL) {
            return null;
        }
        unset($data['_cached_at']);
        return $data;
    }

    private function saveCache(array $data): void
    {
        $file = $this->basePath . '/' . self::CACHE_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $data['_cached_at'] = time();
        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    private function readKeyFromConfig(): string
    {
        $configFile = $this->basePath . '/config/evolution.json';
        if (!is_file($configFile)) {
            return '';
        }
        $config = json_decode((string)file_get_contents($configFile), true);
        return trim((string)(
            $config['trading']['agent_toolbox']['whale_watcher']['api_key']
            ?? $config['trading']['whale_watcher']['api_key']
            ?? ''
        ));
    }
}
