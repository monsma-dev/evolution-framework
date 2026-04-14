<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use PDO;

/**
 * SystemSettingsService — DB-driven runtime settings.
 *
 * Stores key/value pairs in the `system_settings` table.
 * Acts as an override layer on top of evolution.json / .env.
 *
 * Usage:
 *   SystemSettingsService::get($db, 'live_mode', false);
 *   SystemSettingsService::set($db, 'live_mode', true, $adminUserId);
 *   SystemSettingsService::isLive($db);
 */
final class SystemSettingsService
{
    public const KEY_LIVE_MODE          = 'live_mode';
    public const KEY_MONTHLY_BUDGET     = 'budget.monthly_eur';
    public const KEY_API_DEEPSEEK       = 'api.deepseek_key';
    public const KEY_API_ANTHROPIC      = 'api.anthropic_key';
    public const KEY_API_TAVILY         = 'api.tavily_key';
    public const KEY_API_FIGMA          = 'api.figma_key';
    public const KEY_API_GITHUB         = 'api.github_token';
    public const KEY_API_OPENAI         = 'api.openai_key';
    public const KEY_AGENT_MODE         = 'agents.mode';
    public const KEY_HUNTER_ENABLED     = 'agents.hunter_enabled';
    public const KEY_DESIGNER_ENABLED   = 'agents.designer_enabled';
    public const KEY_OUTREACH_ENABLED   = 'agents.outreach_enabled';
    public const KEY_PLATFORM_CLONE     = 'agents.platform_clone_enabled';
    public const KEY_PENDING_LAUNCHES   = 'incubator.pending_launches';

    private static ?array $cache = null;

    public static function isLive(PDO $db): bool
    {
        return (bool) self::get($db, self::KEY_LIVE_MODE, false);
    }

    /**
     * Returns decoded value or $default if not set.
     */
    public static function get(PDO $db, string $key, mixed $default = null): mixed
    {
        $all = self::loadAll($db);
        if (!isset($all[$key])) {
            return $default;
        }
        $decoded = json_decode($all[$key]['setting_val'], true);
        return $decoded ?? $default;
    }

    /**
     * Persist a setting. Value is JSON-encoded.
     */
    public static function set(PDO $db, string $key, mixed $value, int $updatedBy = 0, bool $isSecret = false): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare(
            'INSERT INTO system_settings (setting_key, setting_val, is_secret, updated_by)
             VALUES (:k, :v, :s, :u)
             ON DUPLICATE KEY UPDATE setting_val = :v, is_secret = :s, updated_by = :u'
        );
        $stmt->execute([':k' => $key, ':v' => $json, ':s' => (int)$isSecret, ':u' => $updatedBy]);
        self::$cache = null;
    }

    /**
     * @return array<string, array{setting_key:string, setting_val:string, is_secret:int}>
     */
    public static function all(PDO $db): array
    {
        return self::loadAll($db);
    }

    /**
     * Returns value masked as '••••••••' for secrets in UI.
     */
    public static function maskedValue(PDO $db, string $key): string
    {
        $all = self::loadAll($db);
        if (!isset($all[$key])) {
            return '';
        }
        if ($all[$key]['is_secret']) {
            $val = json_decode($all[$key]['setting_val'], true);
            if (is_string($val) && strlen($val) > 4) {
                return substr($val, 0, 4) . str_repeat('•', min(12, strlen($val) - 4));
            }
            return '••••••••';
        }
        return (string)json_decode($all[$key]['setting_val'], true);
    }

    /**
     * Increment spend counter (atomic).
     */
    public static function addSpend(PDO $db, float $amount): void
    {
        $current = (float)self::get($db, 'budget.spent_eur', 0.0);
        self::set($db, 'budget.spent_eur', round($current + $amount, 4));
    }

    public static function resetSpend(PDO $db, int $adminId): void
    {
        self::set($db, 'budget.spent_eur', 0.0, $adminId);
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string, array{setting_key:string, setting_val:string, is_secret:int}> */
    private static function loadAll(PDO $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            self::ensureTable($db);
            $rows = $db->query('SELECT setting_key, setting_val, is_secret FROM system_settings')->fetchAll(PDO::FETCH_ASSOC);
            $map  = [];
            foreach ($rows as $row) {
                $map[$row['setting_key']] = $row;
            }
            self::$cache = $map;
        } catch (\Throwable) {
            self::$cache = [];
        }
        return self::$cache;
    }

    private static function ensureTable(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `system_settings` (
                `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `setting_key` VARCHAR(120)  NOT NULL,
                `setting_val` TEXT          NOT NULL DEFAULT \'\',
                `is_secret`   TINYINT(1)    NOT NULL DEFAULT 0,
                `updated_by`  INT UNSIGNED  DEFAULT NULL,
                `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_key` (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
