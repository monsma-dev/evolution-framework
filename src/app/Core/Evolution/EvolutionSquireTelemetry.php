<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Live telemetry for dual-runs (peak memory, current usage).
 */
final class EvolutionSquireTelemetry
{
    /**
     * @return array{current_bytes: int, peak_bytes: int, real_peak: bool}
     */
    public static function memorySnapshot(): array
    {
        return [
            'current_bytes' => memory_get_usage(true),
            'peak_bytes' => memory_get_peak_usage(true),
            'real_peak' => true,
        ];
    }

    public static function resetPeak(): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }
}
