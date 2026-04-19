<?php

declare(strict_types=1);

namespace App\Core\Evolution\Wallet;

/**
 * BaseRpcService — JSON-RPC voor Base mainnet (chain 8453).
 *
 * Vast: alleen {@see self::BASE_MAINNET_RPC} — geen alternatieve endpoints of saldo-overrides.
 * Adres-normalisatie en wei→ETH via {@see MultiChainRpcService}.
 */
final class BaseRpcService extends MultiChainRpcService
{
    public const BASE_MAINNET_RPC = 'https://mainnet.base.org';

    public const BASE_CHAIN_ID = 8453;

    public function __construct(?string $basePath = null)
    {
        parent::__construct($basePath, self::BASE_MAINNET_RPC, 'base');
    }

    public static function forTrading(?string $basePath = null): self
    {
        return new self($basePath);
    }

    public static function forTradingFromEvolutionJson(?string $basePath = null): self
    {
        return new self($basePath);
    }
}
