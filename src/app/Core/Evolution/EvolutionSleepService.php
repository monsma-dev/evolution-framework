<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Evolution Downtime / Rhythm — night construction (00:00–06:00), day cognitive study (deep scans + Study-Log wiki),
 * Zen free-play window (Refactor-Relaxation / code-dust), monthly context flush (vacation).
 *
 * Cognitive Study (day): Architect defers mutating severities; use EvolutionWikiService::appendCognitiveStudyLog /
 * appendSquireReadiness so the night crew gets improvement bullets and up-to-speed docs.
 * Zen: use severity zen_sandbox or mode zen in-window; log with appendZenHarmonyLine (default Zen-state DNA line).
 */
final class EvolutionSleepService
{
    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        if (!is_array($evo)) {
            return null;
        }
        $s = $evo['sleep'] ?? [];

        return is_array($s) && filter_var($s['enabled'] ?? true, FILTER_VALIDATE_BOOL) ? $s : null;
    }

    public static function nowInTimezone(Config $config): DateTimeImmutable
    {
        $s = self::cfg($config) ?? [];
        $tzName = (string) ($s['timezone'] ?? 'UTC');

        try {
            $tz = new DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        return new DateTimeImmutable('now', $tz);
    }

    /**
     * Night work window (default 00:00–06:00 local): Shadow patches, Rust compiles, heavy changes.
     * Aliased as "hibernation" in older configs via hibernation_* hour keys (fallback).
     */
    public static function inNightWorkWindow(Config $config): bool
    {
        $s = self::cfg($config);
        if ($s === null) {
            return false;
        }
        $start = max(0, min(23, (int) ($s['night_work_start_hour'] ?? $s['hibernation_start_hour'] ?? 0)));
        $end = max(0, min(24, (int) ($s['night_work_end_hour'] ?? $s['hibernation_end_hour'] ?? 6)));
        $now = self::nowInTimezone($config);
        $h = (int) $now->format('G');

        if ($start < $end) {
            return $h >= $start && $h < $end;
        }

        return $h >= $start || $h < $end;
    }

    /**
     * @deprecated Use inNightWorkWindow(); kept for callers / JSON compatibility.
     */
    public static function inHibernationWindow(Config $config): bool
    {
        return self::inNightWorkWindow($config);
    }

    /**
     * Day = outside night window — cognitive study phase when enabled (no code writes except allow-list).
     */
    public static function isCognitiveStudyDayPhase(Config $config): bool
    {
        $s = self::cfg($config);
        if ($s === null) {
            return false;
        }
        $st = $s['cognitive_study'] ?? [];

        return is_array($st) && filter_var($st['enabled'] ?? false, FILTER_VALIDATE_BOOL)
            && !self::inNightWorkWindow($config);
    }

    /**
     * Zen "Refactor-Relaxation" — fixed daily window for sandbox free-play (default 30 min).
     */
    public static function inZenFreePlayWindow(Config $config): bool
    {
        $s = self::cfg($config);
        if ($s === null) {
            return false;
        }
        $z = $s['zen_mode'] ?? [];
        if (!is_array($z) || !filter_var($z['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return false;
        }
        $zh = max(0, min(23, (int) ($z['start_hour'] ?? 12)));
        $zm = max(0, min(59, (int) ($z['start_minute'] ?? 0)));
        $dur = max(5, min(180, (int) ($z['duration_minutes'] ?? 30)));
        $now = self::nowInTimezone($config);
        $start = $now->setTime($zh, $zm, 0);
        $end = $start->add(new DateInterval('PT' . $dur . 'M'));

        return $now >= $start && $now < $end;
    }

    /**
     * Monthly "meta fatigue" prevention — context flush window (default 1st of month, 05:00 local).
     */
    public static function isMonthlyVacationFlushDue(Config $config): bool
    {
        $s = self::cfg($config);
        if ($s === null) {
            return false;
        }
        $v = $s['monthly_context_flush'] ?? [];
        if (!is_array($v) || !filter_var($v['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }
        $dom = max(1, min(28, (int) ($v['day_of_month'] ?? 1)));
        $hour = max(0, min(23, (int) ($v['hour'] ?? 5)));
        $now = self::nowInTimezone($config);
        if ((int) $now->format('j') !== $dom) {
            return false;
        }
        if ((int) $now->format('G') !== $hour) {
            return false;
        }
        $m = (int) $now->format('i');

        return $m < 30;
    }

    /**
     * Night = inside night work window. Day = outside.
     */
    public static function getRhythmMode(Config $config): string
    {
        return self::inNightWorkWindow($config) ? 'night' : 'day';
    }

    /**
     * @return 'lax'|'high'
     */
    public static function getPoliceAlertLevel(Config $config): string
    {
        $s = self::cfg($config);
        if ($s === null) {
            return 'high';
        }
        if (self::inNightWorkWindow($config)) {
            return 'lax';
        }

        return 'high';
    }

    public static function shouldBlockConsensusDuringHibernation(Config $config): bool
    {
        $s = self::cfg($config);
        if ($s === null) {
            return false;
        }

        return filter_var($s['block_consensus_during_hibernation'] ?? false, FILTER_VALIDATE_BOOL)
            && self::inNightWorkWindow($config);
    }

    public static function isMaintenanceFlushDue(Config $config): bool
    {
        $s = self::cfg($config);
        if ($s === null) {
            return false;
        }
        $hour = max(0, min(23, (int) ($s['maintenance_flush_hour'] ?? 6)));
        $minute = max(0, min(59, (int) ($s['maintenance_flush_minute'] ?? 0)));
        $now = self::nowInTimezone($config);
        if ((int) $now->format('G') !== $hour) {
            return false;
        }
        $m = (int) $now->format('i');

        return $m >= $minute && $m < $minute + 15;
    }

    /**
     * Architect API gate: police cell is checked separately in ArchitectChatService.
     *
     * @return array{defer: bool, reason?: string, rhythm?: string, night?: bool, cognitive_study?: bool, zen?: bool, hibernation?: bool}
     */
    public static function shouldDeferArchitectCall(Config $config, string $mode, ?string $taskSeverity): array
    {
        $s = self::cfg($config);
        if ($s === null) {
            return ['defer' => false];
        }

        $night = self::inNightWorkWindow($config);
        $rhythm = self::getRhythmMode($config);
        $sev = $taskSeverity ?? '';

        if (self::inZenFreePlayWindow($config) && ($sev === 'zen_sandbox' || $mode === 'zen')) {
            return ['defer' => false, 'rhythm' => $rhythm, 'zen' => true, 'night' => $night];
        }

        $study = $s['cognitive_study'] ?? [];
        $studyOn = is_array($study) && filter_var($study['enabled'] ?? false, FILTER_VALIDATE_BOOL);

        if ($studyOn && !$night) {
            $allow = $study['allow_severities'] ?? ['study_log', 'squire_prep', 'study_scan', 'deep_scan'];
            $block = $study['block_write_severities'] ?? [
                'ui_autofix', 'low_autofix', 'evolution_routing', 'critical_autofix', 'evolution_assets', 'shadow_patch',
            ];
            if (!is_array($allow)) {
                $allow = [];
            }
            if (!is_array($block)) {
                $block = [];
            }
            if ($sev !== '' && in_array($sev, $allow, true)) {
                return ['defer' => false, 'rhythm' => $rhythm, 'cognitive_study' => true];
            }
            if ($sev !== '' && in_array($sev, $block, true)) {
                return [
                    'defer' => true,
                    'reason' => 'Cognitive Study Mode (day): Deep Scans + Study-Log wiki only — night crew ships patches.',
                    'rhythm' => $rhythm,
                    'cognitive_study' => true,
                ];
            }
            if ($mode === 'study') {
                return ['defer' => false, 'rhythm' => $rhythm, 'cognitive_study' => true];
            }
        }

        if ($night) {
            $saveUx = $s['night_save_credits_block_modes'] ?? [];
            if (is_array($saveUx) && $saveUx !== [] && in_array($mode, $saveUx, true)) {
                return [
                    'defer' => true,
                    'reason' => 'Night: optional UX defer (credit saving).',
                    'rhythm' => $rhythm,
                    'night' => true,
                ];
            }

            return ['defer' => false, 'rhythm' => $rhythm, 'night' => true];
        }

        $dayBlock = $s['day_block_heavy_severities'] ?? ['ui_autofix', 'evolution_routing'];
        $allowDay = $s['day_allow_always_severities'] ?? ['critical_autofix'];
        if (is_array($dayBlock) && $sev !== '' && in_array($sev, $dayBlock, true)) {
            if (is_array($allowDay) && in_array($sev, $allowDay, true)) {
                return ['defer' => false, 'rhythm' => $rhythm];
            }

            return [
                'defer' => true,
                'reason' => 'Day stability: heavy Architect work deferred (emergency severities still allowed).',
                'rhythm' => $rhythm,
            ];
        }

        return ['defer' => false, 'rhythm' => $rhythm];
    }

    /**
     * @return array<string, mixed>
     */
    public static function runMaintenanceFlush(Config $config): array
    {
        $s = self::cfg($config);
        if ($s === null) {
            return ['ok' => false, 'error' => 'sleep disabled'];
        }
        $paths = $s['maintenance_clear_relative_paths'] ?? [];
        if (!is_array($paths)) {
            $paths = [];
        }
        $cleared = [];
        foreach ($paths as $rel) {
            if (!is_string($rel) || str_contains($rel, '..')) {
                continue;
            }
            $full = BASE_PATH . '/' . ltrim(str_replace('\\', '/', $rel), '/');
            if (is_file($full)) {
                if (@unlink($full)) {
                    $cleared[] = $rel;
                }
            } elseif (is_dir($full)) {
                foreach (glob($full . '/*_temp_*.json') ?: [] as $f) {
                    if (is_string($f) && is_file($f) && @unlink($f)) {
                        $cleared[] = str_replace(BASE_PATH . '/', '', $f);
                    }
                }
            }
        }
        EvolutionLogger::log('evolution_sleep', 'maintenance_flush', ['cleared' => count($cleared)]);

        return ['ok' => true, 'cleared' => $cleared, 'ts' => gmdate('c')];
    }

    /**
     * @return array<string, mixed>
     */
    public static function runMonthlyVacationFlush(Config $config): array
    {
        $s = self::cfg($config);
        if ($s === null) {
            return ['ok' => false, 'error' => 'sleep disabled'];
        }
        $v = $s['monthly_context_flush'] ?? [];
        if (!is_array($v) || !filter_var($v['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'monthly_context_flush disabled'];
        }
        $paths = $v['clear_relative_paths'] ?? [];
        if (!is_array($paths)) {
            $paths = [];
        }
        $cleared = [];
        foreach ($paths as $rel) {
            if (!is_string($rel) || str_contains($rel, '..')) {
                continue;
            }
            $full = BASE_PATH . '/' . ltrim(str_replace('\\', '/', $rel), '/');
            if (is_file($full) && @unlink($full)) {
                $cleared[] = $rel;
            }
        }
        EvolutionWikiService::appendCognitiveStudyLog(
            $config,
            'Monthly Context Flush (vacation)',
            'Architect context scratch cleared to reduce meta-fatigue. Squire + wiki remain canonical.',
            ['Session reset — resume with fresh retrieval next cycle.']
        );
        EvolutionLogger::log('evolution_sleep', 'monthly_vacation_flush', ['cleared' => count($cleared)]);

        return ['ok' => true, 'cleared' => $cleared, 'ts' => gmdate('c')];
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(Config $config): array
    {
        $s = self::cfg($config);
        if ($s === null) {
            return ['enabled' => false];
        }
        $now = self::nowInTimezone($config);

        return [
            'enabled' => true,
            'ts' => gmdate('c'),
            'local_time' => $now->format('c'),
            'timezone' => $now->getTimezone()->getName(),
            'night_work_window' => self::inNightWorkWindow($config),
            'cognitive_study_day' => self::isCognitiveStudyDayPhase($config),
            'zen_free_play' => self::inZenFreePlayWindow($config),
            'monthly_vacation_due' => self::isMonthlyVacationFlushDue($config),
            'hibernation' => self::inNightWorkWindow($config),
            'rhythm' => self::getRhythmMode($config),
            'police_alert' => self::getPoliceAlertLevel($config),
            'maintenance_flush_due' => self::isMaintenanceFlushDue($config),
            'heartbeat_note' => 'Police + Squire minimal heartbeat; deep wiki indexing when spend is €0.',
            'study_note' => 'Day: Deep Scans without writes; Study-Log + Squire prep for night crew.',
            'zen_note' => 'After study: Zen-mode sandbox — Framework is in Zen-state: Harmonizing DNA.',
        ];
    }
}
