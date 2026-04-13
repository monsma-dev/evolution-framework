<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Heuristiek: vendor-grootte / package-count vs Pulse-latency (geen runtime profiling per package).
 */
final class DependencyAnalyzer
{
    /**
     * @return array<string, mixed>
     */
    public static function analyze(Config $config): array
    {
        $da = self::cfg($config);
        if (!filter_var($da['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'skipped' => true];
        }

        $lockPath = BASE_PATH . '/composer.lock';
        $packages = 0;
        $names = [];
        if (is_file($lockPath)) {
            $raw = @file_get_contents($lockPath);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            $pk = is_array($j) && isset($j['packages']) && is_array($j['packages']) ? $j['packages'] : [];
            $packages = count($pk);
            foreach (array_slice($pk, 0, 40) as $p) {
                if (is_array($p) && isset($p['name'])) {
                    $names[] = (string)$p['name'];
                }
            }
        }

        $vendorBytes = self::dirSizeApprox(BASE_PATH . '/vendor', (int)($da['max_vendor_files_scan'] ?? 8000));
        $pulse = EvolutionPulseService::lastState();
        $pulseMs = (float)($pulse['latency_ms_total'] ?? 0);

        $heavy = $vendorBytes > (int)($da['vendor_bytes_warn'] ?? 80_000_000)
            || $packages > (int)($da['package_count_warn'] ?? 120);
        $pulseSlow = $pulseMs > (float)($da['pulse_ms_warn'] ?? 400);

        return [
            'ok' => true,
            'composer_packages' => $packages,
            'vendor_bytes_approx' => $vendorBytes,
            'vendor_mb_approx' => round($vendorBytes / 1048576, 2),
            'sample_packages' => $names,
            'pulse_latency_ms_total' => $pulseMs,
            'pulse_status' => $pulse['status'] ?? 'unknown',
            'warnings' => array_filter([
                $heavy ? 'Grote vendor tree of veel packages — overweeg dev-deps scheiden of autoload optimaliseren.' : null,
                $pulseSlow ? 'Pulse latency hoog — correleer met recente composer-wijzigingen / autoload.' : null,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cfg(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $d = is_array($evo) ? ($evo['dependency_analyzer'] ?? []) : [];
        if (!is_array($d)) {
            $d = [];
        }

        return array_merge([
            'enabled' => true,
            'max_vendor_files_scan' => 8000,
            'vendor_bytes_warn' => 80_000_000,
            'package_count_warn' => 120,
            'pulse_ms_warn' => 400.0,
        ], $d);
    }

    private static function dirSizeApprox(string $root, int $maxFiles): int
    {
        if (!is_dir($root)) {
            return 0;
        }
        $bytes = 0;
        $n = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $bytes += $file->getSize();
            $n++;
            if ($n >= $maxFiles) {
                break;
            }
        }

        return $bytes;
    }
}
