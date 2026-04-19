<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Container as AppContainer;
use App\Core\Evolution\Wallet\AgentWallet;
use App\Core\Evolution\Wallet\BaseRpcService;
use App\Core\Evolution\Wallet\MultiChainRpcService;
use App\Core\Evolution\Wallet\TradingWallet;
use App\Domain\Web\Models\EvolutionClientModel;
use Psr\Container\ContainerInterface;

/**
 * TradingService — Autonomous ETH day trading engine.
 *
 * Safety layers (deepest-to-shallowest):
 *   1. Circuit Breaker  — auto-pauze bij >10% verlies in 1u (TradingCircuitBreaker)
 *   2. Governance       — Trias Politica: regels + slippage + AI consensus 2/3 (TradingGovernance)
 *   3. Separate wallet  — TRADING_WALLET_ADDRESS (nooit de API-budget wallet)
 *   4. ETH reserve      — min_eth_reserve altijd bewaard voor gas
 *
 * Modes:
 *   paper_mode = true  → Simuleer trades, bijhoud virtueel P&L (VEILIG STANDAARD)
 *   paper_mode = false → Echte Uniswap v2 on-chain swaps (ECHT GELD)
 *
 * Enable real trading in evolution.json:
 *   "trading": { "enabled": true, "paper_mode": false, "evm": { ... } }
 * Default chain: Base (8453). Swaps: Uniswap V2 Router02 — zelfde ABI als mainnet-V2 (geen V3-router zonder aparte encoder).
 * Requires: composer require kornrunner/keccak web3p/ethereum-tx, ext-gmp.
 * Live signing: als trading_wallet_address gelijk is aan het agent-adres (server wallet), wordt AgentWallet gebruikt
 * (geen apart trading_key nodig). Anders: trading_wallet.json moet hetzelfde adres hebben als config.
 */
final class TradingService
{
    private const USDC_DECIMALS = 6;

    /** Base Mainnet defaults — override via trading.evm in evolution.json. */
    private const DEFAULT_CHAIN_ID          = 8453;
    private const DEFAULT_UNISWAP_V2_ROUTER = '0x4752ba5DBc23f44D87826276BF6Fd6b1C372aD24';
    private const DEFAULT_WETH              = '0x4200000000000000000000000000000000000006';
    private const DEFAULT_USDC              = '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';

    /** Ethereum L1 mainnet — Uniswap V2 + canonical tokens. */
    private const MAINNET_UNISWAP_V2_ROUTER = '0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D';
    private const MAINNET_WETH              = '0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2';
    private const MAINNET_USDC              = '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48';

    private PriceFeedService    $priceFeed;
    private TradingStrategy     $strategy;
    private TradingLedger       $ledger;
    private TradingCircuitBreaker $circuitBreaker;
    private MultiChainRpcService $rpc;
    private AgentWallet         $wallet;
    private TradingWallet       $tradingWallet;
    private ?ContainerInterface $container;

    private bool   $enabled;
    private bool   $paperMode;
    private float  $minEthReserve;
    private float  $maxTradePct;
    private float  $dailyLossLimitPct;
    private int    $minStrength;
    private string $basePath;
    private array  $strategyConfig;
    private array  $governanceCfg;
    private string $tradingWalletAddress;

    /** EUR value assumed per 1 USDC stablecoin (voor min ETH-out schatting bij live BUY). */
    private float $eurPerUsdc;

    private int    $chainId;
    private string $routerV2;
    private string $weth;
    private string $usdc;
    /** Min output factor, e.g. 0.97 for 3% slippage (300 bps). */
    private float $slippageMultiplier;

    /** Fractie van beschikbare USDC/EUR per trade; default = max_trade_pct als niet gezet. */
    private float $positionSizePct;

    /** trading.validator uit evolution.json (Sonnet-tier, Haiku, drempels). */
    private array $validatorCfg;

    /** trading.evm uit config — nodig om chain te wisselen (L1 ↔ Base). */
    private array $evmConfig = [];

    /** chain_id uit evolution/.env vóór saldo-gebaseerde auto-switch. */
    private int $configuredFallbackChainId = self::DEFAULT_CHAIN_ID;

    /** Laatste geprobed saldi (zelfde TRADING_WALLET-adres op beide RPC’s). */
    private float $probeBalanceEthereum = 0.0;

    private float $probeBalanceBase = 0.0;

    /** Multi-client: isolated ledger + optional runtime signer + performance fee %. */
    private ?int $clientId = null;

    private ?string $clientPrivKeyHex = null;

    private float $performanceFeePct = 0.0;

    public function __construct(array $config = [], ?string $basePath = null, ?ContainerInterface $container = null, ?int $clientId = null)
    {
        $this->basePath   = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->container  = $container;
        $this->clientId   = $clientId;
        $cfg              = $config['trading'] ?? $config;

        $evm = (array)($cfg['evm'] ?? []);
        $this->evmConfig = $evm;
        $cid = $evm['chain_id'] ?? getenv('ETH_CHAIN_ID') ?: getenv('BASE_CHAIN_ID');
        $initial = is_numeric($cid) ? (int)$cid : self::DEFAULT_CHAIN_ID;
        if ($initial <= 0) {
            $initial = self::DEFAULT_CHAIN_ID;
        }
        $this->configuredFallbackChainId = $initial;
        $bps = (int)($evm['slippage_bps'] ?? 300);
        $this->slippageMultiplier = max(0.5, min(1.0, 1.0 - ($bps / 10000.0)));

        $this->installEvmBindings($initial);

        $this->enabled             = (bool)($cfg['enabled']             ?? true);
        $this->paperMode           = (bool)($cfg['paper_mode']          ?? true);
        $this->minEthReserve       = (float)($cfg['min_eth_reserve']    ?? 0.005);
        $this->maxTradePct         = (float)($cfg['max_trade_pct']      ?? 0.25);
        $this->positionSizePct     = (float)($cfg['position_size_pct'] ?? 0);
        if ($this->positionSizePct <= 0 || $this->positionSizePct > 1.0) {
            $this->positionSizePct = $this->maxTradePct;
        }
        $this->validatorCfg        = (array)($cfg['validator'] ?? []);
        $this->dailyLossLimitPct   = (float)($cfg['daily_loss_limit_pct'] ?? 8.0);
        $this->minStrength         = (int)($cfg['min_signal_strength']  ?? 30);
        $this->strategyConfig      = (array)($cfg['strategy']           ?? []);
        $this->governanceCfg       = (array)($cfg['governance']         ?? []);
        $this->tradingWalletAddress= trim((string)($cfg['trading_wallet_address']
            ?? getenv('TRADING_WALLET_ADDRESS')
            ?: ''));

        $eurPu = $cfg['eur_per_usdc'] ?? getenv('TRADING_EUR_PER_USDC');
        $this->eurPerUsdc = is_numeric($eurPu) ? (float)$eurPu : 0.92;

        $this->priceFeed      = new PriceFeedService($this->basePath, (array)($cfg['price_feed'] ?? []));
        $this->strategy       = new TradingStrategy($this->strategyConfig, $this->basePath);
        $this->ledger         = new TradingLedger($this->basePath, $this->clientId);
        $this->circuitBreaker = new TradingCircuitBreaker($cfg['circuit_breaker'] ?? [], $this->basePath);
        $this->wallet        = new AgentWallet($this->basePath);
        $this->tradingWallet = new TradingWallet($this->basePath);
    }

