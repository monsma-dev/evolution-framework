<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Persists client-reported first-input delay (FID-style) samples and rolls up for HealthSnapshot.
 */
final class EvolutionWebVitalsService
{
    private const JSONL = 'storage/evolution/web_vitals.jsonl';
    private const MAX_LINE_BYTES = 8192;
    private const MAX_FILE_BYTES = 4_194_304;

    public static function isEnabled(?Config $config): bool
    {
        if ($config === null) {
            return true;
        }
        $wv = $config->get('evolution.web_vitals_beacon', []);

        return !is_array($wv) || filter_var($wv['enabled'] ?? true, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function appendSample(array $payload): bool
    {
        if (!defined('BASE_PATH')) {
            return false;
        }
        $path = BASE_PATH . '/' . self::JSONL;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        if (is_file($path) && filesize($path) > self::MAX_FILE_BYTES) {
            @rename($path, $path . '.' . gmdate('Ymd_His') . '.bak');
        }

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line) || strlen($line) > self::MAX_LINE_BYTES) {
            return false;
        }

        return @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Container $container): array
    {
        $cfg = $container->get('config');
        if (!$cfg instanceof Config) {
            return ['enabled' => false];
        }
        if (!self::isEnabled($cfg)) {
            return ['enabled' => false];
        }
        $path = BASE_PATH . '/' . self::JSONL;
        if (!is_file($path)) {
            return [
                'enabled' => true,
                'samples_24h' => 0,
                'fid_p75_ms' => null,
                'fid_max_ms' => null,
            ];
        }

        $cutoff = time() - 86400;
        $fids = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return ['enabled' => true, 'samples_24h' => 0, 'fid_p75_ms' => null, 'fid_max_ms' => null];
        }
        foreach ($lines as $line) {
            try {
                $j = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($j)) {
                continue;
            }
            $ts = (int) ($j['ts'] ?? 0);
            if ($ts < $cutoff) {
                continue;
            }
            $fid = $j['fid_ms'] ?? null;
            if (!is_numeric($fid)) {
                continue;
            }
            $v = (float) $fid;
            if ($v < 0 || $v > 60000) {
                continue;
            }
            $fids[] = $v;
        }

        $n = count($fids);
        if ($n === 0) {
            return [
                'enabled' => true,
                'samples_24h' => 0,
                'fid_p75_ms' => null,
                'fid_max_ms' => null,
            ];
        }
        sort($fids);
        $p75Idx = (int) floor(0.75 * ($n - 1));

        return [
            'enabled' => true,
            'samples_24h' => $n,
            'fid_p75_ms' => round($fids[$p75Idx], 2),
            'fid_max_ms' => round($fids[$n - 1], 2),
        ];
    }
}
