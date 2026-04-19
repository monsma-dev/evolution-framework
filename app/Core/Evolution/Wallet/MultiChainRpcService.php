<?php

declare(strict_types=1);

namespace App\Core\Evolution\Wallet;

/**
 * MultiChainRpcService — JSON-RPC voor meerdere EVM-ketens (Base, Arbitrum, Optimism, …).
 *
 * Observer / trading default blijft Base (8453). Andere ketens via forChain('arbitrum') enz.
 *
 * RPC-URL's: marketing-domeinen (base.org, arbitrum.io, optimism.io) hosten geen JSON-RPC;
 * defaults gebruiken werkende openbare endpoints. Overschrijf via env per keten.
 */
class MultiChainRpcService
{
    private const TX_LOG_FILE = 'storage/evolution/wallet/tx_log.jsonl';
    private const TIMEOUT     = 8;

    /**
     * @var array<string, array{default_rpc: string, chain_id: int, env_rpc: list<string>, env_chain: list<string>}>
     */
    private const CHAINS = [
        // Base: uitsluitend canonical JSON-RPC (geen BASE_RPC_URL — voorkomt verkeerde endpoint / L1-mix).
        'base' => [
            'default_rpc' => 'https://mainnet.base.org',
            'chain_id'    => 8453,
            'env_rpc'     => [],
            'env_chain'   => ['BASE_CHAIN_ID'],
        ],
        'arbitrum' => [
            'default_rpc' => 'https://arb1.arbitrum.io/rpc',
            'chain_id'    => 42161,
            'env_rpc'     => ['ARBITRUM_RPC_URL'],
            'env_chain'   => ['ARBITRUM_CHAIN_ID'],
        ],
        'optimism' => [
            'default_rpc' => 'https://mainnet.optimism.io',
            'chain_id'    => 10,
            'env_rpc'     => ['OPTIMISM_RPC_URL'],
            'env_chain'   => ['OPTIMISM_CHAIN_ID'],
        ],
        'ethereum' => [
            'default_rpc' => 'https://ethereum.publicnode.com',
            'chain_id'    => 1,
            'env_rpc'     => ['ETH_MAINNET_RPC_URL', 'ETHEREUM_RPC_URL'],
            'env_chain'   => ['ETH_CHAIN_ID'],
        ],
    ];

    protected string $rpcUrl;
    private string $basePath;
    private int $chainId;
    private float $gasWarnEth;
    private string $chainKey;

    /** Laatste JSON-RPC error object (voor debug:rpc) */
    private ?array $lastJsonrpcError = null;

    private int $lastHttpStatus = 0;

