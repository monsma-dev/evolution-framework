<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use Throwable;

/**
 * Removes shadow patches if errors recur within a configurable window (code circuit breaker).
 */
final class PatchCircuitBreaker
{
    /**
     * If the exception originated from a patched file under storage/patches, record failure
     * and remove the patch when the breaker trips.
     */
    public static function evaluateThrowable(Throwable $e): void
    {
        $file = $e->getFile();
        $patchRoot = realpath(BASE_PATH . '/data/patches');
        if ($patchRoot === false) {
            return;
        }
        $normFile = realpath($file);
        if ($normFile === false || !str_starts_with($normFile, $patchRoot)) {
            return;
        }

        $relative = substr($normFile, strlen($patchRoot) + 1);
        if ($relative === false || $relative === '') {
            return;
        }
        $fqcn = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

        $minutes = 5;
        if (isset(($GLOBALS)['app_container'])) {
            try {
                $cfg = ($GLOBALS)['app_container']->get('config');
                $minutes = max(1, (int)$cfg->get('evolution.self_heal.circuit_breaker_minutes', 5));
            } catch (\Throwable) {
            }
        }
        $window = $minutes * 60;

        $metaDir = BASE_PATH . '/data/patches/.meta';
        if (!is_dir($metaDir)) {
            @mkdir($metaDir, 0755, true);
        }
        $hash = hash('sha256', $fqcn);
        $metaFile = $metaDir . '/' . $hash . '.json';

        $now = time();
        $prev = [];
        if (is_file($metaFile)) {
            $raw = @file_get_contents($metaFile);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $prev = $decoded;
                }
            }
        }

        $firstAt = (int)($prev['first_error_at'] ?? $now);
        if ($now - $firstAt > $window) {
            $firstAt = $now;
        }

        $errors = (int)($prev['error_count'] ?? 0) + 1;
        $payload = [
            'fqcn' => $fqcn,
            'first_error_at' => $firstAt,
            'last_error_at' => $now,
            'error_count' => $errors,
            'last_message' => $e->getMessage(),
        ];
        @file_put_contents($metaFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($errors >= 2 && ($now - $firstAt) <= $window) {
            SelfHealingManager::purgePatch($fqcn);
            EvolutionLogger::log('circuit_breaker', 'rolled back shadow patch', [
                'fqcn' => $fqcn,
                'errors' => $errors,
                'window_seconds' => $window,
            ]);
            @unlink($metaFile);
        }
    }
}
