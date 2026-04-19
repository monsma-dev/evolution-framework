<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use PDO;
use PDOException;

/**
 * Safe .env mutations: versioned backup + HotSwap arm before write, optional DB handshake after
 * DB_HOST / DB_PORT / DB_DATABASE / MYSQL_ATTR changes — rollback on connection failure.
 *
 * StructuralRefactorService does not edit .env; AI/env patches should use EvolutionConfigService::updateEnvKeys().
 */
final class EvolutionEnvGuardService
{
    private const DB_KEYS = [
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'DB_SOCKET', 'MYSQL_ATTR_SSL_CA',
    ];

    /**
     * @param array<string, string> $keyValues KEY => raw value (no surrounding quotes required)
     * @return array{ok: bool, error?: string, backup?: string}
     */
    public static function applyKeyUpdates(Container $container, array $keyValues): array
    {
        $cfg = $container->get('config');
        $eg = $cfg->get('evolution.env_guard', []);
        if (!is_array($eg) || !filter_var($eg['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'evolution.env_guard.enabled is false'];
        }
        if ($keyValues === []) {
            return ['ok' => false, 'error' => 'no keys'];
        }

        $path = BASE_PATH . '/.env';
        if (!is_file($path)) {
            return ['ok' => false, 'error' => '.env not found'];
        }

        $touchesDb = false;
        foreach (array_keys($keyValues) as $k) {
            if (in_array(strtoupper((string) $k), self::DB_KEYS, true)) {
                $touchesDb = true;
                break;
            }
        }

        $backup = HotSwapService::backupArbitraryFile($path);
        if ($backup === null) {
            return ['ok' => false, 'error' => 'cannot backup .env before mutation'];
        }

        HotSwapService::arm('__dotenv__', $path, $backup);

        $raw = (string) @file_get_contents($path);
        $next = self::mergeEnvLines($raw, $keyValues);
        if (@file_put_contents($path, $next) === false) {
            @copy($backup, $path);
            HotSwapService::disarm();

            return ['ok' => false, 'error' => 'cannot write .env'];
        }

        if ($touchesDb && filter_var($eg['verify_db_after_change'] ?? true, FILTER_VALIDATE_BOOL)) {
            if (!self::verifyMysqlFromEnvContent($next)) {
                @copy($backup, $path);
                HotSwapService::disarm();
                EvolutionLogger::log('env_guard', 'rollback_db_failed', []);

                return ['ok' => false, 'error' => 'Database connection failed after .env change — restored previous .env', 'backup' => $backup];
            }
        }

        HotSwapService::disarm();
        EvolutionLogger::log('env_guard', 'env_updated', ['keys' => array_keys($keyValues)]);

        return ['ok' => true, 'backup' => $backup];
    }

    /**
     * @param array<string, string> $keyValues
     */
    private static function mergeEnvLines(string $content, array $keyValues): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $keysDone = [];
        $out = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                $out[] = $line;

                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
                $key = $m[1];
                if (array_key_exists($key, $keyValues)) {
                    $out[] = $key . '=' . self::escapeEnvValue((string) $keyValues[$key]);
                    $keysDone[$key] = true;

                    continue;
                }
            }
            $out[] = $line;
        }
        foreach ($keyValues as $k => $v) {
            if (!isset($keysDone[$k])) {
                $out[] = $k . '=' . self::escapeEnvValue((string) $v);
            }
        }

        return implode("\n", $out) . "\n";
    }

    private static function escapeEnvValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }
        if (preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    private static function verifyMysqlFromEnvContent(string $content): bool
    {
        $vars = self::parseEnvBlock($content);
        $driver = strtolower((string) ($vars['DB_CONNECTION'] ?? 'mysql'));
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return true;
        }
        $host = (string) ($vars['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($vars['DB_PORT'] ?? '3306');
        $db = (string) ($vars['DB_DATABASE'] ?? '');
        $user = (string) ($vars['DB_USERNAME'] ?? '');
        $pass = (string) ($vars['DB_PASSWORD'] ?? '');
        if ($db === '' || $user === '') {
            return false;
        }
        $socket = trim((string) ($vars['DB_SOCKET'] ?? ''));
        if ($socket !== '') {
            $dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . $db . ';charset=utf8mb4';
        } else {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4';
        }
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->query('SELECT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function parseEnvBlock(string $content): array
    {
        $vars = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
                $vars[$m[1]] = self::unquoteEnvValue(trim($m[2]));
            }
        }

        return $vars;
    }

    private static function unquoteEnvValue(string $v): string
    {
        if (strlen($v) >= 2 && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
            return stripcslashes(substr($v, 1, -1));
        }

        return $v;
    }
}
