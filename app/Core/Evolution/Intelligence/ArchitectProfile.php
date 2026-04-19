<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

/**
 * ArchitectProfile — Legacy Knowledge Injectie.
 *
 * Geeft de trading agent toegang tot de persoonlijke doelen van de Architect.
 * De agent handelt dan niet meer voor "meer geld", maar voor jouw specifieke vrijheid.
 *
 * Werking:
 *   1. Laad src/config/architect_profile.json
 *   2. Bereken voortgang + urgentie per doel
 *   3. Genereer risicoaanpassing-signaal (-3 tot +2 RSI-punten)
 *   4. Format een persoonlijke context-string voor het LLM
 *
 * Risicoaanpassingen:
 *   • Kritiek doel < 90 dagen + <50% behaald  → RSI −3 (voorzichtig)
 *   • Hoog doel <1 jaar + <50% behaald        → RSI −2 (voorzichtig)
 *   • Alle doelen op schema                   → RSI ±0 (normaal)
 *   • Alle doelen >2 jaar weg / alle behaald  → RSI +1 (wat agressiever)
 *
 * Integratie:
 *   • DeepReasoningService::buildContextPack() — sectie 8
 *   • StrategyOptimizer::run()               — RSI-drempel aanpassing
 */
final class ArchitectProfile
{
    private const PROFILE_FILE = 'config/architect_profile.json';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Geef de RSI-drempelaanpassing terug op basis van doelenurgentie.
     *
     * Positief = meer ruimte (agressiever), negatief = strenger (conservatiever).
     * Bereik: −3 tot +2
     */
    public function rsiAdjustment(): float
    {
        $profile = $this->load();
        if ($profile === null || empty($profile['goals'])) {
            return 0.0;
        }

        $adjustment = 0.0;
        $now        = time();
        $cfg        = $profile['risk_overrides'] ?? [];
        $penaltyDays = (int)($cfg['critical_goal_near_deadline_days'] ?? 90);
        $penaltyRsi  = (float)($cfg['critical_goal_rsi_penalty']       ?? 3.0);

        foreach ($profile['goals'] as $goal) {
            $targetEur  = (float)($goal['target_eur']  ?? 0);
            $currentEur = (float)($goal['current_eur'] ?? 0);
            $deadline   = strtotime((string)($goal['deadline'] ?? '')) ?: 0;
            $priority   = strtolower((string)($goal['priority'] ?? 'medium'));

            if ($targetEur <= 0 || $deadline <= 0) {
                continue;
            }

            $progressPct    = min(100.0, $currentEur / $targetEur * 100.0);
            $daysRemaining  = max(0, (int)(($deadline - $now) / 86400));
            $alreadyExpired = $deadline < $now;

            if ($alreadyExpired) {
                continue; // Verlopen doelen negeren
            }

            // Kritiek doel + dichtbij deadline + ver van doel → sterk conservatiever
            if ($priority === 'critical' && $daysRemaining <= $penaltyDays && $progressPct < 50) {
                $adjustment -= $penaltyRsi;
                continue;
            }

            // Hoog prioriteit doel binnen 1 jaar + <50% behaald → conservatiever
            if ($priority === 'high' && $daysRemaining <= 365 && $progressPct < 50) {
                $adjustment -= 2.0;
                continue;
            }

            // Medium prioriteit + <1 jaar + <30% behaald → iets conservatiever
            if ($priority === 'medium' && $daysRemaining <= 365 && $progressPct < 30) {
                $adjustment -= 1.0;
                continue;
            }
        }

        // Als alle doelen ver weg zijn (>2 jaar) of behaald → kleine bonus agressiviteit
        $allFarOrDone = true;
        foreach ($profile['goals'] as $goal) {
            $targetEur  = (float)($goal['target_eur']  ?? 0);
            $currentEur = (float)($goal['current_eur'] ?? 0);
            $deadline   = strtotime((string)($goal['deadline'] ?? '')) ?: 0;

            if ($deadline > 0 && $deadline > $now + (2 * 365 * 86400)) {
                continue; // Meer dan 2 jaar weg — OK
            }
            if ($targetEur > 0 && $currentEur >= $targetEur) {
                continue; // Behaald
            }
            $allFarOrDone = false;
            break;
        }

        if ($allFarOrDone && $adjustment === 0.0) {
            $adjustment += 1.0;
        }

        // Clamp op bereik
        return max(-3.0, min(2.0, $adjustment));
    }

