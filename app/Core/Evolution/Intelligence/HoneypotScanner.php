<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

/**
 * HoneypotScanner — Bytecode + metadata analyse van Base L2 smart contracts.
 *
 * Detecteert:
 *   • Verborgen mint-functies (inflatie-aanval)
 *   • Blacklist/whitelist functies (uitsluitingsaanval)
 *   • Fee-manipulatiefuncties (rugpull via 99% fee)
 *   • Onverifieerbare bytecode (niet-geverifieerd = verdacht)
 *   • Proxy/upgradeable patterns (contract kan vervangen worden)
 *   • Honeypot patronen (kopen lukt, verkopen niet)
 *
 * Werking:
 *   1. `eth_getCode` via Base RPC → ruwe bytecode ophalen
 *   2. Scan op 4-byte function selectors (keccak256 prefixes)
 *   3. Basescan API → verificatiestatus + creation info
 *   4. Score 0–100 berekenen (hoger = gevaarlijker)
 *   5. Verdict: SAFE / SUSPICIOUS / SCAM
 *
 * Gebruik:
 *   $scanner = new HoneypotScanner($rpcUrl, $basescanApiKey);
 *   $result  = $scanner->scan('0x1234...abcd');
 *   if ($result['verdict'] === 'SCAM') { ... }
 *
 * Integratie: TradingValidatorAgent roept dit aan voor niet-ETH tokens.
 */
final class HoneypotScanner
{
    // ── Bekende gevaarlijke 4-byte function selectors ─────────────────────
    // Berekend met: keccak256("functionName(params)").substring(0, 8)
    private const DANGEROUS_SELECTORS = [
        '40c10f19' => ['name' => 'mint(address,uint256)',         'risk' => 90, 'label' => 'Verborgen mint-functie'],
        '9dc29fac' => ['name' => 'burn(address,uint256)',          'risk' => 40, 'label' => 'Owner burn (bearable)'],
        '8456cb59' => ['name' => 'pause()',                        'risk' => 70, 'label' => 'Pause-mechanisme'],
        '3f4ba83a' => ['name' => 'unpause()',                      'risk' => 30, 'label' => 'Unpause-mechanisme'],
        'f2fde38b' => ['name' => 'transferOwnership(address)',     'risk' => 50, 'label' => 'Eigendomsoverdracht'],
        '715018a6' => ['name' => 'renounceOwnership()',            'risk' => 20, 'label' => 'Eigendom opgeven (laag risico)'],
        'e47d6060' => ['name' => 'setFee(uint256)',                'risk' => 85, 'label' => 'Fee kan tot 100% worden ingesteld'],
        'b0e21e8a' => ['name' => 'setMaxTxAmount(uint256)',        'risk' => 75, 'label' => 'Maximale transactielimiet'],
        'dd62ed3e' => ['name' => 'allowance(address,address)',     'risk' =>  0, 'label' => 'Standaard ERC-20 (veilig)'],
        'a9059cbb' => ['name' => 'transfer(address,uint256)',      'risk' =>  0, 'label' => 'Standaard ERC-20 (veilig)'],
        '70a08231' => ['name' => 'balanceOf(address)',             'risk' =>  0, 'label' => 'Standaard ERC-20 (veilig)'],
        '18160ddd' => ['name' => 'totalSupply()',                  'risk' =>  0, 'label' => 'Standaard ERC-20 (veilig)'],
        '095ea7b3' => ['name' => 'approve(address,uint256)',       'risk' =>  0, 'label' => 'Standaard ERC-20 (veilig)'],
        '23b872dd' => ['name' => 'transferFrom(address,address,uint256)', 'risk' => 0, 'label' => 'Standaard ERC-20 (veilig)'],
        'f9f92be4' => ['name' => 'blacklist(address)',             'risk' => 95, 'label' => 'Blacklist-functie — kan wallets blokkeren'],
        '44337ea1' => ['name' => 'blacklistAddress(address)',      'risk' => 95, 'label' => 'Blacklist variant'],
        '537df3b6' => ['name' => 'excludeFromFees(address,bool)',  'risk' => 60, 'label' => 'Fee-exclusie (insiders voordeel)'],
        '5342acb4' => ['name' => 'excludeFromMaxTx(address,bool)', 'risk' => 60, 'label' => 'Tx-limiet exclusie (insiders)'],
        '3ccfd60b' => ['name' => 'withdraw()',                     'risk' => 80, 'label' => 'Owner kan liquidity opnemen'],
        '2e1a7d4d' => ['name' => 'withdraw(uint256)',              'risk' => 80, 'label' => 'Owner kan liquidity opnemen'],
        '1e89d545' => ['name' => 'setBuyFee(uint256,uint256)',     'risk' => 85, 'label' => 'Aanpasbare koop-fee'],
        'c0246668' => ['name' => 'excludeFromRewards(address,bool)', 'risk' => 55, 'label' => 'Reward-exclusie'],
    ];

