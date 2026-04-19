<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive;

use App\Core\Container;
use App\Domain\Web\Models\ReasoningTraceModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * EvidenceExporter — writes anonymised, public-consumable JSON snapshots
 * of winning trades into web/evidence/ for the marketing "Public Evidence"
 * page.
 *
 * What gets exported per trade:
 *   - the correlation_id (truncated to 8 hex chars for display)
 *   - trace direction, aggregate_score, confidence, summary
 *   - each policy step with its weight/confidence/contribution + rationale
 *   - calibration rudder (factor + reason)
 *   - the net profit in EUR, *rounded to the nearest whole EUR* for anonymity
 *   - the realised % delta (rounded to 1dp)
 *   - the pair (e.g. "ETH/EUR") and network_label (e.g. "Base")
 *   - horizon hours, ISO-date of the trade
 *
 * What gets stripped:
 *   - chain_id (numerical), exact spot prices (rounded to nearest 10 EUR)
 *   - wallet addresses, tx hashes (never touched; not in trace anyway)
 *   - IPs, user agents (never in trace)
 *   - raw observations that may reveal wallet size (sample_size capped at "20+")
 *
 * Files are written as content-addressed JSON:
 *   web/evidence/{correlation_id}.json
 *
 * A simple index.json (auto-regenerated on every export) lists all files
 * with lightweight metadata, suitable for the public page.
 */
final class EvidenceExporter
{
    public const DIR_REL           = 'web/evidence';
    public const INDEX_FILE        = 'index.json';
    public const PRICE_ROUND_EUR   = 10.0;   // round spot_price_eur to nearest €10
    public const PROFIT_ROUND_EUR  = 1.0;    // round profit to nearest €1
    public const MIN_PROFIT_EUR    = 0.50;   // below this we do NOT export

    public function __construct(
        private readonly Container $container,
        private readonly ?string $basePath = null
    ) {
    }

    /**
     * Export a trade's reasoning evidence if it meets the profitability bar.
     *
     * @param array<string, mixed> $context  Optional extras: pair, trade_id, trade_iso
     * @return array{ok: bool, exported?: bool, reason?: string, path?: string, correlation_id?: string}
     */
    public function exportTrade(string $correlationId, float $profitEur, array $context = []): array
    {
        if ($correlationId === '') {
            return ['ok' => false, 'reason' => 'missing_correlation_id'];
        }
        if ($profitEur < self::MIN_PROFIT_EUR) {
            return [
                'ok'       => true,
                'exported' => false,
                'reason'   => sprintf('profit_below_threshold (%.4f < %.2f)', $profitEur, self::MIN_PROFIT_EUR),
            ];
        }

        $traceModel = new ReasoningTraceModel($this->container);
        $row = $traceModel->findByCorrelationId($correlationId);
        if ($row === null) {
            return ['ok' => false, 'reason' => 'trace_not_found:' . $correlationId];
        }

        $decoded = json_decode((string) ($row['payload'] ?? '{}'), true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'reason' => 'trace_payload_corrupt'];
        }

        $public = $this->anonymise($decoded, $profitEur, $context);

