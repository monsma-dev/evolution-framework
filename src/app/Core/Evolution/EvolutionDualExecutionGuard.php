<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Runs PHP vs native (FFI / extension function) in lockstep and compares normalized output.
 * Used before any native extension is allowed to shadow production logic.
 */
final class EvolutionDualExecutionGuard
{
    /**
     * @param callable(int): mixed $inputGenerator iteration index → input payload (scalar or array)
     * @param callable(mixed): mixed $phpFn
     * @param callable(mixed): mixed $nativeFn
     * @param callable(mixed): string|null $normalize optional; default JSON stable encode
     *
     * @param array{
     *   hard_cap_iterations?: int,
     *   strict_types?: bool,
     *   track_memory?: bool
     * } $options strict_types: require identical PHP types for PHP vs native outputs (recommended with integer-only hot paths).
     *
     * @return array{
     *   ok: bool,
     *   iterations: int,
     *   mismatches: int,
     *   first_mismatch?: array{iteration: int, php: string, native: string},
     *   php_ms_total: float,
     *   native_ms_total: float,
     *   peak_memory_bytes?: int,
     *   strict_types?: bool
     * }
     */
    public static function compare(
        int $iterations,
        callable $inputGenerator,
        callable $phpFn,
        callable $nativeFn,
        ?callable $normalize = null,
        array $options = []
    ): array {
        $hardCap = max(100, min(1_000_000, (int) ($options['hard_cap_iterations'] ?? 1_000_000)));
        $iterations = max(1, min($hardCap, $iterations));
        $strictTypes = (bool) ($options['strict_types'] ?? false);
        $trackMemory = (bool) ($options['track_memory'] ?? true);

        $normalize ??= static function (mixed $v): string {
            try {
                return (string) json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                return 'non_json:' . hash('sha256', print_r($v, true));
            }
        };

        $mismatches = 0;
        $first = null;
        $phpMs = 0.0;
        $nativeMs = 0.0;
        $peakMemory = memory_get_usage(true);

        for ($i = 0; $i < $iterations; $i++) {
            $input = $inputGenerator($i);

            $t0 = microtime(true);
            $outPhp = $phpFn($input);
            $phpMs += microtime(true) - $t0;

            $t1 = microtime(true);
            $outNative = $nativeFn($input);
            $nativeMs += microtime(true) - $t1;

            if ($strictTypes && gettype($outPhp) !== gettype($outNative)) {
                $mismatches++;
                if ($first === null) {
                    $first = [
                        'iteration' => $i,
                        'php' => 'type:' . gettype($outPhp),
                        'native' => 'type:' . gettype($outNative),
                    ];
                }

                continue;
            }

            $a = $normalize($outPhp);
            $b = $normalize($outNative);
            if ($a !== $b) {
                $mismatches++;
                if ($first === null) {
                    $first = [
                        'iteration' => $i,
                        'php' => mb_substr($a, 0, 2000),
                        'native' => mb_substr($b, 0, 2000),
                    ];
                }
            }
            if ($trackMemory) {
                $peakMemory = max($peakMemory, memory_get_usage(true));
            }
        }

        $out = [
            'ok' => $mismatches === 0,
            'iterations' => $iterations,
            'mismatches' => $mismatches,
            'first_mismatch' => $first,
            'php_ms_total' => round($phpMs * 1000, 3),
            'native_ms_total' => round($nativeMs * 1000, 3),
            'strict_types' => $strictTypes,
        ];
        if ($trackMemory) {
            $out['peak_memory_bytes'] = $peakMemory;
        }

        return $out;
    }
}
