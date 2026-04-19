<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;
use App\Domain\Web\Services\Payments\FinancialInsightService;

/**
 * Static framework text (README) + live Mollie/Stripe/tax snapshot for prompts & Anthropic cache.
 */
final class EvolutionFrameworkContext
{
    /**
     * README/handoff excerpt + optional live finance (when Container is passed — e.g. Claude consensus cache).
     */
    public static function load(Config $config, ?Container $container = null): string
    {
        $static = self::appendReadmeAndLocalDocs($config);
        if ($container === null) {
            return $static;
        }

        return $static . self::buildLiveFinanceBlock($config, $container);
    }

    /**
     * README (framework_context_file) + scanned markdown under docs/ for Architect / consensus.
     */
    public static function appendReadmeAndLocalDocs(Config $config): string
    {
        return self::loadStaticFile($config) . LocalDocScanner::compile($config);
    }

    /**
     * Only the live marketplace finance block (Tier 1 Architect chat — geen volledige README).
     */
    public static function appendLiveFinance(Config $config, Container $container): string
    {
        return self::buildLiveFinanceBlock($config, $container);
    }

    private static function buildLiveFinanceBlock(Config $config, Container $container): string
    {
        $arch = $config->get('evolution.architect', []);
        if (!is_array($arch) || !filter_var($arch['include_live_finance_snapshot'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $days = max(1, min(90, (int)($arch['live_finance_window_days'] ?? 7)));
        $svc = new FinancialInsightService($container);
        $block = $svc->executiveSnapshotText($days);
        if ($block === '') {
            return '';
        }

        return "\n\n" . $block;
    }

    private static function loadStaticFile(Config $config): string
    {
        $arch = $config->get('evolution.architect', []);
        if (!is_array($arch)) {
            $arch = [];
        }
        $rel = trim((string)($arch['framework_context_file'] ?? 'README.md'));
        $max = max(2000, min(100000, (int)($arch['framework_context_max_chars'] ?? 24000)));
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $full = $base . '/' . ltrim($rel, '/');
        if (!is_file($full)) {
            return '';
        }
        $raw = @file_get_contents($full);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($raw, 0, $max);
        }

        return substr($raw, 0, $max);
    }
}