    /** Decrypted 64-hex private key for this client's trading_wallet_address (memory only). */
    public function setClientRuntimeSigner(?string $privKeyHex): void
    {
        if ($privKeyHex === null || $privKeyHex === '') {
            $this->clientPrivKeyHex = null;

            return;
        }
        $this->clientPrivKeyHex = strtolower(preg_replace('/^0x/i', '', trim($privKeyHex)));
    }

    public function setPerformanceFeePct(float $pct): void
    {
        $this->performanceFeePct = max(0.0, min(100.0, $pct));
    }

    public function clientId(): ?int
    {
        return $this->clientId;
    }

    /** Router/WETH/USDC + JSON-RPC voor één EVM-keten (1 of 8453). */
    private function installEvmBindings(int $chainId): void
    {
        if ($chainId !== 1 && $chainId !== 8453) {
            $chainId = self::DEFAULT_CHAIN_ID;
        }
        $evm            = $this->evmConfig;
        $isEthMainnet   = $chainId === 1;
        $this->chainId  = $chainId;
        $this->routerV2 = $this->normAddr((string)($evm['uniswap_v2_router'] ?? (
            $isEthMainnet ? self::MAINNET_UNISWAP_V2_ROUTER : self::DEFAULT_UNISWAP_V2_ROUTER
        )));
        $this->weth = $this->normAddr((string)($evm['weth'] ?? (
            $isEthMainnet ? self::MAINNET_WETH : self::DEFAULT_WETH
        )));
        $this->usdc = $this->normAddr((string)($evm['usdc'] ?? (
            $isEthMainnet ? self::MAINNET_USDC : self::DEFAULT_USDC
        )));
        $ethRpcOverride = null;
        if ($isEthMainnet) {
            $ru = trim((string)($evm['rpc_url'] ?? ''));
            if ($ru !== '' && str_starts_with($ru, 'http')) {
                $ethRpcOverride = $ru;
            }
        }
        $this->rpc = $isEthMainnet
            ? new MultiChainRpcService($this->basePath, $ethRpcOverride, 'ethereum')
            : BaseRpcService::forTrading($this->basePath);
    }

    /**
     * Prioriteit: saldo op Ethereum L1 → trade L1; anders saldo op Base → Base; anders configured fallback.
     */
    private function refreshChainByBalances(): void
    {
        try {
            $addr = '';
            if ($this->tradingWalletAddress !== '') {
                $addr = $this->normAddr($this->tradingWalletAddress);
            } elseif ($this->tradingWallet->exists()) {
                $addr = $this->normAddr((string)($this->tradingWallet->load()['address'] ?? ''));
            }
            if ($addr === '') {
                return;
            }
            $ethProbe = new MultiChainRpcService($this->basePath, null, 'ethereum');
            $baseProbe = BaseRpcService::forTrading($this->basePath);
            $this->probeBalanceEthereum = $ethProbe->getBalance($addr);
            $this->probeBalanceBase     = $baseProbe->getBalance($addr);

            $pick = $this->configuredFallbackChainId;
            if ($this->probeBalanceEthereum >= 1e-18) {
                $pick = 1;
            } elseif ($this->probeBalanceBase >= 1e-18) {
                $pick = 8453;
            }
            if ($pick !== $this->chainId) {
                $this->installEvmBindings($pick);
            }
        } catch (\Throwable) {
            // laat huidige keten staan
        }
    }

    private function normAddr(string $addr): string
    {
        $a = trim($addr);
        if (!str_starts_with($a, '0x')) {
            $a = '0x' . $a;
        }
        $h = strtolower(substr($a, 2));
        if (strlen($h) !== 40 || !ctype_xdigit($h)) {
            throw new \InvalidArgumentException('Invalid EVM address in trading.evm config');
        }

        return '0x' . $h;
    }

    /**
     * @return array{ok: bool, wallet_info?: array, priv_key?: string, error?: string}
     */
    private function resolveSignerForLiveTrading(): array
    {
        if ($this->clientPrivKeyHex !== null && strlen($this->clientPrivKeyHex) === 64 && ctype_xdigit($this->clientPrivKeyHex)) {
            try {
                $tw   = new TradingWallet($this->basePath);
                $addr = $tw->deriveAddressFromPrivateKeyHex($this->clientPrivKeyHex);
                $fund = $this->tradingWalletAddress !== '' ? $this->normAddr($this->tradingWalletAddress) : '';
                if ($fund !== '' && strcasecmp($addr, $fund) !== 0) {
                    return ['ok' => false, 'error' => 'Client private key does not match trading_wallet_address'];
                }

                return [
                    'ok' => true,
                    'wallet_info' => ['address' => $addr],
                    'priv_key'    => $this->clientPrivKeyHex,
                ];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Client signer: ' . $e->getMessage()];
            }
        }

        if (!$this->wallet->exists()) {
            return ['ok' => false, 'error' => 'Agent wallet ontbreekt (key.enc)'];
        }
        $agentAddr = $this->normAddr($this->wallet->load()['address']);
        $fundAddr  = $this->tradingWalletAddress !== '' ? $this->normAddr($this->tradingWalletAddress) : '';

        if ($fundAddr !== '' && strcasecmp($fundAddr, $agentAddr) === 0) {
            return [
                'ok' => true,
                'wallet_info' => $this->wallet->load(),
                'priv_key'    => $this->wallet->decryptPrivateKey(),
            ];
        }

        if ($this->tradingWallet->exists()) {
            $tw     = $this->tradingWallet->load();
            $twAddr = $this->normAddr((string)($tw['address'] ?? ''));
            if ($fundAddr === '' || strcasecmp($fundAddr, $twAddr) === 0) {
                return [
                    'ok' => true,
                    'wallet_info' => $tw,
                    'priv_key'    => $this->tradingWallet->decryptPrivateKey(),
                ];
            }

            return [
                'ok' => false,
                'error' => sprintf(
                    'trading_wallet_address (%s) wijkt af van trading_wallet.json (%s). Pas config aan of importeer key.',
                    $fundAddr,
                    $twAddr
                ),
            ];
        }

        if ($fundAddr === '') {
            return [
                'ok' => true,
                'wallet_info' => $this->wallet->load(),
                'priv_key'    => $this->wallet->decryptPrivateKey(),
            ];
        }

        return [
            'ok' => false,
            'error' => 'Geen trading_wallet.json en trading_wallet_address wijkt af van agent-adres — zet trading_wallet_address gelijk aan het agent-adres of importeer een trading key.',
        ];
    }

