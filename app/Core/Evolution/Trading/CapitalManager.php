<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Evolution\Affiliate\EntitySettingsManager;
use App\Core\Evolution\Wallet\AgentWallet;
use App\Core\Evolution\Wallet\BaseRpcService;
use App\Core\Evolution\Wallet\TradingWallet;
use Psr\Container\ContainerInterface;

/**
 * CapitalManager — Autonome geldstroom-service tussen drie entiteiten.
 *
 * Regels:
 *   API-Refill:   Agent Wallet < €15  → stuur €10 vanuit Trading Wallet (indien winstgevend).
 *   Profit Vault: trading-NAV op Base > vault_trigger_eur → stuur (EUR − vault_floor) naar vault
 *   (VAULT_WALLET_ADDRESS / VAULT_ADDRESS / trading.vault_address). Zelfde Base-netwerk als trader; saldo’s via BaseRpcService.
 *
 * Elke verplaatsing wordt gelogd als CourtRecord.
 * Werkelijke on-chain transfers vereisen Node.js + ethers (tools/eth-transfer-generic.js).
 */
final class CapitalManager
{
    private const LOG_FILE       = 'storage/evolution/trading/capital_transfers.jsonl';
    private const REFILL_TRIGGER = 15.0;
    private const REFILL_AMOUNT  = 10.0;
    private const VAULT_TRIGGER  = 100.0;
    private const VAULT_FLOOR    = 50.0;

    private float $vaultTriggerEur;
    private float $vaultFloorEur;

