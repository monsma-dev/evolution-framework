<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * TokenValidator — Veiligheidscheck voor nieuwe ERC-20 tokens.
 *
 * Controles:
 *   1. Honeypot check  — kan het token wel verkocht worden?
 *   2. Liquidity check — minimaal EUR 100.000 in de pool?
 *   3. Pool Age check  — pool ouder dan 30 dagen?
 *
 * API bronnen (geen key nodig):
 *   - honeypot.is: gratis honeypot-detectie
 *   - DexScreener: liquiditeit en pool-leeftijd
 */
final class TokenValidator
{
    private const HONEYPOT_API    = 'https://api.honeypot.is/v2/IsHoneypot?address=%s&chainID=1';
    private const DEXSCREENER_API = 'https://api.dexscreener.com/latest/dex/tokens/%s';
    private const MIN_LIQUIDITY   = 100000.0;
    private const MIN_AGE_DAYS    = 30;
    private const TIMEOUT         = 10;

    /**
     * Voer alle veiligheidschecks uit op een token-adres.
     *
     * @param  string $tokenAddress ERC-20 contract adres (0x...)
     * @return array{safe: bool, checks: array, score: float, address: string, ts: string}
     */
    public function validate(string $tokenAddress): array
    {
        $tokenAddress = strtolower(trim($tokenAddress));
        $checks       = [];

        $checks['honeypot'] = $this->checkHoneypot($tokenAddress);

        $pairData            = $this->fetchDexScreenerPairs($tokenAddress);
        $checks['liquidity'] = $this->checkLiquidity($pairData);
        $checks['pool_age']  = $this->checkPoolAge($pairData);

        $critical  = ['honeypot', 'liquidity', 'pool_age'];
        $allPassed = true;
        foreach ($critical as $key) {
            if (!($checks[$key]['passed'] ?? false)) {
                $allPassed = false;
                break;
            }
        }

        $passedCount = count(array_filter($checks, fn($c) => $c['passed'] ?? false));
        $score       = round($passedCount / max(1, count($checks)), 2);

        return [
            'safe'    => $allPassed,
            'checks'  => $checks,
            'score'   => $score,
            'address' => $tokenAddress,
            'ts'      => date('c'),
        ];
    }

    /** Snel liquiditeitscheck — voor snelle pre-filter in DiscoveryService. */
    public function quickLiquidityCheck(string $tokenAddress): bool
    {
        $pairs = $this->fetchDexScreenerPairs(strtolower(trim($tokenAddress)));
        return ($this->checkLiquidity($pairs))['passed'];
    }

    // ── Private checks ────────────────────────────────────────────────────

    /** @return array{passed: bool, reason: string} */
    private function checkHoneypot(string $address): array
    {
        $url  = sprintf(self::HONEYPOT_API, urlencode($address));
        $resp = $this->fetch($url);

        if ($resp === null) {
            return ['passed' => true, 'reason' => 'Honeypot API niet bereikbaar (WARN — check overgeslagen)'];
        }

        $data       = json_decode($resp, true);
        $isHoneypot = (bool)($data['honeypotResult']['isHoneypot'] ?? false);
        $canSell    = (bool)($data['simulationResult']['canSell']   ?? true);
        $sellTax    = (float)($data['simulationResult']['sellTax']  ?? 0);

        if ($isHoneypot || !$canSell) {
            return ['passed' => false, 'reason' => 'HONEYPOT gedetecteerd — token kan niet verkocht worden'];
        }

        if ($sellTax > 10) {
            return ['passed' => false, 'reason' => sprintf('Sell tax te hoog: %.1f%% (max 10%%)', $sellTax)];
        }

        return ['passed' => true, 'reason' => sprintf('Geen honeypot, sell tax %.1f%%', $sellTax)];
    }

    /** @return array{passed: bool, reason: string} */
    private function checkLiquidity(array $pairData): array
    {
        if (empty($pairData)) {
            return ['passed' => false, 'reason' => 'Geen liquiditeitsdata gevonden op DexScreener'];
        }

        $maxLiquidity = 0.0;
        foreach ($pairData as $pair) {
            $liqUsd = (float)($pair['liquidity']['usd'] ?? 0);
            if ($liqUsd > $maxLiquidity) {
                $maxLiquidity = $liqUsd;
            }
        }

        $liqEur = $maxLiquidity * 0.93;

        if ($liqEur < self::MIN_LIQUIDITY) {
            return [
                'passed' => false,
                'reason' => sprintf('Liquiditeit te laag: EUR %.0f (minimum EUR %.0f)', $liqEur, self::MIN_LIQUIDITY),
            ];
        }

        return [
            'passed' => true,
            'reason' => sprintf('Liquiditeit OK: EUR %.0f', $liqEur),
        ];
    }

    /** @return array{passed: bool, reason: string} */
    private function checkPoolAge(array $pairData): array
    {
        if (empty($pairData)) {
            return ['passed' => false, 'reason' => 'Geen pool-data voor leeftijdscheck'];
        }

        $oldestPairCreated = PHP_INT_MAX;
        foreach ($pairData as $pair) {
            $createdAt = (int)($pair['pairCreatedAt'] ?? 0);
            if ($createdAt > 0 && $createdAt < $oldestPairCreated) {
                $oldestPairCreated = $createdAt;
            }
        }

        if ($oldestPairCreated === PHP_INT_MAX) {
            return ['passed' => true, 'reason' => 'Pool-leeftijd onbekend (WARN)'];
        }

        $ageMs   = (int)(microtime(true) * 1000) - $oldestPairCreated;
        $ageDays = $ageMs / (1000 * 86400);

        if ($ageDays < self::MIN_AGE_DAYS) {
            return [
                'passed' => false,
                'reason' => sprintf('Pool te nieuw: %.0f dagen (minimum %d dagen)', $ageDays, self::MIN_AGE_DAYS),
            ];
        }

        return [
            'passed' => true,
            'reason' => sprintf('Pool leeftijd OK: %.0f dagen', $ageDays),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchDexScreenerPairs(string $address): array
    {
        $url  = sprintf(self::DEXSCREENER_API, urlencode($address));
        $resp = $this->fetch($url);
        if (!$resp) {
            return [];
        }
        $data  = json_decode($resp, true);
        $pairs = $data['pairs'] ?? [];
        return is_array($pairs) ? $pairs : [];
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