    /** Effectieve positiefractie: position_size_pct of anders max_trade_pct. */
    private function tradeFraction(): float
    {
        $p = $this->positionSizePct;

        return ($p > 0 && $p <= 1.0) ? $p : $this->maxTradePct;
    }

    /** Agent + trading wallet ETH × spotprijs (EUR) — voor TradingGovernance::canBridge / bridge-guard. */
    private function writeNavSnapshotEur(float $priceEur): void
    {
        $agentEth = $this->wallet->exists() ? $this->rpc->getBalance($this->wallet->load()['address']) : 0.0;
        $tradingEth = 0.0;
        if ($this->tradingWalletAddress !== '') {
            $tradingEth = $this->rpc->getBalance($this->normAddr($this->tradingWalletAddress));
        } elseif ($this->tradingWallet->exists()) {
            $tradingEth = $this->rpc->getBalance($this->tradingWallet->load()['address']);
        }
        $totalEur = ($agentEth + $tradingEth) * max(0.0, $priceEur);
        $dir      = $this->basePath . '/data/evolution/trading';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tradingNavEur = $tradingEth * max(0.0, $priceEur);
        file_put_contents(
            $dir . '/nav_snapshot.json',
            json_encode([
                'total_nav_eur'   => round($totalEur, 2),
                'trading_nav_eur' => round($tradingNavEur, 2),
                'updated_at'      => date('c'),
                'agent_eth'       => $agentEth,
                'trading_eth'     => $tradingEth,
                'price_eur'       => $priceEur,
            ], JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * Context voor Telegram bij evolve:trade run — Base, tick-walletbalans, sentiment.
     *
     * @return array{network: string, chain_id: int, balance_eth: float, sentiment: float, sentiment_ok: bool}
     */
    public function getRunNotificationContext(): array
    {
        $this->refreshChainByBalances();
        if ($this->tradingWalletAddress !== '') {
            $addr = $this->normAddr($this->tradingWalletAddress);
        } elseif ($this->tradingWallet->exists()) {
            $addr = $this->tradingWallet->load()['address'];
        } else {
            $addr = $this->wallet->exists() ? $this->wallet->load()['address'] : '';
        }
        $eth = $addr !== '' ? $this->rpc->getBalance($addr) : 0.0;
        $sent = (new SentimentAnalyzer($this->container, $this->basePath))->currentSentiment();

        $st = $this->status();

        return [
            'network'      => $this->chainId === 1 ? 'Ethereum' : 'Base',
            'chain_id'     => $this->chainId,
            'balance_eth'  => $eth,
            'sentiment'    => (float)($sent['score'] ?? 0.0),
            'sentiment_ok' => array_key_exists('score', $sent),
            'performance'  => $st['performance'] ?? [],
            'primary_eur_estimate' => $st['primary_eur_estimate'] ?? null,
        ];
    }

    /**
     * Main trading tick — called every N minutes via cron / evolution worker.
     * Returns what was decided and why.
     */
    /**
     * @param bool $force Admin/CLI: sla circuit breaker, flash-crash lock, min-signaal en sentiment-BUY-blok over (geen gas-risico overslaan).
     */
    public function tick(bool $force = false): array
    {
        $this->refreshChainByBalances();
        if (!$this->enabled) {
            return ['action' => 'SKIP', 'reason' => 'Trading disabled in config'];
        }

        // ── Safety laag 0: Dead Man's Switch (AgentEstate) ───────────────
        try {
            $estate = (new \App\Core\Evolution\Intelligence\AgentEstate($this->basePath))->checkAndAct();
            if (in_array($estate['action'], ['estate_executed', 'alarm_sent'], true)) {
                if ($estate['action'] === 'estate_executed') {
                    return ['action' => 'ESTATE_PROTOCOL', 'reason' => 'Estate Protocol geactiveerd: ' . $estate['reason']];
                }
            }
        } catch (\Throwable) {
        }

        // ── Safety laag 1: Circuit Breaker ────────────────────────────────
        if (!$force && $this->circuitBreaker->isPaused()) {
            return [
                'action' => 'CIRCUIT_BREAK',
                'reason' => $this->circuitBreaker->pauseReason(),
                'help'   => 'Verwijder storage/evolution/trading/TRADING_PAUSE.lock om te hervatten',
            ];
        }

        // ── Safety laag 2: Oracle Guard (dual-source prijscheck) ──────────
        $oracle      = (new OraclePriceGuard($this->governanceCfg['oracle'] ?? [], $this->basePath))->check();
        if (!$oracle['ok']) {
            return [
                'action'     => 'ORACLE_FREEZE',
                'reason'     => $oracle['alert'],
                'alert_type' => $oracle['alert_type'],
                'sources'    => $oracle['sources'],
            ];
        }

        $history = $this->priceFeed->getPriceHistory('ethereum', 'eur', 3);
        if (count($history) < 25) {
            return ['action' => 'SKIP', 'reason' => 'Onvoldoende prijsdata (' . count($history) . ' candles)'];
        }

        // Gebruik gemiddelde aankoopprijs van open positie voor trailing stop
        $port         = $this->ledger->portfolio();
        $buyPrice     = (float)($port['avg_buy_price'] ?? 0);
        $signal       = $this->strategy->analyse($history, $buyPrice);
        $currentPrice = $oracle['price'] > 0 ? $oracle['price'] : $this->priceFeed->getCurrentPrice('ethereum', 'eur');

        // ── Flash Crash Guard: controleer op wick / plotselinge daling ──────
        $flashGuard  = new FlashCrashGuard($this->basePath);
        $flashCheck  = $flashGuard->checkAndRecord($currentPrice);
        if ($flashCheck['crashed'] && !$force) {
            return ['action' => 'FLASH_CRASH', 'reason' => $flashCheck['reason'], 'drop_pct' => $flashCheck['drop_pct']];
        }

        // ── Memory Loop: los uitstaande outcomes op (~1u oud) ────────────
        $memory = new TradeMemoryService($this->basePath);
        $memory->resolvePendingOutcomes($currentPrice);

        // ── Agent State Machine: controleer RESTING/STUDYING/VACATION ──────
        $prices1h      = array_column($history, 'price');
        $priceMin      = !empty($prices1h) ? min($prices1h) : $currentPrice;
        $priceMax      = !empty($prices1h) ? max($prices1h) : $currentPrice;
        $volatilityPct = $priceMin > 0 ? ($priceMax - $priceMin) / $priceMin * 100 : 0.0;

        $stateManager = new AgentStateManager($this->basePath);
        $agentState   = $stateManager->evaluate($this->ledger, $volatilityPct);

        if ($agentState === AgentStateManager::STATE_STUDYING) {
            (new StudySessionService($this->container, $this->basePath))->runIfNeeded($stateManager);
            return ['action' => 'STATE_STUDYING', 'reason' => $stateManager->stateInfo()['reason']];
        }
        if ($agentState !== AgentStateManager::STATE_TRADING) {
            return [
                'action'     => 'STATE_' . $agentState,
                'reason'     => $stateManager->stateInfo()['reason'],
                'resume_at'  => $stateManager->stateInfo()['resume_at'] ?? null,
            ];
        }

        // ── Multi-Timeframe upgrade: 15m trigger + 1h bevestiging ─────────
        $history15m    = $this->priceFeed->get15mPriceHistory('ethereum', 'eur');
        $multiSignal   = count($history15m) >= 15 ? $this->strategy->analyseMultiTimeframe($history15m, $history, $buyPrice) : null;
        if ($multiSignal !== null && $multiSignal['signal'] !== 'HOLD') {
            // Multi-TF signaal overschrijft alleen als het STERKER is (bijv. stop-loss of micro-dip)
            if ($multiSignal['strength'] >= $signal['strength'] || $multiSignal['signal'] === 'SELL') {
                $signal = array_merge($signal, $multiSignal);
            }
        }

        $prices15 = array_column($history15m, 'price');
        if (count($prices15) >= 15) {
            $signal['rsi_15m'] = $this->strategy->rsi($prices15, 14);
        } else {
            $signal['rsi_15m'] = (float)($signal['rsi'] ?? 50.0);
        }

        // Gebruik trading wallet als geconfigureerd, anders de agent wallet (genormaliseerd voor Base RPC)
        $walletAddress = $this->tradingWalletAddress !== ''
            ? $this->normAddr($this->tradingWalletAddress)
            : $this->normAddr($this->wallet->load()['address']);
        $ethBalance    = $this->rpc->getBalance($walletAddress);

        // ── Auto-Compounding: dynamische position sizing op basis van actueel saldo ──
        $tradingNavEur = $ethBalance * max(0.0, $currentPrice);
        if ($tradingNavEur >= 100.0) {
            $this->positionSizePct = 0.30; // Standard Tier: 30%
        } elseif ($tradingNavEur >= 50.0) {
            $this->positionSizePct = 0.25; // Professional Tier: 25%
        }

        $this->writeNavSnapshotEur($currentPrice);

        $paperEth      = ($this->paperMode && ($port['seeded_eth'] ?? 0) > 0)
            ? (float)($port['eth_balance'] ?? 0)
            : $ethBalance;

        $safetyCheck = $this->safetyCheck($ethBalance, $currentPrice, $signal, $force);
        if ($safetyCheck !== null) {
            return ['action' => 'SKIP', 'reason' => $safetyCheck, 'signal' => $signal];
        }

        // ── Safety laag: Sentiment Hard Stop (BUY geblokkeerd bij negatief sentiment) ──
        if (!$force && $signal['signal'] === 'BUY') {
            $sentimentData  = (new SentimentAnalyzer($this->container, $this->basePath))->currentSentiment();
            $sentimentScore = (float)($sentimentData['score'] ?? 0.0);
            if ($sentimentScore < -0.3) {
                $memory->recordMoment(array_merge($signal, [
                    'price'     => $currentPrice,
                    'action'    => 'HOLD_SENTIMENT',
                    'sentiment' => $sentimentScore,
                ]));
                return [
                    'action'          => 'HOLD',
                    'reason'          => sprintf(
                        'Technisch RSI signaal was BUY (RSI: %.1f), maar Fysiek Sentiment is te negatief (score: %.2f). Trade geblokkeerd door sentiment-filter.',
                        (float)($signal['rsi'] ?? 0),
                        $sentimentScore
                    ),
                    'signal'          => $signal,
                    'sentiment_score' => $sentimentScore,
                    'price'           => $currentPrice,
                ];
            }
        }

        if (!$force && $signal['strength'] < $this->minStrength) {
            return [
                'action'  => 'HOLD',
                'reason'  => 'Signaal te zwak (' . $signal['strength'] . '/' . $this->minStrength . ' min)',
                'signal'  => $signal,
                'price'   => $currentPrice,
            ];
        }

        // ── Memory Loop: sla context op vóór elke beslissing ─────────────
        $sentCtx = (new SentimentAnalyzer($this->container, $this->basePath))->currentSentiment();
        $memory->recordMoment(array_merge($signal, [
            'price'     => $currentPrice,
            'action'    => $signal['signal'],
            'sentiment' => (float)($sentCtx['score'] ?? 0.0),
        ]));

        if ($signal['signal'] === 'BUY') {
            return $this->executeBuy($signal, $currentPrice, $paperEth, $ethBalance);
        }

        if ($signal['signal'] === 'SELL') {
            $result = $this->executeSell($signal, $currentPrice, $paperEth, $ethBalance);
            // ── Evalueer circuit breaker NA elke trade ────────────────────
            $cb = $this->circuitBreaker->evaluate($this->ledger, $currentPrice);
            if ($cb['tripped']) {
                $result['circuit_break_warning'] = $cb['reason'];
            }
            return $result;
        }

        return [
            'action' => 'HOLD',
            'reason' => $signal['reason'],
            'signal' => $signal,
            'price'  => $currentPrice,
        ];
    }

    /** Full status snapshot for dashboard. */
    public function status(): array
    {
        $this->refreshChainByBalances();
        $currentPrice = $this->priceFeed->getCurrentPrice('ethereum', 'eur');
        $history      = $this->priceFeed->getPriceHistory('ethereum', 'eur', 3);
        $signal       = count($history) > 25 ? $this->strategy->analyse($history) : ['signal' => 'HOLD', 'rsi' => 50.0];
        $history15m   = $this->priceFeed->get15mPriceHistory('ethereum', 'eur');
        $prices15     = array_column($history15m, 'price');
        if (count($prices15) >= 15) {
            $signal['rsi_15m'] = $this->strategy->rsi($prices15, 14);
        } else {
            $signal['rsi_15m'] = (float)($signal['rsi'] ?? 50.0);
        }

        $pnl          = $this->ledger->pnlSummary($currentPrice);
        $port         = $this->ledger->portfolio();
        $recentTrades = $this->ledger->allTrades(10);
        $walletInfo   = $this->wallet->exists() ? $this->wallet->load() : [];
        $agentEth     = !empty($walletInfo) ? $this->rpc->getBalance((string)$walletInfo['address']) : 0.0;

        $tradingInfo = $this->tradingWallet->exists() ? $this->tradingWallet->load() : [];
        $fundAddr    = $this->tradingWalletAddress !== '' ? $this->normAddr($this->tradingWalletAddress) : '';
        $tradingEth  = $fundAddr !== ''
            ? $this->rpc->getBalance($fundAddr)
            : ((!empty($tradingInfo['address']))
                ? $this->rpc->getBalance((string)$tradingInfo['address'])
                : 0.0);

        $ethBlock = $this->rpc->blockNumber();

        $liveDepsOk = class_exists('\kornrunner\Keccak') && class_exists('\Web3p\EthereumTx\Transaction');

        $sentimentData  = (new SentimentAnalyzer($this->container, $this->basePath))->currentSentiment();
        $sentimentScore = (float)($sentimentData['score'] ?? 0.0);

        $cm            = new CapitalManager($this->container, $this->basePath);
        $cap           = $cm->balanceSummary($currentPrice);
        $vaultSecured  = (float)($cap['vault_transferred_eur'] ?? 0);
        $todayNetFlow  = $this->ledger->todayNetFlowEur();
        $primaryEur    = $this->tradingWalletAddress !== ''
            ? (float)($cap['trading']['eur'] ?? 0)
            : (float)($cap['agent']['eur'] ?? 0);

        $xBridgeSnap = null;
        if ($this->container !== null) {
            try {
                $cfg = $this->container->get('config');
                if ($cfg instanceof \App\Core\Config) {
                    $xBridgeSnap = (new XTwitterSentimentBridge($cfg, $this->basePath))->snapshot();
                }
            } catch (\Throwable) {
            }
        }

        return [
            'enabled'       => $this->enabled,
            'mode'          => $this->paperMode ? 'paper' : 'LIVE',
            'chain_id'      => $this->chainId,
            'rpc_url'       => $this->rpc->getRpcUrl(),
            'eth_block_number' => $ethBlock,
            'rpc_chain_id'  => $this->rpc->getChainId(),
            'network_label' => $this->chainId === 1 ? 'Ethereum' : 'Base',
            'ethereum_mainnet_balance' => round($this->probeBalanceEthereum, 8),
            'base_balance_probe'       => round($this->probeBalanceBase, 8),
            'active_trading_address' => $fundAddr !== '' ? $fundAddr : (string)($tradingInfo['address'] ?? ''),
            'price_eur'     => $currentPrice,
            'sentiment_score' => $sentimentScore,
            'x_sentiment_bridge' => $xBridgeSnap,
            'eth_balance'   => round($this->tradingWalletAddress !== '' ? $tradingEth : $agentEth, 8),
            'agent_eth_balance'   => round($agentEth, 8),
            'trading_eth_balance' => round($tradingEth, 8),
            'primary_eur_estimate'=> round($primaryEur, 2),
            'live_signing_ready' => $liveDepsOk,
            'signal'        => $signal,
            'pnl'           => $pnl,
            'portfolio'     => $port,
            'recent_trades' => $recentTrades,
            'performance'   => [
                'today_net_flow_eur' => $todayNetFlow,
                'vault_secured_eur'  => round($vaultSecured, 2),
                'trading_nav_eur'    => round((float)($cap['trading']['eur'] ?? 0), 2),
            ],
            'config'        => [
                'min_eth_reserve'     => $this->minEthReserve,
                'max_trade_pct'       => $this->maxTradePct,
                'position_size_pct'   => $this->positionSizePct,
                'trade_fraction'      => $this->tradeFraction(),
                'daily_loss_limit'    => $this->dailyLossLimitPct . '%',
                'min_signal_strength' => $this->minStrength,
                'validator'           => $this->validatorCfg,
            ],
            'circuit_breaker'=> [
                'paused' => $this->circuitBreaker->isPaused(),
                'reason' => $this->circuitBreaker->isPaused() ? $this->circuitBreaker->pauseReason() : null,
            ],
            'trading_wallet' => $this->tradingWalletAddress ?: 'agent_wallet',
            'ts' => date('c'),
        ];
    }

    /** Circuit breaker reset (admin only). */
    public function resetCircuitBreaker(string $note = ''): void
    {
        $this->circuitBreaker->reset($note);
    }

    /** Seed paper trading portfolio with current ETH balance. */
    public function seedPaperPortfolio(): string
    {
        $walletInfo = $this->wallet->load();
        $ethBalance = $this->rpc->getBalance($walletInfo['address']);
        $price      = $this->priceFeed->getCurrentPrice('ethereum', 'eur');
        $this->ledger->seed($ethBalance, $price);
        return sprintf('Portfolio geseeded met %.6f ETH @ €%.2f/ETH', $ethBalance, $price);
    }

    // ── Execution ─────────────────────────────────────────────────────────

    private function executeBuy(array $signal, float $price, float $paperEth, float $realEth): array
    {
        $port     = $this->ledger->portfolio();
        $eurAvail = (float)($port['eur_balance'] ?? 0);
        $frac     = $this->tradeFraction();
        $buyEur   = $eurAvail * $frac;
        $buyEth   = $price > 0 ? $buyEur / $price : 0;

        // ── Governance check (Trias Politica) ────────────────────────────
        if ($this->container !== null && !$this->paperMode) {
            $gov     = new TradingGovernance($this->container, $this->governanceCfg, $this->basePath);
            $verdict = $gov->evaluate([
                'side'            => 'BUY',
                'amount_eth'      => $buyEth,
                'price_eur'       => $price,
                'live_market_price'=> $price,
                'target_contract' => '',
                'portfolio_eth'   => $paperEth,
                'today_volume_eur'=> 0.0,
                'signal'          => $signal,
            ]);
            if (!$verdict['approved']) {
                return ['action' => 'BLOCKED', 'reason' => $verdict['reason'],
                        'verdict' => $verdict['verdict'], 'signal' => $signal];
            }
        }

        // ── Gas Optimizer: alleen live trades uitstellen bij hoge gas ──────
        if (!$this->paperMode) {
            $gasCheck = (new GasOptimizerService($this->basePath, $this->rpc))->check();
            if (!$gasCheck['ok']) {
                return ['action' => 'GAS_DEFERRED', 'reason' => $gasCheck['reason'], 'signal' => $signal];
            }
        }

        // ── Validator Agent (Rechterlijke Macht stap 1) ───────────────────
        $buyWallet     = $this->tradingWalletAddress !== ''
            ? $this->normAddr($this->tradingWalletAddress)
            : ($this->wallet->exists() ? $this->normAddr($this->wallet->load()['address']) : '');
        $tradingNavEur = $buyWallet !== '' ? $this->rpc->getBalance($buyWallet) * max(0.0, $price) : 0.0;
        $oracleResult  = (new OraclePriceGuard([], $this->basePath))->check();
        $validator     = (new TradingValidatorAgent($this->container, $this->validatorCfg, $this->basePath))->validate(
            array_merge($signal, [
                'side'          => 'BUY',
                'amount_eth'    => $buyEth,
                'price_eur'     => $price,
                'mode'          => $this->paperMode ? 'paper' : 'live',
                'trading_eur'   => round($tradingNavEur, 2),
                'price_history' => $this->priceFeed->getPriceHistory('ethereum', 'eur', 3),
            ]),
            $oracleResult
        );
        if ($validator['verdict'] === 'VETO') {
            return ['action' => 'VETOED', 'reason' => 'Validator VETO: ' . $validator['reason'], 'signal' => $signal];
        }

        if ($this->paperMode) {
            if ($eurAvail < 10) {
                return ['action' => 'HOLD', 'reason' => 'Geen EUR balance om te kopen (verkoop eerst ETH)', 'signal' => $signal];
            }
            $trade = $this->ledger->record('BUY', $buyEth, $price, 'paper', '', $signal['reason']);
            // Court of Records
            (new TradingCourtRecord($this->container, $this->basePath))->record(
                $trade, $signal, $oracleResult, $validator, ['approved' => true, 'verdict' => 'paper_mode', 'votes' => [], 'cost_eur' => 0]
            );
            return [
                'action'     => 'BUY',
                'mode'       => 'paper',
                'amount_eth' => round($buyEth, 6),
                'price_eur'  => $price,
                'value_eur'  => round($buyEur, 2),
                'signal'     => $signal,
                'trade_id'   => $trade['id'],
            ];
        }

        return $this->executeUniswapBuy($price, $signal, $oracleResult, $validator);
    }

    /**
     * @param array<string, mixed> $trade
     * @param array<string, mixed> $portBefore
     */
    private function maybeAccrueProfitFeeOnSell(array $trade, float $amountEth, array $portBefore): void
    {
        if ($this->clientId === null || $this->performanceFeePct <= 0.0) {
            return;
        }
        if (!$this->container instanceof AppContainer) {
            return;
        }
        $avgBuy = (float) ($portBefore['avg_buy_price'] ?? 0);
        $profitEur = max(0.0, (float) ($trade['value_eur'] ?? 0) - $amountEth * $avgBuy);
        if ($profitEur < 0.01) {
            return;
        }
        $feeEur = round($profitEur * ($this->performanceFeePct / 100.0), 4);
        $model = new EvolutionClientModel($this->container);
        $model->insertPerformanceFee(
            $this->clientId,
            (string) ($trade['id'] ?? ''),
            $profitEur,
            $feeEur,
            $this->performanceFeePct,
            null,
            'ACCRUED'
        );
    }

    private function executeSell(array $signal, float $price, float $paperEth, float $realEth): array
    {
        $tradeEth = ($this->paperMode ? $paperEth : $realEth) * $this->tradeFraction();
        $tradeEth = max(0, $tradeEth);

        // ── Governance check (Trias Politica) ────────────────────────────
        if ($this->container !== null && !$this->paperMode) {
            $gov     = new TradingGovernance($this->container, $this->governanceCfg, $this->basePath);
            $verdict = $gov->evaluate([
                'side'             => 'SELL',
                'amount_eth'       => $tradeEth,
                'price_eur'        => $price,
                'live_market_price'=> $price,
                'target_contract'  => $this->routerV2,
                'portfolio_eth'    => $realEth,
                'today_volume_eur' => 0.0,
                'signal'           => $signal,
            ]);
            if (!$verdict['approved']) {
                return ['action' => 'BLOCKED', 'reason' => $verdict['reason'],
                        'verdict' => $verdict['verdict'], 'signal' => $signal];
            }
        }

        // ── Validator Agent ───────────────────────────────────────────────
        $sellWallet    = $this->tradingWalletAddress !== ''
            ? $this->normAddr($this->tradingWalletAddress)
            : ($this->wallet->exists() ? $this->normAddr($this->wallet->load()['address']) : '');
        $tradingNavEur = $sellWallet !== '' ? $this->rpc->getBalance($sellWallet) * max(0.0, $price) : 0.0;
        $oracleResult  = (new OraclePriceGuard([], $this->basePath))->check();
        $validator     = (new TradingValidatorAgent($this->container, $this->validatorCfg, $this->basePath))->validate(
            array_merge($signal, [
                'side'          => 'SELL',
                'amount_eth'    => $tradeEth,
                'price_eur'     => $price,
                'mode'          => $this->paperMode ? 'paper' : 'live',
                'trading_eur'   => round($tradingNavEur, 2),
                'price_history' => $this->priceFeed->getPriceHistory('ethereum', 'eur', 3),
            ]),
            $oracleResult
        );
        if ($validator['verdict'] === 'VETO') {
            return ['action' => 'VETOED', 'reason' => 'Validator VETO: ' . $validator['reason'], 'signal' => $signal];
        }

        if ($this->paperMode) {
            if ($tradeEth < 0.0001) {
                return ['action' => 'HOLD', 'reason' => 'Te weinig ETH om te verkopen', 'signal' => $signal];
            }
            $portBefore = $this->ledger->portfolio();
            $trade = $this->ledger->record('SELL', $tradeEth, $price, 'paper', '', $signal['reason']);
            $this->maybeAccrueProfitFeeOnSell($trade, $tradeEth, $portBefore);
            // Court of Records
            (new TradingCourtRecord($this->container, $this->basePath))->record(
                $trade, $signal, $oracleResult, $validator, ['approved' => true, 'verdict' => 'paper_mode', 'votes' => [], 'cost_eur' => 0]
            );
            // Retrospectie — meteen lessen trekken uit gesloten trade
            $pnlEur = round(($trade['price'] ?? $price) * $tradeEth - $tradeEth * $price, 4);
            try {
                (new \App\Core\Evolution\Intelligence\TradeRetrospective($this->container, $this->basePath))
                    ->run($trade['id'] ?? '', 'SELL', $pnlEur, $price, $signal);
            } catch (\Throwable) {
                // Nooit de trade-flow blokkeren door een retrospectie-fout
            }
            return [
                'action'     => 'SELL',
                'mode'       => 'paper',
                'amount_eth' => round($tradeEth, 6),
                'price_eur'  => $price,
                'value_eur'  => round($tradeEth * $price, 2),
                'signal'     => $signal,
                'trade_id'   => $trade['id'],
            ];
        }

        // Real Uniswap execution — requires on-chain signing libraries
        return $this->executeUniswapSell($tradeEth, $price, $signal);
    }

    /**
     * Real Uniswap v2 ETH → USDC swap.
     * Requires: composer require kornrunner/keccak web3p/ethereum-tx
     */
    private function executeUniswapSell(float $amountEth, float $priceEur, array $signal): array
    {
        if (!class_exists('\kornrunner\Keccak') || !class_exists('\Web3p\EthereumTx\Transaction')) {
            return [
                'action' => 'SKIP',
                'reason' => 'Live trading vereist: composer require kornrunner/keccak web3p/ethereum-tx',
                'signal' => $signal,
            ];
        }

        try {
            $signer = $this->resolveSignerForLiveTrading();
            if (!$signer['ok']) {
                return [
                    'action' => 'SKIP',
                    'reason' => (string)($signer['error'] ?? 'Geen signer voor live trade'),
                    'signal' => $signal,
                ];
            }
            $walletInfo  = $signer['wallet_info'] ?? [];
            $privKey     = (string)($signer['priv_key'] ?? '');
            $fromAddress = $this->normAddr((string)($walletInfo['address'] ?? ''));

            $weiAmount = $this->ethToWei($amountEth);
            $expectedUsdc = $priceEur > 0 && $this->eurPerUsdc > 0
                ? ($amountEth * $priceEur) / $this->eurPerUsdc
                : 0.0;
            $minOut = (int)floor($expectedUsdc * $this->slippageMultiplier * 1e6); // USDC 6 decimals
            $deadline    = time() + 300;

            $nonce    = $this->rpc->getNonce($fromAddress);
            $gasPrice = $this->rpc->gasPriceWei();
            $gasLimit = 200000;

            $callData = $this->encodeUniswapSwap($minOut, $fromAddress, $deadline);

            $txClass = '\Web3p\EthereumTx\Transaction';
            $tx = new $txClass([
                'nonce'    => '0x' . dechex($nonce),
                'gasPrice' => '0x' . dechex($gasPrice),
                'gasLimit' => '0x' . dechex($gasLimit),
                'to'       => $this->routerV2,
                'value'    => '0x' . dechex($weiAmount),
                'data'     => $callData,
                'chainId'  => $this->chainId,
            ]);

            $signedTx = '0x' . $tx->sign($privKey);
            $txHash   = $this->rpc->sendRawTransaction($signedTx);

            $portBefore = $this->ledger->portfolio();
            $trade = $this->ledger->record('SELL', $amountEth, $priceEur, 'live', $txHash, $signal['reason']);
            $this->maybeAccrueProfitFeeOnSell($trade, $amountEth, $portBefore);

            return [
                'action'     => 'SELL',
                'mode'       => 'live',
                'amount_eth' => round($amountEth, 6),
                'price_eur'  => $priceEur,
                'tx_hash'    => $txHash,
                'signal'     => $signal,
                'trade_id'   => $trade['id'],
            ];
        } catch (\Throwable $e) {
            return ['action' => 'ERROR', 'reason' => $e->getMessage(), 'signal' => $signal];
        }
    }

    /**
     * Live Uniswap v2: USDC → ETH (swapExactTokensForETH). Vereist USDC op de trading wallet (o.a. na SELL).
     */
    private function executeUniswapBuy(float $priceEur, array $signal, array $oracleResult, array $validator): array
    {
        if (!class_exists('\kornrunner\Keccak') || !class_exists('\Web3p\EthereumTx\Transaction')) {
            return [
                'action' => 'SKIP',
                'reason' => 'Live trading vereist: kornrunner/keccak + web3p/ethereum-tx',
                'signal' => $signal,
            ];
        }

        try {
            $signer = $this->resolveSignerForLiveTrading();
            if (!$signer['ok']) {
                return [
                    'action' => 'SKIP',
                    'reason' => (string)($signer['error'] ?? 'Geen signer voor live BUY'),
                    'signal' => $signal,
                ];
            }
            $walletInfo  = $signer['wallet_info'] ?? [];
            $privKey     = (string)($signer['priv_key'] ?? '');
            $fromAddress = $this->normAddr((string)($walletInfo['address'] ?? ''));

            $usdcRaw = $this->erc20BalanceOf($this->usdc, $fromAddress);
            if ($usdcRaw <= 0) {
                return [
                    'action' => 'SKIP',
                    'reason' => 'Geen USDC op trading wallet — voer eerst live SELL (ETH→USDC) uit of stuur USDC naar het adres',
                    'signal' => $signal,
                ];
            }

            $tradeUsdc = (int)floor($usdcRaw * $this->tradeFraction());
            if ($tradeUsdc < 50_000) {
                return [
                    'action' => 'HOLD',
                    'reason' => sprintf('Te weinig USDC om te swappen (min ~0.05 USDC na drempel); beschikbaar %s USDC', number_format($usdcRaw / 1e6, 4)),
                    'signal' => $signal,
                ];
            }

            $allowance = $this->erc20Allowance($this->usdc, $fromAddress, $this->routerV2);
            $deadline  = time() + 300;

            $usdcHuman = $tradeUsdc / 1e6;
            $eurVal    = $usdcHuman * $this->eurPerUsdc;
            $expectEth = $priceEur > 0 ? $eurVal / $priceEur : 0.0;
            $amountOutMinWei = (int)max(1, floor($expectEth * 1e18 * $this->slippageMultiplier));

            $gasPrice = $this->rpc->gasPriceWei();
            $nonce    = $this->rpc->getNonce($fromAddress);
            $txClass  = '\Web3p\EthereumTx\Transaction';

            if ($allowance < $tradeUsdc) {
                $approveData = $this->encodeErc20Approve($this->routerV2, $this->uint256MaxHex());
                $txApprove     = new $txClass([
                    'nonce'    => '0x' . dechex($nonce),
                    'gasPrice' => '0x' . dechex($gasPrice),
                    'gasLimit' => '0x' . dechex(100_000),
                    'to'       => $this->usdc,
                    'value'    => '0x0',
                    'data'     => $approveData,
                    'chainId'  => $this->chainId,
                ]);
                $signedA = '0x' . $txApprove->sign($privKey);
                $this->rpc->sendRawTransaction($signedA);
                $nonce++;
            }

            $swapData = $this->encodeSwapExactTokensForETH($tradeUsdc, $amountOutMinWei, $fromAddress, $deadline);
            $txSwap   = new $txClass([
                'nonce'    => '0x' . dechex($nonce),
                'gasPrice' => '0x' . dechex($gasPrice),
                'gasLimit' => '0x' . dechex(320_000),
                'to'       => $this->routerV2,
                'value'    => '0x0',
                'data'     => $swapData,
                'chainId'  => $this->chainId,
            ]);
            $signedS  = '0x' . $txSwap->sign($privKey);
            $txHash   = $this->rpc->sendRawTransaction($signedS);

            $buyEthApprox = $expectEth;
            $trade        = $this->ledger->record('BUY', $buyEthApprox, $priceEur, 'live', $txHash, $signal['reason']);
            (new TradingCourtRecord($this->container, $this->basePath))->record(
                $trade,
                $signal,
                $oracleResult,
                $validator,
                ['approved' => true, 'verdict' => 'live_uniswap_buy', 'votes' => [], 'cost_eur' => 0]
            );

            return [
                'action'       => 'BUY',
                'mode'         => 'live',
                'amount_eth'   => round($buyEthApprox, 6),
                'usdc_in'      => round($tradeUsdc / 1e6, 4),
                'price_eur'    => $priceEur,
                'tx_hash'      => $txHash,
                'signal'       => $signal,
                'trade_id'     => $trade['id'],
            ];
        } catch (\Throwable $e) {
            return ['action' => 'ERROR', 'reason' => $e->getMessage(), 'signal' => $signal];
        }
    }

    private function erc20BalanceOf(string $token, string $holder): int
    {
        $data = '0x70a08231' . $this->abiAddr($holder);
        $out  = $this->rpc->ethCall($token, $data);

        return $this->hexUintToInt($out ?? '0x0');
    }

    private function erc20Allowance(string $token, string $owner, string $spender): int
    {
        $data = '0xdd62ed3e' . $this->abiAddr($owner) . $this->abiAddr($spender);
        $out  = $this->rpc->ethCall($token, $data);

        return $this->hexUintToInt($out ?? '0x0');
    }

    private function encodeErc20Approve(string $spender, string $amountHex64): string
    {
        return '0x095ea7b3' . $this->abiAddr($spender) . $amountHex64;
    }

    /** 32-byte hex (64 chars) max uint256 voor eenmalige router-approve. */
    private function uint256MaxHex(): string
    {
        return str_repeat('f', 64);
    }

    private function abiAddr(string $addr0x): string
    {
        $h = strtolower(preg_replace('/^0x/', '', $addr0x));
        if (strlen($h) !== 40 || !ctype_xdigit($h)) {
            throw new \InvalidArgumentException('Invalid address for ABI encoding');
        }

        return str_pad($h, 64, '0', STR_PAD_LEFT);
    }

    private function hexUintToInt(?string $hex): int
    {
        if ($hex === null || $hex === '' || $hex === '0x') {
            return 0;
        }
        $h = strtolower(preg_replace('/^0x/', '', $hex));
        $h = ltrim($h, '0') ?: '0';
        if (strlen($h) <= 16) {
            return (int)hexdec($h);
        }
        if (\function_exists('gmp_init')) {
            return (int)\gmp_strval(\gmp_init($h, 16), 10);
        }

        throw new \RuntimeException('ext-gmp vereist voor grote uint256 RPC-waarden (zet php-gmp aan op de server)');
    }

    /**
     * Uniswap V2 swapExactTokensForETH — path USDC → WETH.
     */
    private function encodeSwapExactTokensForETH(int $amountIn, int $amountOutMinWei, string $to, int $deadline): string
    {
        $selector = '18cbafe5';
        $u256     = static function (int $n): string {
            $hex = dechex(max(0, $n));
            if (strlen($hex) % 2 === 1) {
                $hex = '0' . $hex;
            }

            return str_pad($hex, 64, '0', STR_PAD_LEFT);
        };
        $addrWord = function (string $a): string {
            return $this->abiAddr($a);
        };

        return '0x' . $selector
            . $u256($amountIn)
            . $u256($amountOutMinWei)
            . $u256(160)
            . $addrWord($to)
            . $u256($deadline)
            . $u256(2)
            . $addrWord($this->usdc)
            . $addrWord($this->weth);
    }

    // ── Safety checks ─────────────────────────────────────────────────────

    /**
     * @param bool $force CLI --force: sla ETH-reserve-/gas-saldochecks over (dagelijks verlies-limiet blijft).
     */
    private function safetyCheck(float $ethBalance, float $price, array $signal, bool $force = false): ?string
    {
        $sig = $signal['signal'] ?? 'HOLD';

        if (!$force) {
            if ($sig === 'BUY' && !$this->paperMode) {
                $minGasEth = $this->chainId === 8453 ? 0.00015 : 0.0008;
                if ($ethBalance < $minGasEth) {
                    return sprintf(
                        'Live BUY: min %.5f ETH nodig voor gas (approve+swap); nu %.6f ETH',
                        $minGasEth,
                        $ethBalance
                    );
                }
            } elseif ($ethBalance < $this->minEthReserve + ($this->chainId === 8453 ? 0.0002 : 0.001)) {
                return sprintf('ETH balance te laag (%.6f ETH, reserve %.4f)', $ethBalance, $this->minEthReserve);
            }
        }

        $dailyLoss = $this->calcDailyLoss();
        if ($dailyLoss > $this->dailyLossLimitPct) {
            return sprintf('Dagelijks verlies limiet bereikt (%.2f%% > %.2f%%)', $dailyLoss, $this->dailyLossLimitPct);
        }

        return null;
    }

    private function calcDailyLoss(): float
    {
        $today  = date('Y-m-d');
        $trades = $this->ledger->allTrades(50);
        $pnl    = 0.0;
        foreach ($trades as $t) {
            if (!str_starts_with($t['iso'], $today)) {
                continue;
            }
            $pnl += $t['side'] === 'SELL' ? $t['value_eur'] : -$t['value_eur'];
        }
        return $pnl < 0 ? abs($pnl) : 0.0;
    }

    private function ethToWei(float $eth): int
    {
        return (int)round($eth * 1e18);
    }

    private function encodeUniswapSwap(int $amountOutMin, string $to, int $deadline): string
    {
        // swapExactETHForTokens(uint256 amountOutMin, address[] path, address to, uint256 deadline)
        // ABI: heads (4 words) then dynamic path: length, path[0], path[1]
        $selector = '7ff36ab5';

        $u256 = static function (int $n): string {
            $hex = dechex(max(0, $n));
            if (strlen($hex) % 2 === 1) {
                $hex = '0' . $hex;
            }

            return str_pad($hex, 64, '0', STR_PAD_LEFT);
        };

        $addrWord = static function (string $addr0x): string {
            $h = strtolower(preg_replace('/^0x/', '', $addr0x));
            if (strlen($h) !== 40 || !ctype_xdigit($h)) {
                throw new \InvalidArgumentException('Invalid EVM address for swap calldata.');
            }

            return str_pad($h, 64, '0', STR_PAD_LEFT);
        };

        $amountHex = $u256($amountOutMin);
        $pathOff   = $u256(128);
        $toWord    = $addrWord($to);
        $deadWord  = $u256($deadline);
        $lenWord   = $u256(2);
        $wethWord  = $addrWord($this->weth);
        $usdcWord  = $addrWord($this->usdc);

        return '0x' . $selector . $amountHex . $pathOff . $toWord . $deadWord . $lenWord . $wethWord . $usdcWord;
    }
}
