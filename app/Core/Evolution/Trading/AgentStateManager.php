<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * AgentStateManager — State machine voor de autonome trading agent.
 *
 * States:
 *   TRADING  : Normale operatie — signalen volgen en trades uitvoeren.
 *   RESTING  : Na 3+ verliesgevende trades op rij OF na 24u intensief scalpen.
 *              Duurt 6 uur. Voorkomt "revenge trading".
 *   STUDYING : Eén keer per dag (Aziatische sessie UTC 01-05h).
 *              Analyseert Vector Memory met Claude Sonnet en update heuristieken.
 *   VACATION : Bij extreem lage volatiliteit (12u flat) OF handmatig via Telegram.
 *              Bespaart API-kosten; monitort alleen absolute bodemprijzen.
 *
 * Persistentie: storage/evolution/trading/agent_state.json
 * Telegram:     /vacation, /wake, /study — zie TelegramCommandCenter
 */
final class AgentStateManager
{
    public const STATE_TRADING  = 'TRADING';
    public const STATE_RESTING  = 'RESTING';
    public const STATE_STUDYING = 'STUDYING';
    public const STATE_VACATION = 'VACATION';

    private const STATE_FILE           = 'storage/evolution/trading/agent_state.json';
    private const REST_HOURS           = 6;
    private const MAX_LOSING_STREAK    = 3;    // 3 op rij → RESTING
    private const REST_AFTER_HOURS     = 24;   // 24u non-stop actief → RESTING
    private const STUDY_HOUR_UTC_START = 1;    // UTC 01:00 Aziatische sessie
    private const STUDY_HOUR_UTC_END   = 5;    // UTC 05:00
    private const STUDY_DURATION_H     = 2;
    private const VACATION_LOW_VOL_H   = 12;   // 12u extreem lage volatiliteit

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /** Huidige state-string. */
    public function currentState(): string
    {
        return (string)($this->load()['state'] ?? self::STATE_TRADING);
    }

    /**
     * Volledig state-rapport voor Telegram /status.
     *
     * @return array{state: string, since: string, reason: string, resume_at: ?string,
     *               remaining_secs: ?int, losing_streak: int, study_last_ran: ?string}
     */
    public function stateInfo(): array
    {
        $data     = $this->load();
        $resumeAt = $data['resume_at'] ?? null;
        $remaining = $resumeAt !== null ? max(0, strtotime((string)$resumeAt) - time()) : null;

        return [
            'state'          => (string)($data['state']         ?? self::STATE_TRADING),
            'since'          => (string)($data['since']          ?? date('c')),
            'reason'         => (string)($data['reason']         ?? ''),
            'resume_at'      => $resumeAt,
            'remaining_secs' => $remaining,
            'losing_streak'  => (int)($data['losing_streak']    ?? 0),
            'study_last_ran' => $data['study_last_ran']          ?? null,
        ];
    }

    /**
     * Evalueer state-transities. Aanroepen aan het begin van elke tick.
     *
     * @param  TradingLedger $ledger
     * @param  float         $volatilityPct  Actuele 1h price range % (0 = onbekend)
     * @return string  Huidige state na evaluatie
     */
    public function evaluate(TradingLedger $ledger, float $volatilityPct = 0.0): string
    {
        $data  = $this->load();
        $state = (string)($data['state'] ?? self::STATE_TRADING);

        // ── Auto-resume verlopen niet-TRADING states ──────────────────────
        if ($state !== self::STATE_TRADING) {
            $resumeAt = (string)($data['resume_at'] ?? '');
            if ($resumeAt !== '' && time() >= strtotime($resumeAt)) {
                $this->transitionTo(self::STATE_TRADING, 'Auto-resume: periode afgelopen', null);
                return self::STATE_TRADING;
            }
            return $state;
        }

        // ── TRADING → RESTING: verlies-streak ────────────────────────────
        $streak = $this->calcLosingStreak($ledger);
        if ($streak >= self::MAX_LOSING_STREAK) {
            $this->transitionTo(
                self::STATE_RESTING,
                sprintf('%d verliesgevende trades op rij — revenge trading geblokkeerd', $streak),
                self::REST_HOURS
            );
            return self::STATE_RESTING;
        }

        // ── TRADING → RESTING: 24u non-stop actief ───────────────────────
        $activeHours = $this->activeHoursSinceLastRest($data);
        if ($activeHours >= self::REST_AFTER_HOURS) {
            $this->transitionTo(
                self::STATE_RESTING,
                sprintf('%.0fu continu actief gehandeld — verplichte rust voor capaciteitsherstel', $activeHours),
                self::REST_HOURS
            );
            return self::STATE_RESTING;
        }

        // ── TRADING → STUDYING: dagelijks Aziatische sessie ──────────────
        $hour = (int)gmdate('G');
        $studyLastRan = (string)($data['study_last_ran'] ?? '');
        $studiedToday = $studyLastRan !== ''
            && gmdate('Y-m-d', strtotime($studyLastRan)) === gmdate('Y-m-d');

        if ($hour >= self::STUDY_HOUR_UTC_START && $hour < self::STUDY_HOUR_UTC_END && !$studiedToday) {
            $this->transitionTo(
                self::STATE_STUDYING,
                'Dagelijkse Studie-sessie (UTC 01-05h): Vector Memory analyse + heuristiek update',
                self::STUDY_DURATION_H
            );
            return self::STATE_STUDYING;
        }

        // ── TRADING → VACATION: extreme lage volatiliteit ────────────────
        if ($volatilityPct > 0.0 && $volatilityPct < 0.30) {
            $flatHours = $this->consecutiveLowVolatilityHours();
            if ($flatHours >= self::VACATION_LOW_VOL_H) {
                $this->transitionTo(
                    self::STATE_VACATION,
                    sprintf('Markt is %.0fu extreem kalm (%.2f%% range) — API-besparing modus', $flatHours, $volatilityPct),
                    null
                );
                return self::STATE_VACATION;
            }
        }

        return self::STATE_TRADING;
    }