    public function __construct(?string $basePath = null, ?string $rpcUrlOverride = null, string $chainKey = 'base')
    {
        $this->chainKey = strtolower($chainKey);
        if (!isset(self::CHAINS[$this->chainKey])) {
            $this->chainKey = 'base';
        }
        $def = self::CHAINS[$this->chainKey];

        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));

        // Base (trading): chain 8453; RPC vast mainnet.base.org.
        if ($this->chainKey === 'base') {
            $this->rpcUrl = $def['default_rpc'];
            $this->chainId = 8453;
        } elseif ($this->chainKey === 'ethereum') {
            $this->rpcUrl = $rpcUrlOverride ?? $this->firstEnvUrl($def['env_rpc']) ?? $def['default_rpc'];
            $cid = $this->firstEnvChainId($def['env_chain']);
            $this->chainId = $cid ?? $def['chain_id'];
        } else {
            $this->rpcUrl = $rpcUrlOverride ?? $this->firstEnvUrl($def['env_rpc']) ?? $def['default_rpc'];
            $cid = $this->firstEnvChainId($def['env_chain']);
            $this->chainId = $cid ?? $def['chain_id'];
        }

        $gw = getenv('GAS_WARN_ETH');
        if ($gw !== false && $gw !== '' && is_numeric($gw)) {
            $this->gasWarnEth = (float)$gw;
        } else {
            $this->gasWarnEth = match ($this->chainId) {
                8453, 42161, 10 => 0.00015,
                default => 0.001,
            };
        }
    }

    /**
     * Geregistreerde ketens en hun default JSON-RPC (niet de marketing-homepage URL).
     *
     * @return array<string, array{rpc: string, chain_id: int}>
     */
    public static function registry(): array
    {
        $out = [];
        foreach (self::CHAINS as $key => $def) {
            $out[$key] = [
                'rpc'       => $def['default_rpc'],
                'chain_id'  => $def['chain_id'],
            ];
        }
        return $out;
    }

    public static function forChain(string $chainKey, ?string $basePath = null): self
    {
        return new self($basePath, null, $chainKey);
    }

    public function getChainKey(): string
    {
        return $this->chainKey;
    }

    /** @param list<string> $keys */
    private function firstEnvUrl(array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '' && str_starts_with((string)$v, 'http')) {
                return (string)$v;
            }
        }
        return null;
    }

    /** @param list<string> $keys */
    private function firstEnvChainId(array $keys): ?int
    {
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '' && is_numeric($v)) {
                return (int)$v;
            }
        }
        return null;
    }

    public function getChainId(): int
    {
        return $this->chainId;
    }

    /** @return array<string, mixed>|null */
    public function lastJsonRpcError(): ?array
    {
        return $this->lastJsonrpcError;
    }

    public function lastHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    /** eth_chainId van de node (moet 0x2105 = 8453 voor Base mainnet). */
    public function ethChainIdFromNetwork(): ?int
    {
        $result = $this->call('eth_chainId', []);
        if ($result === null) {
            return null;
        }

        return (int) hexdec(ltrim((string) $result, '0x'));
    }

    public function getRpcUrl(): string
    {
        return $this->rpcUrl;
    }

    protected function normalizeEvmAddress(string $address): string
    {
        $a = strtolower(trim($address));
        if ($a === '') {
            return '';
        }
        if (!str_starts_with($a, '0x')) {
            $a = '0x' . $a;
        }

        return $a;
    }

    /** Returns ETH balance in ether (float). */
    public function getBalance(string $address): float
    {
        $addrRpc = $this->normalizeEvmAddress($address);
        if ($addrRpc === '') {
            return 0.0;
        }
        $result = $this->call('eth_getBalance', [$addrRpc, 'latest']);
        if ($result === null) {
            return 0.0;
        }
        $hexValue = $this->strip0x((string)$result);
        if ($hexValue === '' || $hexValue === '0') {
            return 0.0;
        }
        return $this->hexWeiToEth($hexValue);
    }

    public function getNonce(string $address): int
    {
        $addrRpc = $this->normalizeEvmAddress($address);
        if ($addrRpc === '') {
            return 0;
        }
        $result = $this->call('eth_getTransactionCount', [$addrRpc, 'latest']);
        return $result !== null ? (int)hexdec(ltrim((string)$result, '0x')) : 0;
    }

    public function gasPriceWei(): int
    {
        $result = $this->call('eth_gasPrice', []);
        if ($result === null) {
            return 1000000000;
        }
        $hex = ltrim($this->strip0x((string)$result), '0') ?: '0';
        return (int)$this->hexToFloat($hex);
    }

    public function sendRawTransaction(string $raw): string
    {
        $result = $this->call('eth_sendRawTransaction', [$raw]);
        if ($result === null) {
            throw new \RuntimeException('eth_sendRawTransaction failed — check RPC connection');
        }
        return (string)$result;
    }

    public function ethCall(string $contract, string $data, string $blockTag = 'latest'): ?string
    {
        $result = $this->call('eth_call', [['to' => $contract, 'data' => $data], $blockTag]);

        return $result !== null ? (string)$result : null;
    }

    public function blockNumber(): int
    {
        $result = $this->call('eth_blockNumber', []);
        if ($result === null) {
            return 0;
        }
        $hex = ltrim($this->strip0x((string)$result), '0') ?: '0';
        if (extension_loaded('gmp')) {
            return (int)gmp_strval(gmp_init($hex, 16), 10);
        }

        return (int)hexdec($hex);
    }

    public function gasPrice(): float
    {
        $result = $this->call('eth_gasPrice', []);
        if ($result === null) {
            return 0.0;
        }
        $hexValue = $this->strip0x((string)$result);
        if ($hexValue === '' || $hexValue === '0') {
            return 0.0;
        }
        return (float)($this->hexToFloat($hexValue) / 1e9);
    }

    public function getRecentIncoming(string $address, int $blocks = 20): array
    {
        $latestBlock = $this->blockNumber();
        if ($latestBlock === 0) {
            return [];
        }

        $addr     = $this->normalizeEvmAddress($address);
        $incoming = [];

        for ($b = $latestBlock; $b > max(0, $latestBlock - $blocks); $b--) {
            $block = $this->call('eth_getBlockByNumber', ['0x' . dechex($b), true]);
            if (!is_array($block) || empty($block['transactions'])) {
                continue;
            }
            foreach ($block['transactions'] as $tx) {
                if (strtolower((string)($tx['to'] ?? '')) === $addr && ($tx['value'] ?? '0x0') !== '0x0') {
                    $txValue = $this->strip0x((string)($tx['value'] ?? '0x0'));
                    if ($txValue === '' || $txValue === '0') {
                        continue;
                    }
                    $eth = $this->hexWeiToEth($txValue);
                    $incoming[] = [
                        'hash'      => $tx['hash'],
                        'from'      => $tx['from'],
                        'value_eth' => $eth,
                        'block'     => $b,
                        'timestamp' => hexdec($block['timestamp'] ?? '0'),
                    ];
                }
            }
            usleep(50_000);
        }

        return $incoming;
    }

    public function walletStatus(string $address): array
    {
        $balance  = $this->getBalance($address);
        $gasPrice = $this->gasPrice();
        $block    = $this->blockNumber();

        $gasCostEth   = ($gasPrice * 21000) / 1e9;
        $txAffordable = $gasCostEth > 0 ? (int)floor($balance / $gasCostEth) : 0;

        $netLabel = match ($this->chainId) {
            1 => 'Ethereum Mainnet',
            8453 => 'Base Mainnet',
            42161 => 'Arbitrum One',
            10 => 'Optimism',
            default => 'EVM chain ' . $this->chainId,
        };

        return [
            'address'        => $address,
            'balance_eth'    => round($balance, 8),
            'balance_usd'    => null,
            'gas_price_gwei' => round($gasPrice, 2),
            'gas_warn'       => $balance < $this->gasWarnEth,
            'gas_ok'         => $balance >= $this->gasWarnEth,
            'tx_affordable'  => $txAffordable,
            'block'          => $block,
            'network'        => $netLabel,
            'chain_id'       => $this->chainId,
            'chain_key'      => $this->chainKey,
            'rpc'            => $this->rpcUrl,
            'checked_at'     => time(),
        ];
    }

    public function pollNewTransactions(string $address, int $lookbackBlocks = 5): array
    {
        $recent  = $this->getRecentIncoming($address, $lookbackBlocks);
        $seen    = $this->loadSeenTxs();
        $newTxs  = [];

        foreach ($recent as $tx) {
            if (!isset($seen[$tx['hash']])) {
                $seen[$tx['hash']] = $tx['timestamp'];
                $newTxs[]          = $tx;
                $this->logTx($tx);
            }
        }

        $this->saveSeenTxs($seen);
        return $newTxs;
    }

    public function txHistory(int $limit = 50): array
    {
        $file = $this->basePath . '/' . self::TX_LOG_FILE;
        if (!is_file($file)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($file)));
        $items = [];
        foreach ($lines as $line) {
            $d = json_decode($line, true);
            if (is_array($d)) {
                $items[] = $d;
            }
        }
        return array_slice(array_reverse($items), 0, $limit);
    }

    protected function call(string $method, array $params): mixed
    {
        return $this->callAt($this->rpcUrl, $method, $params);
    }

    /**
     * JSON-RPC POST naar een expliciete endpoint (gebruikt door BaseRpcService fallback-lijst).
     */
    protected function callAt(string $rpcUrl, string $method, array $params): mixed
    {
        $this->lastJsonrpcError = null;
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => $params,
        ]);

        $ch = curl_init($rpcUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->lastHttpStatus = (int) $status;

        if ($status !== 200 || !is_string($resp)) {
            return null;
        }
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $this->lastJsonrpcError = $decoded['error'];

            return null;
        }

        return $decoded['result'] ?? null;
    }

    private function loadSeenTxs(): array
    {
        $file = $this->basePath . '/data/evolution/wallet/seen_txs.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveSeenTxs(array $seen): void
    {
        $file = $this->basePath . '/data/evolution/wallet/seen_txs.json';
        file_put_contents($file, json_encode($seen), LOCK_EX);
    }

    private function logTx(array $tx): void
    {
        $file = $this->basePath . '/' . self::TX_LOG_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $tx['logged_at'] = date('c');
        file_put_contents($file, json_encode($tx) . "\n", FILE_APPEND | LOCK_EX);
    }

    protected function hexWeiToEth(string $hex): float
    {
        $hex = ltrim($hex, '0') ?: '0';
        if ($hex === '0') {
            return 0.0;
        }
        if (!extension_loaded('gmp') && !extension_loaded('bcmath')) {
            return $this->hexWeiToEthLegacyFloat($hex);
        }
        $weiDec = $this->hexToDecimalString($hex);
        if ($weiDec === '0') {
            return 0.0;
        }
        if (extension_loaded('bcmath')) {
            return (float) bcdiv($weiDec, '1000000000000000000', 18);
        }

        return $this->hexWeiToEthLegacyFloat($hex);
    }

    /** Grotere wei-hex → decimaal string (gmp of bcmath), voor bcdiv. */
    private function hexToDecimalString(string $hex): string
    {
        if (extension_loaded('gmp')) {
            return gmp_strval(gmp_init($hex, 16), 10);
        }
        if (!extension_loaded('bcmath')) {
            return '0';
        }
        $dec = '0';
        foreach (str_split(strtolower($hex)) as $c) {
            if (!ctype_xdigit($c)) {
                continue;
            }
            $v = (int)hexdec($c);
            $dec = bcmul($dec, '16', 0);
            $dec = bcadd($dec, (string) $v, 0);
        }

        return $dec;
    }

    /** @deprecated Alleen als geen bcmath; kan precisie verliezen. */
    private function hexWeiToEthLegacyFloat(string $hex): float
    {
        $hex = ltrim($hex, '0') ?: '0';
        if (strlen($hex) <= 16) {
            return (float)hexdec($hex) / 1e18;
        }
        $split = strlen($hex) - 16;
        $high  = (float)hexdec(substr($hex, 0, $split));
        $low   = (float)hexdec(substr($hex, $split));
        return ($high * pow(2.0, 64) + $low) / 1e18;
    }

    private function hexToFloat(string $hex): float
    {
        $hex = ltrim($hex, '0') ?: '0';
        if (strlen($hex) <= 15) {
            return (float)hexdec($hex);
        }
        $split = strlen($hex) - 15;
        $high  = (float)hexdec(substr($hex, 0, $split));
        $low   = (float)hexdec(substr($hex, $split));
        return $high * pow(16.0, 15) + $low;
    }

    protected function strip0x(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }
}
