<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Evolution\Wallet\MultiChainRpcService;
use App\Core\Evolution\Wallet\TelegramService;

/**
 * CrossChainRadar — Observer mode: vergelijkt ETH↔USDC impliciete prijzen tussen ketens.
 * Voert geen bridges uit; logt alleen + optioneel Telegram (wekelijks).
 */
final class CrossChainRadar
{
    private const LOG_FILE          = 'storage/evolution/trading/cross_chain_radar.jsonl';
    private const WEEKLY_STATE_FILE = 'storage/evolution/trading/cross_chain_weekly_state.json';
    private const HOURLY_STATS_FILE = 'storage/evolution/trading/cross_chain_hourly_stats.json';

    private string $basePath;
    private float $eurPerUsdc;
    private float $bridgeFeeEur;
    private bool $observerMode;
    private array $chainCfgs;

    public function __construct(array $tradingConfig = [], ?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $cc             = (array)($tradingConfig['cross_chain'] ?? []);
        $this->eurPerUsdc = (float)($tradingConfig['eur_per_usdc'] ?? 0.92);
        $this->bridgeFeeEur = (float)($cc['estimated_bridge_fee_eur'] ?? 2.0);
        $this->observerMode = (bool)($cc['observer_mode'] ?? true);
        $this->chainCfgs = (array)($cc['chains'] ?? $this->defaultChainCfgs());
    }

    /**
     * @return array<string, array{router: string, weth: string, usdc: string, rpc_key: string}>
     */
    private function defaultChainCfgs(): array
    {
        return [
            'base' => [
                'router'  => '0x4752ba5dDBC23f44d87826276BF6Fd6b1C372aD24',
                'weth'    => '0x4200000000000000000000000000000000000006',
                'usdc'    => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                'rpc_key' => 'base',
            ],
            'arbitrum' => [
                'router'  => '0x4757fb23dfeb3e3a9cf3ef8a5d36f16c0bd97266',
                'weth'    => '0x82aF49447D8a07e3bd95BD0d56f35241523fBab1',
                'usdc'    => '0xaf88d065e77c8cC2239327C5EDb3F4322E81F8e8',
                'rpc_key' => 'arbitrum',
            ],
        ];
    }

    /**
     * Uurscan: Base vs Arbitrum (USDC per 1 ETH), netto na bridge-schatting. Geen on-chain writes behalve logs.
     *
     * @return array<string, mixed>
     */
    public function runHourlyScan(): array
    {
        $oneEthWei = (int)1e18;
        $rows      = [];
        foreach ($this->chainCfgs as $name => $cfg) {
            $rpc = MultiChainRpcService::forChain((string)($cfg['rpc_key'] ?? $name), $this->basePath);
            $out = $this->quoteUsdcOut(
                $rpc,
                (string)$cfg['router'],
                (string)$cfg['weth'],
                (string)$cfg['usdc'],
                $oneEthWei
            );
            $rows[$name] = [
                'usdc_out_1eth' => $out,
                'chain_id'      => $rpc->getChainId(),
                'rpc'           => $rpc->getRpcUrl(),
            ];
        }

        $baseRaw  = (float)($rows['base']['usdc_out_1eth'] ?? 0);
        $arbRaw   = (float)($rows['arbitrum']['usdc_out_1eth'] ?? 0);
        $baseUsdc = $baseRaw / 1e6;
        $arbUsdc  = $arbRaw / 1e6;
        $diffUsdc = abs($baseUsdc - $arbUsdc);
        $diffEur  = $diffUsdc * $this->eurPerUsdc;
        $netEur   = ($baseRaw > 0 && $arbRaw > 0) ? max(0.0, $diffEur - $this->bridgeFeeEur) : 0.0;

        $line = [
            'ts'              => date('c'),
            'observer_mode'   => $this->observerMode,
            'base_usdc_raw'   => $baseRaw,
            'arbitrum_usdc_raw' => $arbRaw,
            'base_usdc'       => round($baseUsdc, 4),
            'arbitrum_usdc'   => round($arbUsdc, 4),
            'diff_usdc'       => round($diffUsdc, 4),
            'potential_eur'   => round($netEur, 4),
            'bridge_fee_eur'  => $this->bridgeFeeEur,
        ];
        $this->appendLog($line);
        if ($baseRaw > 0 && $arbRaw > 0) {
            $this->updateHourlyStats($line);
        }

        $this->maybeSendWeeklyReport($rows);

        $msg = ($baseRaw > 0 && $arbRaw > 0)
            ? sprintf(
                'Observer: 1 ETH → Base %.2f USDC vs Arbitrum %.2f USDC (Δ %.2f USDC, ~€%.2f net na €%.2f bridge)',
                $baseUsdc,
                $arbUsdc,
                $diffUsdc,
                $netEur,
                $this->bridgeFeeEur
            )
            : sprintf(
                'Observer: Base %.2f USDC/ETH; Arbitrum V2-quote niet beschikbaar (geen bruikbare WETH→USDC pool via geconfigureerde router). Geen bridge-acties (observer).',
                $baseUsdc
            );

        return [
            'ok'       => true,
            'log'      => $line,
            'chains'   => $rows,
            'message'  => $msg,
        ];
    }