    /**
     * Genereer een context-string voor het LLM met persoonlijke doelen en urgentie.
     * Gebruikt in DeepReasoningService::buildContextPack() als sectie 8.
     */
    public function contextString(): string
    {
        $profile = $this->load();
        if ($profile === null || empty($profile['goals'])) {
            return '';
        }

        $lines = [];
        $now   = time();

        $lines[] = sprintf(
            "Architect: %s | Risicoprofiel: %s",
            $profile['name']         ?? 'Architect',
            strtoupper($profile['risk_profile'] ?? 'moderate')
        );

        foreach ($profile['goals'] as $goal) {
            $targetEur  = (float)($goal['target_eur']  ?? 0);
            $currentEur = (float)($goal['current_eur'] ?? 0);
            $deadline   = strtotime((string)($goal['deadline'] ?? '')) ?: 0;
            $label      = (string)($goal['label']       ?? $goal['id'] ?? '?');
            $priority   = strtolower((string)($goal['priority'] ?? 'medium'));

            if ($targetEur <= 0) {
                continue;
            }

            $progressPct   = min(100.0, $targetEur > 0 ? $currentEur / $targetEur * 100.0 : 0.0);
            $remaining     = max(0, $targetEur - $currentEur);
            $daysRemaining = $deadline > 0 ? max(0, (int)(($deadline - $now) / 86400)) : -1;

            $urgency = '';
            if ($daysRemaining >= 0 && $daysRemaining <= 90) {
                $urgency = ' ⚠️ URGENT';
            } elseif ($daysRemaining >= 0 && $daysRemaining <= 365) {
                $urgency = ' 📅 dit jaar';
            }

            $lines[] = sprintf(
                "• %s [%s]: €%.0f/€%.0f (%.0f%%) — nog €%.0f nodig%s%s",
                $label,
                $priority,
                $currentEur,
                $targetEur,
                $progressPct,
                $remaining,
                $daysRemaining >= 0 ? sprintf(' in %dd', $daysRemaining) : '',
                $urgency
            );
        }

        $adj = $this->rsiAdjustment();
        if ($adj < 0) {
            $lines[] = sprintf("⚠️ RISICO-AANPASSING: RSI-drempel verlaagd met %.0f punten (doelen vereisen voorzichtigheid)", abs($adj));
        } elseif ($adj > 0) {
            $lines[] = sprintf("✅ RISICO-BONUS: RSI-drempel verhoogd met %.0f punt (doelen zijn op schema)", $adj);
        }

        return implode("\n", $lines);
    }

    /**
     * Geef alle doelen terug met berekende voortgang (voor dashboard).
     *
     * @return list<array{id: string, label: string, target_eur: float, current_eur: float, progress_pct: float, days_remaining: int, priority: string, urgent: bool}>
     */
    public function goalsWithProgress(): array
    {
        $profile = $this->load();
        if ($profile === null || empty($profile['goals'])) {
            return [];
        }

        $now    = time();
        $result = [];

        foreach ($profile['goals'] as $goal) {
            $targetEur  = (float)($goal['target_eur']  ?? 0);
            $currentEur = (float)($goal['current_eur'] ?? 0);
            $deadline   = strtotime((string)($goal['deadline'] ?? '')) ?: 0;

            $progressPct   = $targetEur > 0 ? min(100.0, $currentEur / $targetEur * 100.0) : 0.0;
            $daysRemaining = $deadline > 0 ? max(0, (int)(($deadline - $now) / 86400)) : -1;

            $result[] = [
                'id'            => (string)($goal['id']       ?? ''),
                'label'         => (string)($goal['label']    ?? ''),
                'description'   => (string)($goal['description'] ?? ''),
                'target_eur'    => $targetEur,
                'current_eur'   => $currentEur,
                'progress_pct'  => round($progressPct, 1),
                'days_remaining'=> $daysRemaining,
                'priority'      => strtolower((string)($goal['priority'] ?? 'medium')),
                'urgent'        => $daysRemaining >= 0 && $daysRemaining <= 90,
            ];
        }

        return $result;
    }

    /**
     * Update de huidige EUR-waarde van een doel (bijv. na vault-harvest).
     * Aanroepen vanuit CapitalManager of vault-harvest flow.
     */
    public function updateGoalProgress(string $goalId, float $currentEur): bool
    {
        $file = $this->basePath . '/' . self::PROFILE_FILE;
        if (!is_file($file)) {
            return false;
        }

        $profile = json_decode((string)file_get_contents($file), true);
        if (!is_array($profile)) {
            return false;
        }

        foreach ($profile['goals'] as &$goal) {
            if (($goal['id'] ?? '') === $goalId) {
                $goal['current_eur'] = $currentEur;
                $goal['_updated']    = date('c');
                break;
            }
        }
        unset($goal);

        $json = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        return @file_put_contents($file, $json . "\n", LOCK_EX) !== false;
    }

    /** @return array<string, mixed>|null */
    private function load(): ?array
    {
        $file = $this->basePath . '/' . self::PROFILE_FILE;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }
}
