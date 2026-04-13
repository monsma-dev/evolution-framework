<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Maps storage/evolution/twig_functions.json entries to Twig filters/functions using
 * a fixed PHP whitelist (no arbitrary code execution from JSON).
 */
final class EvolutionTwigBridge
{
    private const SPEC_PATH = 'storage/evolution/twig_functions.json';

    /** @var array<string, true> */
    private const ALLOWED_FILTER_HANDLERS = [
        'relative_time' => true,
        'identity' => true,
    ];

    /** @var array<string, true> */
    private const ALLOWED_FUNCTION_HANDLERS = [
        'cro_snippet' => true,
        'empty_string' => true,
    ];

    private static ?int $specMtime = null;

    /** @var array<string, mixed>|null */
    private static ?array $specCache = null;

    /**
     * @return array<string, mixed>
     */
    private static function loadSpec(): array
    {
        if (!defined('BASE_PATH')) {
            return ['version' => 1, 'filters' => [], 'functions' => []];
        }
        $path = BASE_PATH . '/' . self::SPEC_PATH;
        $mtime = is_file($path) ? (int) filemtime($path) : 0;
        if (self::$specCache !== null && self::$specMtime === $mtime) {
            return self::$specCache;
        }
        self::$specMtime = $mtime;
        if (!is_file($path)) {
            self::$specCache = ['version' => 1, 'filters' => [], 'functions' => []];

            return self::$specCache;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            self::$specCache = ['version' => 1, 'filters' => [], 'functions' => []];

            return self::$specCache;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::$specCache = ['version' => 1, 'filters' => [], 'functions' => []];

            return self::$specCache;
        }
        if (!is_array($decoded)) {
            self::$specCache = ['version' => 1, 'filters' => [], 'functions' => []];

            return self::$specCache;
        }
        self::$specCache = $decoded;

        return self::$specCache;
    }

    public static function resolveFilter(string $name, Container $container): TwigFilter|false
    {
        $spec = self::loadSpec();
        $filters = $spec['filters'] ?? null;
        if (!is_array($filters)) {
            return false;
        }
        $entry = $filters[$name] ?? null;
        if (!is_array($entry)) {
            return false;
        }
        $handlerId = strtolower(trim((string) ($entry['handler_id'] ?? '')));
        if ($handlerId === '' || !isset(self::ALLOWED_FILTER_HANDLERS[$handlerId])) {
            return false;
        }

        return new TwigFilter(
            $name,
            static function (mixed $value, mixed ...$args) use ($handlerId, $container): mixed {
                return self::dispatchFilter($handlerId, $value, $args, $container);
            }
        );
    }

    public static function resolveFunction(string $name, Container $container): TwigFunction|false
    {
        $spec = self::loadSpec();
        $functions = $spec['functions'] ?? null;
        if (!is_array($functions)) {
            return false;
        }
        $entry = $functions[$name] ?? null;
        if (!is_array($entry)) {
            return false;
        }
        $handlerId = strtolower(trim((string) ($entry['handler_id'] ?? '')));
        if ($handlerId === '' || !isset(self::ALLOWED_FUNCTION_HANDLERS[$handlerId])) {
            return false;
        }

        $safe = self::functionIsSafe($handlerId);

        return new TwigFunction(
            $name,
            static function (mixed ...$args) use ($handlerId, $container): mixed {
                return self::dispatchFunction($handlerId, $args, $container);
            },
            $safe ? ['is_safe' => ['html']] : []
        );
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function dispatchFilter(string $handlerId, mixed $value, array $args, Container $container): mixed
    {
        return match ($handlerId) {
            'relative_time' => self::filterRelativeTime($value),
            'identity' => $value,
            default => $value,
        };
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function dispatchFunction(string $handlerId, array $args, Container $container): mixed
    {
        return match ($handlerId) {
            'cro_snippet' => self::functionCroSnippet($container, $args),
            'empty_string' => '',
            default => '',
        };
    }

    private static function functionIsSafe(string $handlerId): bool
    {
        return in_array($handlerId, ['cro_snippet'], true);
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function functionCroSnippet(Container $container, array $args): string
    {
        $payload = isset($args[0]) && is_string($args[0]) ? $args[0] : '';

        return '<span class="evo-cro-offer" data-evo-cro="1" data-payload="'
            . htmlspecialchars($payload, ENT_QUOTES, 'UTF-8')
            . '"></span>';
    }

    private static function filterRelativeTime(mixed $value): string
    {
        $ts = self::coerceToTimestamp($value);
        if ($ts === null) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return 'zojuist';
        }
        if ($diff < 3600) {
            return ((int) floor($diff / 60)) . ' min geleden';
        }
        if ($diff < 86400) {
            return ((int) floor($diff / 3600)) . ' uur geleden';
        }

        return ((int) floor($diff / 86400)) . ' dagen geleden';
    }

    private static function coerceToTimestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $t = strtotime($value);

            return is_int($t) ? $t : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{ok: bool, error?: string}
     */
    public static function validateSpec(array $spec): array
    {
        foreach ($spec['filters'] ?? [] as $name => $entry) {
            if (!is_string($name) || !self::validTwigName($name)) {
                return ['ok' => false, 'error' => 'Ongeldige filter naam: ' . (string) $name];
            }
            if (!is_array($entry)) {
                return ['ok' => false, 'error' => 'Filter entry moet object zijn: ' . $name];
            }
            $hid = strtolower(trim((string) ($entry['handler_id'] ?? '')));
            if ($hid === '' || !isset(self::ALLOWED_FILTER_HANDLERS[$hid])) {
                return ['ok' => false, 'error' => 'Ongeldige handler_id voor filter ' . $name];
            }
        }
        foreach ($spec['functions'] ?? [] as $name => $entry) {
            if (!is_string($name) || !self::validTwigName($name)) {
                return ['ok' => false, 'error' => 'Ongeldige functienaam: ' . (string) $name];
            }
            if (!is_array($entry)) {
                return ['ok' => false, 'error' => 'Function entry moet object zijn: ' . $name];
            }
            $hid = strtolower(trim((string) ($entry['handler_id'] ?? '')));
            if ($hid === '' || !isset(self::ALLOWED_FUNCTION_HANDLERS[$hid])) {
                return ['ok' => false, 'error' => 'Ongeldige handler_id voor functie ' . $name];
            }
        }

        return ['ok' => true];
    }

    private static function validTwigName(string $name): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $name);
    }

    /**
     * Called after JSON merge so the next loadSpec() sees new mtime.
     */
    public static function bumpSpecCache(): void
    {
        self::$specMtime = null;
        self::$specCache = null;
    }
}