    private BaseRpcService        $rpc;
    private AgentWallet           $agentWallet;
    private TradingWallet         $tradingWallet;
    private TradingLedger         $ledger;
    private EntitySettingsManager $entity;
    private ?ContainerInterface   $container;
    private string                $basePath;
    private string                $vaultAddress;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null)
    {
        $this->container     = $container;
        $this->basePath      = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->rpc           = BaseRpcService::forTradingFromEvolutionJson($this->basePath);
        $this->agentWallet   = new AgentWallet($this->basePath);
        $this->tradingWallet = new TradingWallet($this->basePath);
        $this->ledger        = new TradingLedger($this->basePath);
        $this->entity        = new EntitySettingsManager($container, $this->basePath);
        $capital               = $this->loadTradingCapitalConfig();
        $this->vaultAddress    = $this->resolveVaultAddress();
        $this->vaultTriggerEur = (float)($capital['vault_trigger_eur'] ?? self::VAULT_TRIGGER);
        $this->vaultFloorEur   = (float)($capital['vault_floor_eur'] ?? self::VAULT_FLOOR);
    }

    /** trading.capital uit evolution.json (drempels). */
    private function loadTradingCapitalConfig(): array
    {
        $file = $this->basePath . '/config/evolution.json';
        if (!is_file($file)) {
            return [];
        }
        $cfg = json_decode((string) file_get_contents($file), true);
        if (!is_array($cfg)) {
            return [];
        }
        $trading = (array)($cfg['trading'] ?? []);

        return (array)($trading['capital'] ?? []);
    }

    /** TRADING_WALLET_ADDRESS env wint; anders trading_wallet_address in evolution.json. */
    private function tradingFundAddress(): string
    {
        $env = trim((string)(getenv('TRADING_WALLET_ADDRESS') ?: ''));
        if ($env !== '') {
            return $this->normAddr($env);
        }
        $file = $this->basePath . '/config/evolution.json';
        if (is_file($file)) {
            $cfg = json_decode((string) file_get_contents($file), true);
            if (is_array($cfg)) {
                $trading = (array)($cfg['trading'] ?? []);
                $a = trim((string)($trading['trading_wallet_address'] ?? ''));
                if ($a !== '') {
                    return $this->normAddr($a);
                }
            }
        }

        return '';
    }

    private function normAddr(string $addr): string
    {
        $a = trim($addr);
        if (!str_starts_with($a, '0x')) {
            $a = '0x' . $a;
        }
        $h = strtolower(substr($a, 2));
        if (strlen($h) !== 40 || !ctype_xdigit($h)) {
            return $a;
        }

        return '0x' . $h;
    }

    /** VAULT_WALLET_ADDRESS of VAULT_ADDRESS env; anders trading.vault_address in evolution.json. */
    private function resolveVaultAddress(): string
    {
        $env = trim((string)(getenv('VAULT_WALLET_ADDRESS') ?: getenv('VAULT_ADDRESS') ?: ''));
        if ($env !== '') {
            return $env;
        }
        $file = $this->basePath . '/config/evolution.json';
        if (!is_file($file)) {
            return '';
        }
        $cfg = json_decode((string) file_get_contents($file), true);
        if (!is_array($cfg)) {
            return '';
        }
        $trading = (array)($cfg['trading'] ?? []);

        return trim((string)(($trading['vault_address'] ?? '') ?: ''));
    }

    /**
     * Voer automatische geldstroom-checks uit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tick(float $ethPriceEur): array
    {
        $actions = [];

        if (!$this->agentWallet->exists() || !$this->tradingWallet->exists()) {
            return [['action' => 'SKIP', 'reason' => 'Wallets niet geconfigureerd']];
        }

        $agentInfo   = $this->agentWallet->load();
        $tradingInfo = $this->tradingWallet->load();
        $fundAddr    = $this->tradingFundAddress();

        $agentEth = $this->rpc->getBalance($agentInfo['address']);
        $tradingEth = $fundAddr !== ''
            ? $this->rpc->getBalance($fundAddr)
            : $this->rpc->getBalance($tradingInfo['address']);
        $agentEur   = $agentEth * $ethPriceEur;
        $tradingEur = $tradingEth * $ethPriceEur;

        /** On-chain transfers gebruiken het trading key-bestand; dat adres moet overeenkomen met trading_wallet_address. */
        $tradingFrom = $tradingInfo['address'];

        // ── Regel 1: API-Refill ──────────────────────────────────────────
        if ($agentEur < self::REFILL_TRIGGER && $tradingEur > self::REFILL_AMOUNT * 2) {
            $refillEth = self::REFILL_AMOUNT / max(1.0, $ethPriceEur);
            $actions[] = $this->transfer(
                'TRADING_TO_AGENT',
                $tradingFrom,
                $agentInfo['address'],
                $refillEth,
                $ethPriceEur,
                sprintf(
                    'API-Refill: agent wallet €%.2f < €%.0f drempel, stuur €%.0f vanuit trading wallet',
                    $agentEur, self::REFILL_TRIGGER, self::REFILL_AMOUNT
                )
            );
        }

        // ── Regel 2: Profit Vault ────────────────────────────────────────
        if ($tradingEur > $this->vaultTriggerEur && $this->vaultAddress !== '') {
            $sendEur  = $tradingEur - $this->vaultFloorEur;
            $sendEth  = $sendEur / max(1.0, $ethPriceEur);
            $actions[] = $this->transfer(
                'TRADING_TO_VAULT',
                $tradingFrom,
                $this->vaultAddress,
                $sendEth,
                $ethPriceEur,
                sprintf(
                    'Profit Vault: trading €%.2f > €%.0f drempel, stuur €%.2f naar vault (behoud €%.0f op trading)',
                    $tradingEur, $this->vaultTriggerEur, $sendEur, $this->vaultFloorEur
                )
            );
        }

        if (empty($actions)) {
            $actions[] = [
                'action'      => 'HOLD',
                'reason'      => sprintf('Balances OK — Agent: €%.2f | Trading: €%.2f', $agentEur, $tradingEur),
                'agent_eur'   => round($agentEur, 2),
                'trading_eur' => round($tradingEur, 2),
            ];
        }

        return $actions;
    }

    /**
     * Balans-overzicht van alle drie de entiteiten.
     *
     * @return array<string, mixed>
     */
    public function balanceSummary(float $ethPriceEur): array
    {
        $agentInfo   = $this->agentWallet->exists()   ? $this->agentWallet->load()   : [];
        $tradingInfo = $this->tradingWallet->exists() ? $this->tradingWallet->load() : [];
        $vaultAddr   = $this->vaultAddress;

        $agentEth = !empty($agentInfo) ? $this->rpc->getBalance((string)$agentInfo['address']) : 0.0;
        $fundAddr = $this->tradingFundAddress();
        if ($fundAddr !== '') {
            $tradingEth = $this->rpc->getBalance($fundAddr);
            $tradingAddrDisplay = $fundAddr;
        } else {
            $tradingEth = !empty($tradingInfo['address']) ? $this->rpc->getBalance((string)$tradingInfo['address']) : 0.0;
            $tradingAddrDisplay = (string)($tradingInfo['address'] ?? '');
        }
        $vaultEth = $vaultAddr !== '' ? $this->rpc->getBalance($this->normAddr($vaultAddr)) : 0.0;

        $pnl = $this->ledger->pnlSummary($ethPriceEur);

        return [
            'agent'   => [
                'address' => (string)($agentInfo['address']   ?? ''),
                'eth'     => round($agentEth, 6),
                'eur'     => round($agentEth * $ethPriceEur, 2),
            ],
            'trading' => [
                'address' => $tradingAddrDisplay,
                'eth'     => round($tradingEth, 6),
                'eur'     => round($tradingEth * $ethPriceEur, 2),
            ],
            'vault'   => [
                'address' => $vaultAddr,
                'eth'     => round($vaultEth, 6),
                'eur'     => round($vaultEth * $ethPriceEur, 2),
            ],
            'capital_rules' => [
                'vault_trigger_eur'  => $this->vaultTriggerEur,
                'vault_floor_eur'    => $this->vaultFloorEur,
                'vault_configured'   => $vaultAddr !== '',
                'refill_trigger_eur' => self::REFILL_TRIGGER,
                'refill_amount_eur'  => self::REFILL_AMOUNT,
            ],
            'vault_transferred_eur' => round($this->sumVaultTransfersEur(), 2),
            'total_profit_sent_eur' => round((float)($pnl['realised_pnl_eur'] ?? 0), 2),
            'eth_price_eur'         => round($ethPriceEur, 2),
            'ts'                    => date('c'),
        ];
    }

    /** Som van EUR die naar de vault is verplaatst (gelogde transfers). */
    private function sumVaultTransfersEur(): float
    {
        $path = $this->basePath . '/' . self::LOG_FILE;
        if (!is_file($path)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach (array_filter(explode("\n", (string)file_get_contents($path))) as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || ($row['type'] ?? '') !== 'TRADING_TO_VAULT') {
                continue;
            }
            $sum += (float)($row['amount_eur'] ?? 0);
        }

        return $sum;
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function transfer(
        string $type,
        string $fromAddress,
        string $toAddress,
        float  $amountEth,
        float  $priceEur,
        string $reason
    ): array {
        $record = [
            'ts'         => date('c'),
            'type'       => $type,
            'from'       => $fromAddress,
            'to'         => $toAddress,
            'amount_eth' => round($amountEth, 8),
            'amount_eur' => round($amountEth * $priceEur, 2),
            'reason'     => $reason,
            'status'     => 'PENDING',
            'tx_hash'    => null,
        ];

        $txHash = $this->executeTransfer($type, $toAddress, $amountEth);
        if ($txHash !== null) {
            $record['status']  = 'SENT';
            $record['tx_hash'] = $txHash;
        }

        $this->logTransfer($record);
        $this->createCourtRecord($record);

        return array_merge(['action' => $type], $record);
    }

    private function executeTransfer(string $type, string $to, float $amountEth): ?string
    {
        $passEnv = str_starts_with($type, 'TRADING') ? 'TRADING_WALLET_PASSPHRASE' : 'WALLET_PASSPHRASE';
        $passVal = (string)(getenv($passEnv) ?: '');
        if ($passVal === '') {
            return null;
        }

        $nodeScript = $this->basePath . '/tools/eth-transfer-generic.js';
        if (!is_file($nodeScript)) {
            return null;
        }

        $cmd = sprintf(
            '%s=%s node %s --to=%s --eth=%.8f 2>&1',
            escapeshellarg($passEnv),
            escapeshellarg($passVal),
            escapeshellarg($nodeScript),
            escapeshellarg($to),
            $amountEth
        );

        $output = (string)shell_exec($cmd);
        if (preg_match('/TX\s*:\s*(0x[0-9a-fA-F]{64})/i', $output, $m)) {
            return $m[1];
        }
        return null;
    }

    private function logTransfer(array $record): void
    {
        $dir = $this->basePath . '/data/evolution/trading';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents(
            $this->basePath . '/' . self::LOG_FILE,
            json_encode($record) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Registreer een "Real World" verkoop en reserveer 21% BTW automatisch
     * wanneer de entiteitsmodus 'bedrijf' is.
     *
     * @return array{reserved_eur: float, entity_mode: string, total_reserve_eur: float}
     */
    public function recordRealWorldSale(float $grossAmountEur, string $description = ''): array
    {
        $mode         = $this->entity->mode();
        $reservedEur  = $this->entity->addToTaxReserve($grossAmountEur);
        $totalReserve = $this->entity->getTaxReserveEur();

        $logEntry = [
            'ts'             => date('c'),
            'type'           => 'REAL_WORLD_SALE',
            'gross_eur'      => round($grossAmountEur, 2),
            'tax_reserved'   => round($reservedEur, 2),
            'entity_mode'    => $mode,
            'description'    => $description,
            'total_tax_reserve_eur' => round($totalReserve, 2),
        ];

        $this->logTransfer($logEntry);

        return [
            'reserved_eur'      => round($reservedEur, 2),
            'entity_mode'       => $mode,
            'total_reserve_eur' => round($totalReserve, 2),
        ];
    }

    /**
     * Overzicht van de fiscale BTW-reserve.
     *
     * @return array{entity_mode: string, is_bedrijf: bool, tax_reserve_eur: float, btw_rate_pct: int}
     */
    public function getTaxReserveSummary(): array
    {
        return [
            'entity_mode'    => $this->entity->mode(),
            'is_bedrijf'     => $this->entity->isBedrijf(),
            'tax_reserve_eur'=> $this->entity->getTaxReserveEur(),
            'btw_rate_pct'   => $this->entity->isBedrijf() ? 21 : 0,
        ];
    }

    private function createCourtRecord(array $transfer): void
    {
        try {
            (new TradingCourtRecord($this->container, $this->basePath))->record(
                ['id' => uniqid('cm_', true), 'type' => 'CAPITAL_TRANSFER', 'iso' => date('c'), 'side' => $transfer['type']],
                ['signal' => 'CAPITAL_MOVE', 'reason' => $transfer['reason'], 'strength' => 100, 'rsi' => 0.0],
                ['ok' => true, 'price' => 0],
                ['verdict' => 'APPROVE', 'reason' => 'CapitalManager automatische verplaatsing', 'heuristics' => [], 'ai_used' => false, 'cost_eur' => 0.0],
                ['approved' => true, 'verdict' => 'capital_manager', 'votes' => [], 'cost_eur' => 0.0]
            );
        } catch (\Throwable) {
        }
    }
}