    private function updateHourlyStats(array $line): void
    {
        $file = $this->basePath . '/' . self::HOURLY_STATS_FILE;
        $prev = ['samples' => [], 'week_base_sum' => 0.0, 'week_arb_sum' => 0.0, 'week_count' => 0];
        if (is_file($file)) {
            $j = json_decode((string) file_get_contents($file), true);
            if (is_array($j)) {
                $prev = array_merge($prev, $j);
            }
        }
        $prev['samples'][] = $line;
        $prev['samples'] = array_slice($prev['samples'], -168);
        $prev['week_base_sum'] = (float)($prev['week_base_sum'] ?? 0) + (float)($line['base_usdc'] ?? 0);
        $prev['week_arb_sum'] = (float)($prev['week_arb_sum'] ?? 0) + (float)($line['arbitrum_usdc'] ?? 0);
        $prev['week_count'] = (int)($prev['week_count'] ?? 0) + 1;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode($prev, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function maybeSendWeeklyReport(array $rows): void
    {
        $cc = [];
        $cfgFile = $this->basePath . '/config/evolution.json';
        if (is_file($cfgFile)) {
            $cfg = json_decode((string) file_get_contents($cfgFile), true);
            $cc  = (array)(($cfg['trading']['cross_chain'] ?? []) ?: []);
        }
        if (!(bool)($cc['weekly_telegram_enabled'] ?? true)) {
            return;
        }

        $stateFile = $this->basePath . '/' . self::WEEKLY_STATE_FILE;
        $state     = ['last_sent' => 0, 'week_id' => ''];
        if (is_file($stateFile)) {
            $j = json_decode((string) file_get_contents($stateFile), true);
            if (is_array($j)) {
                $state = array_merge($state, $j);
            }
        }

        $weekId = date('o-\WW');
        if (($state['week_id'] ?? '') !== $weekId) {
            $state['week_id'] = $weekId;
            $state['last_sent'] = 0;
        }

        $now = time();
        if ($now - (int)($state['last_sent'] ?? 0) < 7 * 86400) {
            return;
        }
        if ((int)date('w') !== 1 || (int)date('G') < 9) {
            return;
        }

        $statsFile = $this->basePath . '/' . self::HOURLY_STATS_FILE;
        $ratioText = 'onduidelijk (nog geen stats)';
        if (is_file($statsFile)) {
            $st = json_decode((string) file_get_contents($statsFile), true);
            if (is_array($st) && (int)($st['week_count'] ?? 0) > 5) {
                $b = (float)($st['week_base_sum'] ?? 1);
                $a = (float)($st['week_arb_sum'] ?? 1);
                $ratio = $b > 0 ? $a / $b : 0.0;
                $better = $ratio > 1.05 ? 'Arbitrum' : ($ratio < 0.95 ? 'Base' : 'vergelijkbaar');
                $ratioText = sprintf(
                    'Gemiddelde USDC per 1 ETH-scan: Base vs Arbitrum factor ~%.2fx (%s vaak gunstiger volgens deze proxy).',
                    max($ratio, 1 / max($ratio, 1e-9)),
                    $better
                );
            }
        }

        $tg = new TelegramService();
        if (!$tg->isConfigured()) {
            return;
        }

        $msg = "<b>Cross-Chain Kansen Rapport (observer)</b>\n\n"
            . $ratioText . "\n\n"
            . "<i>Geen bridges uitgevoerd. Governance-lock &lt; €250 NAV.</i>";
        if ($tg->send($msg)) {
            $state['last_sent'] = $now;
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }

    private function appendLog(array $line): void
    {
        $file = $this->basePath . '/' . self::LOG_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode($line) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function quoteUsdcOut(
        MultiChainRpcService $rpc,
        string $router,
        string $weth,
        string $usdc,
        int $amountInWei
    ): float {
        $data = $this->encodeGetAmountsOut($amountInWei, [$weth, $usdc]);
        $raw  = $rpc->ethCall($router, $data);
        if ($raw === null || $raw === '0x') {
            return 0.0;
        }
        $amounts = $this->decodeUintArray($raw);
        if (count($amounts) < 2) {
            return 0.0;
        }
        return (float)end($amounts);
    }

    private function encodeGetAmountsOut(int $amountInWei, array $path): string
    {
        $selector = 'd06ca61f';
        $u256     = static function (int $n): string {
            $hex = dechex(max(0, $n));
            if (strlen($hex) % 2 === 1) {
                $hex = '0' . $hex;
            }
            return str_pad($hex, 64, '0', STR_PAD_LEFT);
        };
        $addr = static function (string $a): string {
            $h = strtolower(preg_replace('/^0x/', '', $a));
            return str_pad($h, 64, '0', STR_PAD_LEFT);
        };
        $head  = $u256($amountInWei) . $u256(64);
        $tail  = $u256(count($path));
        foreach ($path as $p) {
            $tail .= $addr($p);
        }

        return '0x' . $selector . $head . $tail;
    }

    /** @return list<int> */
    private function decodeUintArray(string $hexData): array
    {
        $h = strtolower(preg_replace('/^0x/', '', $hexData));
        if (strlen($h) < 128) {
            return [];
        }
        $byteOffset = (int)hexdec(substr($h, 0, 64));
        $p          = $byteOffset * 2;
        if ($p + 64 > strlen($h)) {
            return [];
        }
        $n   = (int)hexdec(substr($h, $p, 64));
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $start = $p + 64 + $i * 64;
            if ($start + 64 > strlen($h)) {
                break;
            }
            $out[] = $this->hexToInt(substr($h, $start, 64));
        }
        return $out;
    }

    private function hexToInt(string $hex): int
    {
        $hex = ltrim($hex, '0') ?: '0';
        if (strlen($hex) <= 16) {
            return (int)hexdec($hex);
        }
        if (\function_exists('gmp_init')) {
            return (int)\gmp_strval(\gmp_init($hex, 16), 10);
        }
        return PHP_INT_MAX;
    }
}
