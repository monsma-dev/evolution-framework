<?php

declare(strict_types=1);

namespace App\Core\Evolution\Wallet;

/**
 * BaseRpcService — JSON-RPC client for the Base L2 network.
 *
 * Uses the public Base RPC endpoint (no API key needed for basic calls).
 * For production: set BASE_RPC_URL env var to a private Alchemy/Infura endpoint.
 *
 * Gas threshold: 0.001 ETH = sufficient for ~200 standard transfers on Base.
 */
final class BaseRpcService
{
    private const DEFAULT_RPC  = 'https://mainnet.base.org';
    private const CHAIN_ID     = 8453;
    private const GAS_WARN_ETH = 0.001; // Minimum ETH for gas warnings
    private const TX_LOG_FILE  = 'storage/evolution/wallet/tx_log.jsonl';
    private const TIMEOUT      = 8;

    private string $rpcUrl;
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->rpcUrl   = (string)(getenv('BASE_RPC_URL') ?: self::DEFAULT_RPC);
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 5));
    }

    /** Returns ETH balance in ether (float). */
    public function getBalance(string $address): float
    {
        $result = $this->call('eth_getBalance', [$address, 'latest']);
        if ($result === null) {
            return 0.0;
        }
        // Convert hex wei to ETH
        $wei = gmp_init(ltrim((string)$result, '0x'), 16);
        return (float)(gmp_strval($wei) / 1e18);
    }

    /** Returns the latest block number. */
    public function blockNumber(): int
    {
        $result = $this->call('eth_blockNumber', []);
        return $result !== null ? hexdec(ltrim((string)$result, '0x')) : 0;
    }

    /** Returns the gas price in Gwei. */
    public function gasPrice(): float
    {
        $result = $this->call('eth_gasPrice', []);
        if ($result === null) {
            return 0.0;
        }
        $wei = gmp_init(ltrim((string)$result, '0x'), 16);
        return (float)(gmp_strval($wei) / 1e9); // wei → gwei
    }

    /**
     * Returns recent transactions TO this address from block logs.
     * Uses eth_getBlockByNumber for last N blocks (light approach, no event indexer needed).
     */
    public function getRecentIncoming(string $address, int $blocks = 20): array
    {
        $latestBlock = $this->blockNumber();
        if ($latestBlock === 0) {
            return [];
        }

        $addr     = strtolower($address);
        $incoming = [];

        for ($b = $latestBlock; $b > max(0, $latestBlock - $blocks); $b--) {
            $block = $this->call('eth_getBlockByNumber', ['0x' . dechex($b), true]);
            if (!is_array($block) || empty($block['transactions'])) {
                continue;
            }
            foreach ($block['transactions'] as $tx) {
                if (strtolower((string)($tx['to'] ?? '')) === $addr && ($tx['value'] ?? '0x0') !== '0x0') {
                    $weiGmp = gmp_init(ltrim((string)$tx['value'], '0x'), 16);
                    $eth    = (float)(gmp_strval($weiGmp) / 1e18);
                    $incoming[] = [
                        'hash'      => $tx['hash'],
                        'from'      => $tx['from'],
                        'value_eth' => $eth,
                        'block'     => $b,
                        'timestamp' => hexdec($block['timestamp'] ?? '0'),
                    ];
                }
            }
            usleep(50_000); // 50ms between blocks to be polite to public RPC
        }

        return $incoming;
    }

    /** Full wallet status for dashboard. */
    public function walletStatus(string $address): array
    {
        $balance  = $this->getBalance($address);
        $gasPrice = $this->gasPrice();
        $block    = $this->blockNumber();

        // Estimate: one Base transfer costs ~21000 gas
        $gasCostEth   = ($gasPrice * 21000) / 1e9;
        $txAffordable = $gasCostEth > 0 ? (int)floor($balance / $gasCostEth) : 0;

        return [
            'address'        => $address,
            'balance_eth'    => round($balance, 8),
            'balance_usd'    => null, // filled by caller if ETH price known
            'gas_price_gwei' => round($gasPrice, 2),
            'gas_warn'       => $balance < self::GAS_WARN_ETH,
            'gas_ok'         => $balance >= self::GAS_WARN_ETH,
            'tx_affordable'  => $txAffordable,
            'block'          => $block,
            'network'        => 'Base Mainnet',
            'chain_id'       => self::CHAIN_ID,
            'rpc'            => $this->rpcUrl,
            'checked_at'     => time(),
        ];
    }

    /**
     * Poll for new transactions since last check.
     * Returns new incoming txs and updates the seen-tx log.
     */
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

    /** Returns logged transaction history. */
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

    private function call(string $method, array $params): mixed
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => $params,
        ]);

        $ch = curl_init($this->rpcUrl);
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

        if ($status !== 200 || !is_string($resp)) {
            return null;
        }
        $decoded = json_decode($resp, true);
        return $decoded['result'] ?? null;
    }

    private function loadSeenTxs(): array
    {
        $file = $this->basePath . '/storage/evolution/wallet/seen_txs.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveSeenTxs(array $seen): void
    {
        $file = $this->basePath . '/storage/evolution/wallet/seen_txs.json';
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
}