    // Proxy/upgradeable patterns in bytecode (DELEGATECALL opcode = 0xf4)
    private const PROXY_OPCODE = 'f4'; // DELEGATECALL

    // Basescan Base API
    private const BASESCAN_API = 'https://api.basescan.org/api';

    private string $rpcUrl;
    private string $basescanApiKey;
    private string $cacheDir;

    public function __construct(
        string $rpcUrl = 'https://mainnet.base.org',
        string $basescanApiKey = '',
        string $cacheDir = ''
    ) {
        $this->rpcUrl         = $rpcUrl;
        $this->basescanApiKey = $basescanApiKey;
        $this->cacheDir       = $cacheDir ?: (defined('BASE_PATH')
            ? BASE_PATH . '/data/evolution/trading/honeypot_cache'
            : '/tmp/honeypot_cache');
    }

    /**
     * Scan een smart contract op honeypot- en scam-patronen.
     *
     * @return array{
     *   verdict:         string,    SAFE | SUSPICIOUS | SCAM
     *   score:           int,       0 (veilig) – 100 (gevaarlijk)
     *   verified:        bool,      Is de broncode geverifieerd op Basescan?
     *   dangerous_funcs: array,     Lijst van gevaarlijke functies gevonden
     *   reasons:         string[],  Menselijk leesbare risicofactoren
     *   contract_age_days: int,
     *   cached:          bool,
     * }
     */
    public function scan(string $contractAddress): array
    {
        $address  = strtolower(trim($contractAddress));
        $cacheKey = $address;

        // Cache: max 1 uur voor SAFE, onbeperkt voor SCAM
        $cached = $this->loadCache($cacheKey);
        if ($cached !== null) {
            return array_merge($cached, ['cached' => true]);
        }

        $reasons         = [];
        $dangerousFuncs  = [];
        $totalRisk       = 0;
        $ageWarning      = false;

        // ── 1. Bytecode ophalen ──────────────────────────────────────────
        $bytecode = $this->getCode($address);

        if ($bytecode === '0x' || $bytecode === '') {
            return $this->buildResult('SCAM', 100, false, [], ['Contract heeft geen bytecode (lege/verwijderde contract)'], 0);
        }

        $hex = strtolower(ltrim($bytecode, '0x'));

        // ── 2. Scan op gevaarlijke function selectors ────────────────────
        foreach (self::DANGEROUS_SELECTORS as $selector => $info) {
            if ($info['risk'] === 0) {
                continue;
            }
            if (str_contains($hex, $selector)) {
                $dangerousFuncs[] = ['selector' => $selector, 'name' => $info['name'], 'risk' => $info['risk'], 'label' => $info['label']];
                $totalRisk        = max($totalRisk, $info['risk']);
                $reasons[]        = '⚠️ ' . $info['label'];
            }
        }

        // ── 3. Proxy/DELEGATECALL detectie ──────────────────────────────
        if (str_contains($hex, self::PROXY_OPCODE)) {
            $occurrences = substr_count($hex, self::PROXY_OPCODE);
            if ($occurrences > 3) {
                $totalRisk = max($totalRisk, 65);
                $reasons[] = '🔄 Proxy/Upgradeable contract (' . $occurrences . ' DELEGATECALL opcodes) — kan vervangen worden';
            }
        }

        // ── 4. Basescan verificatie + leeftijd ───────────────────────────
        $basescanInfo    = $this->getBasescanInfo($address);
        $verified        = $basescanInfo['verified'] ?? false;
        $contractAgeDays = $basescanInfo['age_days'] ?? -1;

        if (!$verified) {
            $totalRisk += 30;
            $reasons[]  = '🔴 Broncode NIET geverifieerd op Basescan — grote rode vlag';
        }

        if ($contractAgeDays >= 0 && $contractAgeDays < 7) {
            $totalRisk += 20;
            $ageWarning = true;
            $reasons[]  = sprintf('🆕 Contract slechts %d dag(en) oud — verhoogd risico', $contractAgeDays);
        }

        // ── 5. Korte bytecode = waarschijnlijk proxy ─────────────────────
        if (strlen($hex) < 200) {
            $totalRisk += 40;
            $reasons[]  = '🔍 Zeer korte bytecode — waarschijnlijk minimal proxy (EIP-1167)';
        }

        // ── 6. Geen standaard ERC-20 functies = niet ERC-20 ─────────────
        $hasTransfer     = str_contains($hex, 'a9059cbb');
        $hasBalanceOf    = str_contains($hex, '70a08231');
        $hasApprove      = str_contains($hex, '095ea7b3');
        $standardFuncs   = (int)$hasTransfer + (int)$hasBalanceOf + (int)$hasApprove;

        if ($standardFuncs < 2) {
            $totalRisk += 25;
            $reasons[]  = '❓ Ontbrekende standaard ERC-20 functies — mogelijk geen geldig token';
        }

        // ── 7. Verdict bepalen ───────────────────────────────────────────
        $score   = min(100, $totalRisk);
        $verdict = $score >= 70 ? 'SCAM' : ($score >= 35 ? 'SUSPICIOUS' : 'SAFE');

        if (empty($reasons) && $verdict === 'SAFE') {
            $reasons[] = '✅ Geen bekende risicofactoren gevonden';
        }

        $result = $this->buildResult($verdict, $score, $verified, $dangerousFuncs, $reasons, $contractAgeDays);

        // Cache veilige contracten 1 uur, scams permanent
        $ttl = $verdict === 'SAFE' ? 3600 : 86400 * 30;
        $this->saveCache($cacheKey, $result, $ttl);

        return array_merge($result, ['cached' => false]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function getCode(string $address): string
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'eth_getCode',
            'params'  => [$address, 'latest'],
        ]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => 6,
            'ignore_errors' => true,
        ]]);

        $raw  = @file_get_contents($this->rpcUrl, false, $ctx);
        $data = $raw ? json_decode($raw, true) : null;

        return is_string($data['result'] ?? null) ? (string)$data['result'] : '';
    }

    /** @return array{verified: bool, age_days: int} */
    private function getBasescanInfo(string $address): array
    {
        if ($this->basescanApiKey === '') {
            return ['verified' => false, 'age_days' => -1];
        }

        $url = self::BASESCAN_API . '?' . http_build_query([
            'module'  => 'contract',
            'action'  => 'getsourcecode',
            'address' => $address,
            'apikey'  => $this->basescanApiKey,
        ]);

        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);

        if ($raw === false) {
            return ['verified' => false, 'age_days' => -1];
        }

        $data     = json_decode($raw, true);
        $result   = $data['result'][0] ?? [];
        $verified = !empty($result['SourceCode']);

        // Contract creation timestamp via txlist
        $ageDays = -1;
        $txUrl   = self::BASESCAN_API . '?' . http_build_query([
            'module'     => 'account',
            'action'     => 'txlist',
            'address'    => $address,
            'startblock' => 0,
            'endblock'   => 99999999,
            'page'       => 1,
            'offset'     => 1,
            'sort'       => 'asc',
            'apikey'     => $this->basescanApiKey,
        ]);

        $txRaw  = @file_get_contents($txUrl, false, $ctx);
        $txData = $txRaw ? json_decode($txRaw, true) : null;
        $txList = $txData['result'] ?? [];

        if (is_array($txList) && isset($txList[0]['timeStamp'])) {
            $ageDays = (int)floor((time() - (int)$txList[0]['timeStamp']) / 86400);
        }

        return ['verified' => $verified, 'age_days' => $ageDays];
    }

    /** @param array<string, mixed> $dangerousFuncs @param string[] $reasons */
    private function buildResult(
        string $verdict,
        int    $score,
        bool   $verified,
        array  $dangerousFuncs,
        array  $reasons,
        int    $ageDays
    ): array {
        return [
            'verdict'           => $verdict,
            'score'             => $score,
            'verified'          => $verified,
            'dangerous_funcs'   => $dangerousFuncs,
            'reasons'           => $reasons,
            'contract_age_days' => $ageDays,
            'ts'                => date('c'),
        ];
    }

    // ── Cache ─────────────────────────────────────────────────────────────

    private function loadCache(string $key): ?array
    {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        if ((int)($data['_expires'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $data['result'] ?? null;
    }

    private function saveCache(string $key, array $result, int $ttl): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        @file_put_contents($file, json_encode([
            '_expires' => time() + $ttl,
            'result'   => $result,
        ]), LOCK_EX);
    }
}
