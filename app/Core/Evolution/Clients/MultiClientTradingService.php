<?php

declare(strict_types=1);

namespace App\Core\Evolution\Clients;

use App\Core\Container;
use App\Core\Evolution\Trading\TradingService;
use App\Domain\Web\Models\EvolutionClientModel;

/**
 * Loops ACTIVE clients and runs an isolated TradingService tick per wallet (risk-adjusted).
 */
final class MultiClientTradingService
{
    public function __construct(
        private readonly string $basePath,
        private readonly Container $container,
    ) {
    }

    /**
     * @param array<string, mixed> $tradingCfg evolution.trading slice
     * @return array{mode:string, results?: list<array<string, mixed>>, legacy?: array<string, mixed>}
     */
    public function runTicksForActiveClients(array $tradingCfg, bool $force = false): array
    {
        $model   = new EvolutionClientModel($this->container);
        $clients = $model->listActiveForTrading();
        if ($clients === []) {
            return ['mode' => 'none'];
        }

        $enc = new ClientEncryptionService($this->basePath, $this->container);
        $results = [];

        foreach ($clients as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            try {
                $privHex = $enc->decrypt((string) ($row['trading_wallet_secret_encrypted'] ?? ''));
            } catch (\Throwable $e) {
                $results[] = [
                    'client_id' => $id,
                    'error' => 'decrypt_failed',
                    'message' => $e->getMessage(),
                ];
                continue;
            }

            $merged = $this->mergeRiskIntoTradingConfig($tradingCfg, $row);
            $svc    = new TradingService(['trading' => $merged], $this->basePath, $this->container, $id);
            $svc->setClientRuntimeSigner($privHex);
            $svc->setPerformanceFeePct((float) ($row['profit_share_pct'] ?? 0));

            $tick = $svc->tick($force);
            try {
                $model->touchHeartbeat($id);
            } catch (\Throwable) {
            }
            try {
                $model->insertActivity(
                    $id,
                    'trading',
                    (string) ($tick['action'] ?? '') . ': ' . (string) ($tick['reason'] ?? ''),
                    ['tick' => $tick]
                );

                $sig = $tick['signal'] ?? [];
                $model->insertActivity(
                    $id,
                    'validator',
                    'Signal ' . (string) ($sig['signal'] ?? '?') . ' @ ' . (string) ($sig['strength'] ?? '?') . '%',
                    []
                );
                $model->insertActivity(
                    $id,
                    'architect',
                    'Strategy tick completed — risk profile ' . (string) ($row['risk_level'] ?? ''),
                    []
                );
            } catch (\Throwable) {
            }

            $results[] = [
                'client_id' => $id,
                'tick' => $tick,
            ];

            $svc->setClientRuntimeSigner(null);
        }

        return ['mode' => 'multi', 'results' => $results];
    }

    /**
     * @param array<string, mixed> $tradingCfg
     * @param array<string, mixed> $clientRow
     * @return array<string, mixed>
     */
    public function mergeRiskIntoTradingConfig(array $tradingCfg, array $clientRow): array
    {
        $risk  = (string) ($clientRow['risk_level'] ?? 'BALANCED');
        $min   = (int) ($tradingCfg['min_signal_strength'] ?? 30);
        $daily = (float) ($tradingCfg['daily_loss_limit_pct'] ?? 8.0);
        $out   = $tradingCfg;

        if ($risk === 'SAFE') {
            $out['min_signal_strength']   = min(95, $min + 12);
            $out['daily_loss_limit_pct'] = max(2.0, $daily - 3.0);
        } elseif ($risk === 'AGGRESSIVE') {
            $out['min_signal_strength']   = max(5, $min - 10);
            $out['daily_loss_limit_pct'] = min(25.0, $daily + 5.0);
        }

        $out['trading_wallet_address'] = trim((string) ($clientRow['trading_wallet_address'] ?? ''));

        return $out;
    }
}
