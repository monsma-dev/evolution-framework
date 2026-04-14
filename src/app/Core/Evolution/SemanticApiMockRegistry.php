<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Stores degraded API flags + optional JSON mock payloads for graceful degradation (Stripe, etc.).
 */
final class SemanticApiMockRegistry
{
    private const STATE_FILE = 'storage/evolution/api_mocks/registry.json';

    /**
     * @return array<string, mixed>
     */
    public static function readState(): array
    {
        $p = BASE_PATH . '/' . self::STATE_FILE;
        if (!is_file($p)) {
            return ['apis' => []];
        }
        $j = @json_decode((string) @file_get_contents($p), true);

        return is_array($j) ? $j : ['apis' => []];
    }

    /**
     * @param array<string, mixed>|null $mockBody optional canned JSON shape for non-critical paths
     */
    public static function markDegraded(string $apiName, bool $degraded, ?array $mockBody = null): void
    {
        $apiName = strtolower(trim($apiName));
        if ($apiName === '') {
            return;
        }
        $state = self::readState();
        $apis = $state['apis'] ?? [];
        if (!is_array($apis)) {
            $apis = [];
        }
        $apis[$apiName] = [
            'degraded' => $degraded,
            'updated_at' => gmdate('c'),
            'mock_body' => $mockBody,
        ];
        $state['apis'] = $apis;

        $path = BASE_PATH . '/' . self::STATE_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $path,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    public static function isDegraded(string $apiName): bool
    {
        $apiName = strtolower(trim($apiName));
        $state = self::readState();
        $apis = $state['apis'] ?? [];
        if (!is_array($apis) || !isset($apis[$apiName])) {
            return false;
        }
        $row = $apis[$apiName];

        return is_array($row) && filter_var($row['degraded'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getMockBody(string $apiName): ?array
    {
        $apiName = strtolower(trim($apiName));
        $state = self::readState();
        $apis = $state['apis'] ?? [];
        if (!is_array($apis) || !isset($apis[$apiName])) {
            return null;
        }
        $row = $apis[$apiName];
        if (!is_array($row)) {
            return null;
        }
        $m = $row['mock_body'] ?? null;

        return is_array($m) ? $m : null;
    }
}
