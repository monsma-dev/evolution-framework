<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Weekly cap on autonomous high-severity applies (ISO week, stored in JSON).
 */
final class EvolutionaryBudgetService
{
    private const LEDGER = 'storage/evolution/evolutionary_budget.json';

    /**
     * @return array{enabled: bool, cap: int, used: int, remaining: int, week_id: string}
     */
    public static function status(Config $config): array
    {
        $st = self::settings($config);
        if (!$st['enabled']) {
            return ['enabled' => false, 'cap' => 0, 'used' => 0, 'remaining' => 0, 'week_id' => self::currentWeekId()];
        }
        $weekId = self::currentWeekId();
        $data = self::readLedger();
        $used = ($data['week_id'] ?? '') === $weekId ? (int)($data['high_severity_used'] ?? 0) : 0;

        return [
            'enabled' => true,
            'cap' => $st['cap'],
            'used' => $used,
            'remaining' => max(0, $st['cap'] - $used),
            'week_id' => $weekId,
        ];
    }

    public static function canConsumeHighSeverity(Config $config): bool
    {
        $s = self::status($config);

        return $s['enabled'] && $s['remaining'] > 0;
    }

    public static function recordHighSeverityApply(Config $config): void
    {
        $st = self::settings($config);
        if (!$st['enabled']) {
            return;
        }
        $weekId = self::currentWeekId();
        $data = self::readLedger();
        $used = ($data['week_id'] ?? '') === $weekId ? (int)($data['high_severity_used'] ?? 0) : 0;
        $used++;
        $payload = [
            'week_id' => $weekId,
            'high_severity_used' => $used,
            'updated_at' => gmdate('c'),
        ];
        $path = BASE_PATH . '/' . self::LEDGER;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        EvolutionLogger::log('evolutionary_budget', 'consume', ['week' => $weekId, 'used' => $used, 'cap' => $st['cap']]);
    }

    /**
     * @return array{enabled: bool, cap: int}
     */
    private static function settings(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $eb = is_array($evo) ? ($evo['evolutionary_budget'] ?? []) : [];

        return [
            'enabled' => is_array($eb) && filter_var($eb['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'cap' => max(0, (int)($eb['weekly_high_severity_cap'] ?? 5)),
        ];
    }

    /**
     * @return array{week_id?: string, high_severity_used?: int}
     */
    private static function readLedger(): array
    {
        $path = BASE_PATH . '/' . self::LEDGER;
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? @json_decode($raw, true) : null;

        return is_array($j) ? $j : [];
    }

    private static function currentWeekId(): string
    {
        return gmdate('o-\WW');
    }

    public static function promptAppend(Config $config): string
    {
        $s = self::status($config);
        if (!$s['enabled']) {
            return '';
        }

        return "\n\nEVOLUTIONARY_BUDGET: ISO week {$s['week_id']} — autonomous \"high\" applies remaining: {$s['remaining']}/{$s['cap']} (identical public API + min DNA gain required).";
    }
}