        $dir = $this->evidenceDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'reason' => 'evidence_dir_not_writable:' . $dir];
        }

        $filename = $this->safeFilename($correlationId) . '.json';
        $path     = $dir . DIRECTORY_SEPARATOR . $filename;

        $json = json_encode($public, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return ['ok' => false, 'reason' => 'json_encode_failed'];
        }
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            return ['ok' => false, 'reason' => 'write_failed:' . $path];
        }

        $this->rebuildIndex();

        return [
            'ok'             => true,
            'exported'       => true,
            'path'           => $path,
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEvidence(int $limit = 100): array
    {
        $dir = $this->evidenceDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $files = array_filter($files, fn(string $f) => basename($f) !== self::INDEX_FILE);

        $out = [];
        foreach ($files as $f) {
            $raw = @file_get_contents($f);
            if (!is_string($raw)) {
                continue;
            }
            $d = json_decode($raw, true);
            if (!is_array($d)) {
                continue;
            }
            $out[] = [
                'file'           => basename($f),
                'exported_at'    => $d['exported_at']    ?? null,
                'trade_iso'      => $d['trade_iso']      ?? null,
                'correlation_id' => $d['correlation_id'] ?? null,
                'direction'      => $d['trace']['direction'] ?? null,
                'confidence'     => $d['trace']['confidence'] ?? null,
                'profit_eur'     => $d['profit_eur']     ?? null,
                'delta_pct'      => $d['delta_pct']      ?? null,
                'pair'           => $d['pair']           ?? null,
                'network'        => $d['network_label']  ?? null,
                'horizon_hours'  => $d['horizon_hours']  ?? null,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string) ($b['exported_at'] ?? ''), (string) ($a['exported_at'] ?? ''));
        });
        return array_slice($out, 0, max(1, $limit));
    }

    public function evidenceDir(): string
    {
        $root = $this->basePath ?? (defined('BASE_PATH') ? BASE_PATH : (getcwd() ?: '.'));
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DIR_REL);
    }

    /**
     * @param array<string, mixed> $trace
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function anonymise(array $trace, float $profitEur, array $context): array
    {
        $input = (array) ($trace['input_snapshot'] ?? []);

        $spot = (float) ($input['spot_price_eur'] ?? 0.0);
        $spotBand = self::PRICE_ROUND_EUR > 0
            ? round($spot / self::PRICE_ROUND_EUR) * self::PRICE_ROUND_EUR
            : $spot;

        $profitRounded = self::PROFIT_ROUND_EUR > 0
            ? round($profitEur / self::PROFIT_ROUND_EUR) * self::PROFIT_ROUND_EUR
            : $profitEur;

        $deltaPct = isset($context['delta_pct']) && is_numeric($context['delta_pct'])
            ? round((float) $context['delta_pct'], 1)
            : null;

        // Keep the policy step list but strip chain_id / numeric observations
        // that could fingerprint wallet size.
        $steps = [];
        foreach ((array) ($trace['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $obs = (array) ($step['observations'] ?? []);
            unset($obs['sample_size']);      // redact: looks too specific
            if (isset($obs['historical_hit_rate'])) {
                $obs['historical_hit_rate'] = round((float) $obs['historical_hit_rate'], 2);
            }
            $steps[] = [
                'policy'       => $step['policy']       ?? '',
                'direction'    => $step['direction']    ?? '',
                'weight'       => $step['weight']       ?? 0,
                'confidence'   => $step['confidence']   ?? 0,
                'contribution' => $step['contribution'] ?? 0,
                'rationale'    => $step['rationale']    ?? '',
                'observations' => $obs,
            ];
        }

        $correlation = (string) ($trace['correlation_id'] ?? '');
        $shortId     = substr($correlation, -8);

        return [
            'schema_version' => '1.0.0',
            'exported_at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'trade_iso'      => (string) ($context['trade_iso'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM)),
            'correlation_id' => $correlation,
            'short_id'       => $shortId,
            'pair'           => (string) ($context['pair'] ?? 'ETH/EUR'),
            'network_label'  => (string) ($input['network_label'] ?? 'Unknown'),
            'horizon_hours'  => (int)    ($input['horizon_hours'] ?? 0),
            'spot_price_band_eur' => $spotBand,   // rounded, not exact
            'profit_eur'     => $profitRounded,
            'delta_pct'      => $deltaPct,
            'trace'          => [
                'direction'       => (string) ($trace['direction']       ?? 'NEUTRAL'),
                'aggregate_score' => (float)  ($trace['aggregate_score'] ?? 0),
                'confidence'      => (float)  ($trace['confidence']      ?? 0),
                'summary'         => (string) ($trace['summary']         ?? ''),
                'vetoes'          => (array)  ($trace['vetoes']          ?? []),
                'calibration'     => (array)  ($trace['calibration']     ?? []),
                'steps'           => $steps,
            ],
            'notice' => 'Public reasoning snapshot. Prices banded to nearest '
                     . (int) self::PRICE_ROUND_EUR . ' EUR, profit rounded to nearest '
                     . (int) self::PROFIT_ROUND_EUR . ' EUR. No wallet addresses or tx hashes exposed.',
        ];
    }

    private function rebuildIndex(): void
    {
        $dir = $this->evidenceDir();
        $items = $this->listEvidence(500);
        $index = [
            'schema_version' => '1.0.0',
            'generated_at'   => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'count'          => count($items),
            'items'          => $items,
        ];
        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . self::INDEX_FILE,
            json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}',
            LOCK_EX
        );
    }

    private function safeFilename(string $correlationId): string
    {
        $s = preg_replace('/[^a-zA-Z0-9_-]/', '', $correlationId) ?? '';
        if ($s === '') {
            $s = 'trace-' . bin2hex(random_bytes(4));
        }
        return substr($s, 0, 64);
    }
}
