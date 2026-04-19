<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Config;
use App\Core\Container;
use App\Core\Evolution\TelegramNotifier;
use App\Domain\Web\Models\TradingCourtRecordModel;
use Psr\Container\ContainerInterface;

/**
 * TradingCourtRecord — Juridische logging voor het Trading Rechtssysteem.
 *
 * Elk handels-besluit genereert een "Decision Manifest":
 *   "Ik [kocht|verkocht] X ETH op tijdstip Y omdat RSI Z was,
 *    goedgekeurd door Validator A, met prijsbron B, consensus C."
 *
 * Opslag:
 *   1. RDS database via TradingCourtRecordModel — queries in src/queries/TradingCourtRecord.json
 *   2. JSONL fallback: storage/evolution/trading/court_records.jsonl
 *
 * Gebruikt door de Judge Agent om:
 *   - Verlieslatende trades te analyseren
 *   - Trader Agent te degraderen bij regelovertredingen
 *   - Audit trail te leveren voor externe review
 */
final class TradingCourtRecord
{
    private const JSONL_FILE = 'storage/evolution/trading/court_records.jsonl';

    private ?ContainerInterface $container;
    private string              $basePath;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null)
    {
        $this->container = $container;
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Sla een volledig Decision Manifest op.
     *
     * @param array $trade      Uitgevoerde trade (van TradingLedger::record())
     * @param array $signal     TradingStrategy::analyse() resultaat
     * @param array $oracle     OraclePriceGuard::check() resultaat
     * @param array $validator  TradingValidatorAgent::validate() resultaat
     * @param array $governance TradingGovernance::evaluate() resultaat
     * @param array $extra      Extra context (gas_price, tx_hash, wallet_address, etc.)
     * @return string           Record ID
     */
    public function record(
        array $trade,
        array $signal,
        array $oracle,
        array $validator,
        array $governance,
        array $extra = []
    ): string {
        $manifest = $this->buildManifest($trade, $signal, $oracle, $validator, $governance, $extra);
        $this->persistToJsonl($manifest);
        $this->persistToDb($manifest);
        $this->notifyTelegram($manifest);

        // ── Schrijf de reasoning-keten naar de Live Feed ──────────────────
        try {
            (new ReasoningLogger($this->basePath))->writeChain(
                $manifest['record_id'],
                $signal,
                $oracle,
                $validator,
                $governance,
                $trade
            );
        } catch (\Throwable) {
        }

        return $manifest['record_id'];
    }

    /**
     * Bouw een leesbaar Decision Manifest.
     */
    public function buildManifest(
        array $trade,
        array $signal,
        array $oracle,
        array $validator,
        array $governance,
        array $extra = []
    ): array {
        $side  = strtoupper($trade['side'] ?? '?');
        $eth   = round((float)($trade['amount_eth'] ?? 0), 6);
        $price = round((float)($trade['price_eur'] ?? 0), 2);
        $ts    = $trade['iso'] ?? date('c');
        $mode  = $trade['mode'] ?? 'paper';
        $rsi   = $signal['rsi']      ?? '?';
        $trend = $signal['trend']    ?? '?';
        $str   = $signal['strength'] ?? '?';

        $cgPrice = round((float)($oracle['sources']['coingecko'] ?? $price), 2);
        $krPrice = round((float)($oracle['sources']['kraken']    ?? $price), 2);
        $divPct  = $oracle['divergence_pct'] ?? 0;

        $valVerdict = $validator['verdict']   ?? 'UNKNOWN';
        $valReason  = $validator['reason']    ?? '';
        $govVerdict = $governance['verdict']  ?? 'UNKNOWN';
        $votes      = $governance['votes']    ?? [];

        $narrative = sprintf(
            'Agent %s %s ETH @ €%s op %s (mode: %s). '
            . 'Reden: RSI=%s, trend=%s, signaalsterkte=%s%%. '
            . 'Prijsbronnen: CoinGecko €%s / Kraken €%s (divergentie: %s%%). '
            . 'Validator: %s (%s). Governance: %s (%d stemmen).',
            $side, $eth, $price, $ts, $mode,
            $rsi, $trend, $str,
            $cgPrice, $krPrice, $divPct,
            $valVerdict, $valReason,
            $govVerdict, count($votes)
        );

        return [
            'record_id'       => 'cr_' . uniqid('', true),
            'ts'              => $ts,
            'created_at'      => date('c'),
            'narrative'       => $narrative,
            'trade'           => $trade,
            'signal'          => $signal,
            'oracle'          => $oracle,
            'validator'       => ['verdict' => $valVerdict, 'reason' => $valReason, 'ai_used' => $validator['ai_used'] ?? false],
            'governance'      => ['verdict' => $govVerdict, 'approved' => $governance['approved'] ?? false, 'cost_eur' => $governance['cost_eur'] ?? 0],
            'extra'           => $extra,
            'mode'            => $mode,
        ];
    }

    /**
     * Haal de laatste N records op voor de Judge Agent.
     */
    public function recentRecords(int $limit = 20): array
    {
        // Probeer DB via Model eerst
        if ($this->container instanceof Container) {
            $model = new TradingCourtRecordModel($this->container);
            $dbRecords = $model->recent($limit);
            if (!empty($dbRecords)) {
                return $dbRecords;
            }
        }

        // Fallback JSONL
        $file = $this->basePath . '/' . self::JSONL_FILE;
        if (!is_file($file)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string)file_get_contents($file)));
        $records = [];
        foreach ($lines as $line) {
            $r = json_decode($line, true);
            if (is_array($r)) {
                $records[] = $r;
            }
        }
        return array_slice(array_reverse($records), 0, $limit);
    }

    /**
     * Judge Agent analyse: samenvatting van verdachte trades.
     */
    public function judgeAnalysis(int $lookbackDays = 7): array
    {
        $records = $this->recentRecords(100);
        $issues  = [];

        foreach ($records as $r) {
            if (($r['validator']['verdict'] ?? '') === 'VETO') {
                $issues[] = ['type' => 'VETO_IGNORED', 'ts' => $r['ts'], 'narrative' => $r['narrative'] ?? ''];
            }
        }

        return [
            'lookback_days' => $lookbackDays,
            'total_records' => count($records),
            'issues'        => $issues,
            'issue_count'   => count($issues),
            'verdict'       => count($issues) > 3 ? 'DEGRADATION_RECOMMENDED' : 'CLEAN',
        ];
    }

    // ── Persistence ───────────────────────────────────────────────────────

    private function persistToJsonl(array $manifest): void
    {
        $dir = dirname($this->basePath . '/' . self::JSONL_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents(
            $this->basePath . '/' . self::JSONL_FILE,
            json_encode($manifest) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    private function persistToDb(array $manifest): void
    {
        if (!($this->container instanceof Container)) {
            return;
        }
        try {
            $model = new TradingCourtRecordModel($this->container);
            $model->insert($manifest);
        } catch (\Throwable) {
            // DB niet beschikbaar — JSONL is de fallback
        }
    }

    private function notifyTelegram(array $manifest): void
    {
        if (!($this->container instanceof Container)) {
            return;
        }
        try {
            $cfg   = $this->container->get('config');
            $side  = strtoupper($manifest['trade']['side'] ?? '?');
            $eth   = round((float)($manifest['trade']['amount_eth'] ?? 0), 5);
            $price = round((float)($manifest['trade']['price_eur']  ?? 0), 2);
            $val   = round((float)($manifest['trade']['value_eur']  ?? 0), 2);
            $rsi   = $manifest['signal']['rsi']      ?? '?';
            $trend = $manifest['signal']['trend']    ?? '?';
            $str   = $manifest['signal']['strength'] ?? '?';
            $vval  = $manifest['validator']['verdict'] ?? '?';
            $gval  = $manifest['governance']['verdict'] ?? '?';
            $mode  = $manifest['mode'] ?? 'paper';
            $div   = $manifest['oracle']['divergence_pct'] ?? '?';

            $emoji = $side === 'BUY' ? '🟢' : '🔴';
            $msg   = "{$emoji} *Trading Court Record* [{$mode}]\n\n"
                   . "*{$side}* {$eth} ETH @ €{$price} (€{$val})\n"
                   . "RSI: {$rsi} | Trend: {$trend} | Sterkte: {$str}%\n"
                   . "Oracle divergentie: {$div}%\n"
                   . "Validator: {$vval} | Governance: {$gval}\n\n"
                   . "_" . ($manifest['narrative'] ?? '') . "_";

            TelegramNotifier::send($cfg, $msg, 'info');
        } catch (\Throwable) {
            // Telegram niet beschikbaar — geen exception
        }
    }
}
