<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Request-scoped timeline of slow operations (DB / outbound HTTP) for AI "Network" view.
 * Wire middleware later to push entries; Architect reads last request via snapshot API.
 */
final class EvolutionNetworkObserver
{
    /** @var list<array{ts: float, kind: string, label: string, ms: float, meta?: array<string, mixed>}> */
    private static array $buffer = [];

    public static function reset(): void
    {
        self::$buffer = [];
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function record(string $kind, string $label, float $ms, array $meta = []): void
    {
        self::$buffer[] = [
            'ts' => microtime(true),
            'kind' => $kind,
            'label' => $label,
            'ms' => $ms,
            'meta' => $meta,
        ];
        if (count(self::$buffer) > 200) {
            self::$buffer = array_slice(self::$buffer, -200);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function snapshot(): array
    {
        return self::$buffer;
    }
}