    /** Handmatige state-override via Telegram of CLI. */
    public function forceState(string $state, string $reason = 'Handmatig', int $hours = 0): void
    {
        $this->transitionTo($state, $reason, $hours > 0 ? $hours : null);
    }

    /** Markeer huidige study-sessie als afgerond → terug naar TRADING. */
    public function markStudyComplete(): void
    {
        $data = $this->load();
        $data['study_last_ran'] = date('c');
        $data['state']          = self::STATE_TRADING;
        $data['since']          = date('c');
        $data['reason']         = 'Studie afgerond — terug naar TRADING';
        $data['resume_at']      = null;
        $this->save($data);
    }

    /** Reset verlies-streak (aanroepen na een winstgevende SELL). */
    public function resetLosingStreak(): void
    {
        $data = $this->load();
        $data['losing_streak'] = 0;
        $this->save($data);
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    private function transitionTo(string $state, string $reason, ?int $durationHours): void
    {
        $data = $this->load();
        $resumeAt = $durationHours !== null
            ? date('c', time() + $durationHours * 3600)
            : null;

        // Bij TRADING: sla trading_since op voor 24u-check
        if ($state === self::STATE_TRADING && ($data['state'] ?? '') !== self::STATE_TRADING) {
            $data['trading_since'] = date('c');
        }

        $data['state']    = $state;
        $data['since']    = date('c');
        $data['reason']   = $reason;
        $data['resume_at'] = $resumeAt;
        $this->save($data);
    }

    private function calcLosingStreak(TradingLedger $ledger): int
    {
        $trades  = $ledger->allTrades(20);
        $streak  = 0;
        $openBuy = null;

        foreach (array_reverse($trades) as $t) {
            if ($t['side'] === 'BUY') {
                $openBuy = (float)$t['price_eur'];
            } elseif ($t['side'] === 'SELL' && $openBuy !== null) {
                if ((float)$t['price_eur'] < $openBuy) {
                    $streak++;
                } else {
                    $streak = 0;
                }
                $openBuy = null;
            }
        }

        return $streak;
    }

    private function activeHoursSinceLastRest(array $data): float
    {
        $tradingSince = (string)($data['trading_since'] ?? '');
        if ($tradingSince === '') {
            return 0.0;
        }
        return max(0.0, (time() - strtotime($tradingSince)) / 3600);
    }

    private function consecutiveLowVolatilityHours(): float
    {
        $file = $this->basePath . '/data/evolution/trading/flash_crash_prices.json';
        if (!is_file($file)) {
            return 0.0;
        }
        $prices = json_decode((string)file_get_contents($file), true) ?? [];
        if (count($prices) < 5) {
            return 0.0;
        }

        $values = array_column($prices, 'price');
        $min    = min($values);
        $max    = max($values);
        if ($min <= 0) {
            return 0.0;
        }

        $rangePct = ($max - $min) / $min * 100;
        if ($rangePct > 0.5) {
            return 0.0; // Niet laag-volatiel
        }

        $oldest = min(array_column($prices, 'ts'));
        return (time() - $oldest) / 3600;
    }

    private function load(): array
    {
        $file = $this->basePath . '/' . self::STATE_FILE;
        if (!is_file($file)) {
            return [
                'state'          => self::STATE_TRADING,
                'since'          => date('c'),
                'reason'         => 'Initiële state',
                'resume_at'      => null,
                'losing_streak'  => 0,
                'trading_since'  => date('c'),
                'study_last_ran' => null,
            ];
        }
        return json_decode((string)file_get_contents($file), true) ?? [];
    }

    private function save(array $data): void
    {
        $file = $this->basePath . '/' . self::STATE_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
