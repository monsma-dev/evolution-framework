<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Kiest het chat-model op basis van task-severity (Budget-Optimizer / model tiering).
 * Alleen actief als evolution.architect.model_router.enabled true is.
 */
final class ModelRouterService
{
    /**
     * @param array<string, mixed> $arch evolution.architect
     */
    public static function isRouterEnabled(array $arch): bool
    {
        $mr = $arch['model_router'] ?? [];

        return is_array($mr) && filter_var($mr['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, mixed> $arch
     *
     * @return array{model: string, tier: string, router: bool}
     */
    public static function pickCoreModel(
        array $arch,
        ?string $taskSeverity,
        string $fallbackModel,
        string $cheapCore,
        string $tier1,
        bool $tier1Only
    ): array {
        $tier1 = $tier1 !== '' ? $tier1 : $cheapCore;

        if (!self::isRouterEnabled($arch)) {
            if ($tier1Only) {
                return ['model' => $tier1, 'tier' => 'tier1_lock', 'router' => false];
            }

            return ['model' => $fallbackModel, 'tier' => 'fallback', 'router' => false];
        }

        $mr = is_array($arch['model_router'] ?? null) ? $arch['model_router'] : [];
        $defaultSev = trim((string)($mr['default_severity'] ?? 'standard'));
        $tier = self::normalizeTier($taskSeverity ?? $defaultSev);

        $light = trim((string)($mr['light_model'] ?? $cheapCore));
        $standard = trim((string)($mr['standard_model'] ?? $fallbackModel));
        $premium = trim((string)($mr['premium_model'] ?? 'gpt-4o'));

        if ($light === '') {
            $light = $cheapCore;
        }
        if ($standard === '') {
            $standard = $fallbackModel;
        }
        if ($premium === '') {
            $premium = $standard;
        }

        $model = match ($tier) {
            'light' => $light,
            'premium' => $premium,
            default => $standard,
        };

        return ['model' => $model, 'tier' => $tier, 'router' => true];
    }

    /**
     * @param array<string, mixed> $arch
     *
     * @return array{model: string, tier: string, router: bool}
     */
    public static function pickUxModel(
        array $arch,
        ?string $taskSeverity,
        string $uxModel,
        string $cheapUx,
        string $tier1Ux,
        bool $tier1Only
    ): array {
        $tier1Ux = $tier1Ux !== '' ? $tier1Ux : $cheapUx;

        if (!self::isRouterEnabled($arch)) {
            if ($tier1Only) {
                return ['model' => $tier1Ux, 'tier' => 'tier1_lock', 'router' => false];
            }

            return ['model' => $uxModel, 'tier' => 'fallback', 'router' => false];
        }

        $mr = is_array($arch['model_router'] ?? null) ? $arch['model_router'] : [];
        $defaultSev = trim((string)($mr['default_severity'] ?? 'standard'));
        $tier = self::normalizeTier($taskSeverity ?? $defaultSev);

        $light = trim((string)($mr['ux_light_model'] ?? $cheapUx));
        $standard = trim((string)($mr['ux_standard_model'] ?? $uxModel));
        $premium = trim((string)($mr['ux_premium_model'] ?? $uxModel));

        if ($light === '') {
            $light = $cheapUx;
        }
        if ($standard === '') {
            $standard = $uxModel;
        }
        if ($premium === '') {
            $premium = $standard;
        }

        $model = match ($tier) {
            'light' => $light,
            'premium' => $premium,
            default => $standard,
        };

        return ['model' => $model, 'tier' => $tier, 'router' => true];
    }

    /**
     * light = bijna gratis (typo, kleine CSS); standard = bugfix / normale refactor; premium = architectuur / grote feature.
     */
    public static function normalizeTier(string $severity): string
    {
        $s = strtolower(trim($severity));
        if ($s === '') {
            return 'standard';
        }

        if (in_array($s, ['light', 'low', 'typo', 'trivial', 'css', 'ui', 'ui_tweak', 'mini', 'small'], true)) {
            return 'light';
        }
        if (in_array($s, ['premium', 'high', 'architecture', 'arch', 'supreme', 'feature', 'major', 'large'], true)) {
            return 'premium';
        }

        return 'standard';
    }
}
