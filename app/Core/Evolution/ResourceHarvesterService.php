<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Passive yield evaluation on Base — no CPU mining.
 * Validator: allowlist (governance.approved_protocols) + blacklist (unsafe_contracts + Academy file).
 */
final class ResourceHarvesterService
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param list<string> $approvedFromGovernance lowercase 0x addresses from config
     */
    public function assertProtocolAllowed(string $contractAddress, array $approvedFromGovernance = []): bool
    {
        $addr = $this->normAddr($contractAddress);
        if ($addr === '') {
            return false;
        }

        foreach ($this->blacklistAddresses() as $b) {
            if ($b === $addr) {
                EvolutionLogger::log('validator', 'resource_harvest_blocked', ['reason' => 'blacklist', 'contract' => $addr]);

                return false;
            }
        }

        $rh = (array)($this->config->get('evolution.resource_harvester', []));
        $requireAllowlist = (bool)($rh['require_allowlist'] ?? true);
        if (!$requireAllowlist) {
            return true;
        }

        if ($approvedFromGovernance === []) {
            $gov = (array)($this->config->get('evolution.trading.governance', []));
            $ap = (array)($gov['approved_protocols'] ?? []);
            foreach ($ap as $x) {
                $approvedFromGovernance[] = strtolower((string)$x);
            }
        }

        if ($approvedFromGovernance === []) {
            EvolutionLogger::log('validator', 'resource_harvest_blocked', ['reason' => 'empty_allowlist', 'contract' => $addr]);

            return false;
        }

        foreach ($approvedFromGovernance as $ok) {
            if ($this->normAddr((string)$ok) === $addr) {
                return true;
            }
        }

        EvolutionLogger::log('validator', 'resource_harvest_blocked', ['reason' => 'not_in_allowlist', 'contract' => $addr]);

        return false;
    }

    /**
     * Rough APR vs gas — uses config thresholds; does not guarantee on-chain execution.
     *
     * @return array{profitable: bool, apr_percent: float, gas_usd_estimate: float, annual_yield_usd: float, note: string}
     */
    public function estimateYieldVsGas(float $amountUsd, float $aprPercent, float $gasUsdPerRoundtrip, int $roundtrips = 2): array
    {
        $rh = (array)($this->config->get('evolution.resource_harvester', []));
        $minApr = (float)($rh['min_yield_apr_percent'] ?? 3.0);
        $minSurplus = (float)($rh['min_surplus_after_gas_usd'] ?? 0.5);

        $annual = $amountUsd * ($aprPercent / 100.0);
        $gasTotal = $gasUsdPerRoundtrip * max(1, $roundtrips);
        $profitable = $aprPercent >= $minApr && ($annual - $gasTotal) >= $minSurplus;

        return [
            'profitable' => $profitable,
            'apr_percent' => $aprPercent,
            'gas_usd_estimate' => $gasTotal,
            'annual_yield_usd' => $annual,
            'note' => $profitable ? 'yield_after_gas_ok' : 'yield_after_gas_insufficient',
        ];
    }

    /**
     * eth_getBalance via Base JSON-RPC (cheap; no heavy polling).
     *
     * @return non-empty-string|null wei hex (0x…) or null on failure
     */
    public function fetchNativeBalanceWei(string $rpcUrl, string $address): ?string
    {
        $rpcUrl = trim($rpcUrl);
        $addr = $this->normAddr($address);
        if ($rpcUrl === '' || !str_starts_with($addr, '0x')) {
            return null;
        }

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'eth_getBalance',
            'params' => [$addr, 'latest'],
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($rpcUrl, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $j = json_decode($raw, true);
        $r = is_array($j) ? ($j['result'] ?? null) : null;

        return is_string($r) && str_starts_with($r, '0x') ? $r : null;
    }

    public function cavemanLog(string $message, array $context = []): void
    {
        EvolutionLogger::log('caveman', $message, $context);
    }

    /**
     * Scheduled tick: log allowlist size + template yield-vs-gas (no on-chain tx).
     * Called from EvolutionAlphaCycleCommand when evolution.alpha_cycle.enabled is true.
     *
     * @return array{ok: bool, skipped?: bool, reason?: string, approved_protocols?: int, yield_template?: array<string, mixed>}
     */
    public function runScheduledSnapshot(): array
    {
        $rh = (array) ($this->config->get('evolution.resource_harvester', []));
        if (!filter_var($rh['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'resource_harvester disabled'];
        }

        $gov = (array) ($this->config->get('evolution.trading.governance', []));
        $approved = (array) ($gov['approved_protocols'] ?? []);
        $gasUsd = (float) ($rh['gas_usd_per_roundtrip_estimate'] ?? 0.15);
        $minApr = (float) ($rh['min_yield_apr_percent'] ?? 3.0);
        $est = $this->estimateYieldVsGas(100.0, max(3.0, $minApr), $gasUsd);

        $this->cavemanLog('resource_harvest_snapshot', [
            'approved_protocols' => count($approved),
            'profitable_template' => $est['profitable'],
            'apr_percent' => $est['apr_percent'],
        ]);

        EvolutionLogger::log('resource_harvester', 'scheduled_snapshot', [
            'approved_count' => count($approved),
            'yield_note' => $est['note'],
        ]);

        return [
            'ok' => true,
            'approved_protocols' => count($approved),
            'yield_template' => $est,
        ];
    }

    /**
     * @return list<string>
     */
    private function blacklistAddresses(): array
    {
        $out = [];
        $rh = (array)($this->config->get('evolution.resource_harvester', []));
        foreach ((array)($rh['unsafe_contracts'] ?? []) as $x) {
            $n = $this->normAddr((string)$x);
            if ($n !== '') {
                $out[] = $n;
            }
        }

        $path = $this->basePath() . '/storage/evolution/academy_cache/protocol_blacklist_base.json';
        if (is_readable($path)) {
            $raw = @file_get_contents($path);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($j)) {
                foreach ((array)($j['contracts'] ?? []) as $c) {
                    if (is_string($c)) {
                        $n = $this->normAddr($c);
                        if ($n !== '') {
                            $out[] = $n;
                        }
                    }
                }
                foreach ($j as $k => $v) {
                    if (is_string($k) && str_starts_with(strtolower($k), '0x')) {
                        $n = $this->normAddr($k);
                        if ($n !== '') {
                            $out[] = $n;
                        }
                    }
                    if (is_array($v) && isset($v['address'])) {
                        $n = $this->normAddr((string)$v['address']);
                        if ($n !== '') {
                            $out[] = $n;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function basePath(): string
    {
        return defined('BASE_PATH') ? (string)BASE_PATH : dirname(__DIR__, 3);
    }

    private function normAddr(string $a): string
    {
        $a = strtolower(trim($a));

        return preg_match('/^0x[a-f0-9]{40}$/', $a) === 1 ? $a : '';
    }
}
