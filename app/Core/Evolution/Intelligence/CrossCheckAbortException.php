<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

/**
 * CrossCheckAbortException — gegooid wanneer MythosProxy een ABORT-verdict geeft.
 *
 * Gedrag:
 *   - Extern: stack blijft stil (geen buy-signal, geen deploy)
 *   - Intern:  schrijft een JSONL-record naar data/logs/crosscheck_aborts_YYYY-MM-DD.jsonl
 *
 * Dit abort-log is de bewijslast voor de WeeklySystemAuditJob:
 *   "Was Llama's veto terecht? Update het brain-bestand als dat zo is."
 *
 * @example
 *   $cross = $proxy->crossCheck($sonnetOutput, 'buy BTC at $94k', $ctx);
 *   if ($cross['verdict'] === 'ABORT') {
 *       throw new CrossCheckAbortException('buy BTC at $94k', $cross);
 *   }
 */
final class CrossCheckAbortException extends \RuntimeException
{
    private readonly array  $attacks;
    private readonly float  $confidence;
    private readonly string $criticalRisk;
    private readonly string $decision;
    private readonly string $abortId;

    public function __construct(string $decision, array $crossCheckResult, ?string $basePath = null)
    {
        $this->decision     = $decision;
        $this->attacks      = (array)($crossCheckResult['attacks']      ?? []);
        $this->confidence   = (float)($crossCheckResult['confidence']   ?? 0.0);
        $this->criticalRisk = (string)($crossCheckResult['critical_risk'] ?? '');
        $this->abortId      = 'abort_' . date('Ymd_His') . '_' . substr(md5($decision), 0, 6);

        $summary = count($this->attacks) > 0 ? $this->attacks[0] : 'CrossCheck ABORT';
        parent::__construct("[ABORT:{$this->abortId}] {$decision} — {$summary}");

        $this->writeAbortLog($basePath);
    }

    public function getDecision(): string    { return $this->decision; }
    public function getAttacks(): array      { return $this->attacks; }
    public function getConfidence(): float   { return $this->confidence; }
    public function getCriticalRisk(): string{ return $this->criticalRisk; }
    public function getAbortId(): string     { return $this->abortId; }

    /**
     * Geeft een compact array voor logging/API-responses.
     * Bevat GEEN intern redeneerproces — alleen wat extern gecommuniceerd mag worden.
     *
     * @return array{abort_id: string, decision: string, critical_risk: string, timestamp: string}
     */
    public function toPublicSummary(): array
    {
        return [
            'abort_id'      => $this->abortId,
            'decision'      => $this->decision,
            'critical_risk' => $this->criticalRisk,
            'timestamp'     => date('c'),
        ];
    }

    /**
     * Geeft het volledige interne record — ALLEEN voor interne audit/logging.
     *
     * @return array{abort_id: string, decision: string, attacks: string[], confidence: float, critical_risk: string, timestamp: string}
     */
    public function toInternalRecord(): array
    {
        return [
            'abort_id'      => $this->abortId,
            'decision'      => $this->decision,
            'attacks'       => $this->attacks,
            'confidence'    => $this->confidence,
            'critical_risk' => $this->criticalRisk,
            'timestamp'     => date('c'),
        ];
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function writeAbortLog(?string $basePath): void
    {
        $base = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $dir  = $base . '/data/logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $file   = $dir . '/crosscheck_aborts_' . date('Y-m-d') . '.jsonl';
        $record = json_encode($this->toInternalRecord(), JSON_UNESCAPED_UNICODE);

        @file_put_contents($file, $record . "\n", FILE_APPEND | LOCK_EX);

        // Log ook naar PHP error_log zodat het zichtbaar is in CloudWatch
        error_log(sprintf(
            '[CROSSCHECK_ABORT] id=%s decision="%s" confidence=%.2f critical="%s" attacks=%d',
            $this->abortId,
            substr($this->decision, 0, 80),
            $this->confidence,
            substr($this->criticalRisk, 0, 100),
            count($this->attacks)
        ));
    }
}
