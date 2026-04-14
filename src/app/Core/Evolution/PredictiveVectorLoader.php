<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Predictive Vector Loader — anticipate what the AI will need next.
 *
 * ─── The idea ────────────────────────────────────────────────────────────────
 *
 *  While the AI generates a patch for a Controller, this loader pre-warms the
 *  VectorMemory static cache for the related View, Model, and Test namespaces.
 *  When the AI moves to those files, the cache is already hot — zero disk reads.
 *
 *  In FrankenPHP worker mode, the static cache persists between requests,
 *  so a single warmForFile() call can benefit many subsequent requests.
 *
 * ─── File-type → namespace mapping ──────────────────────────────────────────
 *
 *  Controller   → global, bugfixes, security, debate
 *  Model        → global, bugfixes, security
 *  Service      → global, bugfixes
 *  Twig/View    → global, bugfixes
 *  Test         → global, bugfixes
 *  payment/*    → security, debate   (always)
 *  auth/*       → security, debate   (always)
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *  // Before processing a Controller — warm related namespaces
 *  PredictiveVectorLoader::warmForFile('src/app/Domain/Web/Controllers/Admin/ListingController.php');
 *
 *  // Before processing a high-risk task
 *  PredictiveVectorLoader::warmForTask('Implement Stripe webhook idempotency');
 *
 *  // Explicit warm (e.g. at worker boot)
 *  PredictiveVectorLoader::preload('global', 'security', 'bugfixes');
 */
final class PredictiveVectorLoader
{
    // ── File-pattern → namespace mapping ─────────────────────────────────────

    /** @var array<string, list<string>> */
    private const FILE_MAP = [
        'controller' => ['global', 'bugfixes', 'security', 'debate'],
        'model'      => ['global', 'bugfixes', 'security'],
        'service'    => ['global', 'bugfixes'],
        'repository' => ['global', 'bugfixes'],
        'twig'       => ['global', 'bugfixes'],
        'view'       => ['global', 'bugfixes'],
        'test'       => ['global', 'bugfixes'],
        'spec'       => ['global', 'bugfixes'],
        'migration'  => ['global', 'bugfixes'],
    ];

    /** @var list<string> These keywords in a path always add security + debate namespaces */
    private const HIGH_RISK_PATH_KEYWORDS = [
        'payment', 'stripe', 'mollie', 'checkout', 'invoice',
        'auth', 'login', 'register', 'password', 'token', 'oauth',
        'webhook', 'escrow', 'transaction', 'crypto',
    ];

    /** @var list<string> These keywords in task text always add security + debate namespaces */
    private const HIGH_RISK_TASK_KEYWORDS = [
        'payment', 'stripe', 'mollie', 'auth', 'login', 'security',
        'webhook', 'password', 'token', 'sql injection', 'xss', 'csrf',
        'escrow', 'checkout', 'invoice', 'transaction',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Pre-warm all namespaces relevant to the given file path.
     * Optionally provide a task description for additional hints.
     */
    public static function warmForFile(string $filePath, string $taskHint = ''): void
    {
        $namespaces = self::namespacesForFile($filePath, $taskHint);
        self::preload(...$namespaces);
    }

    /**
     * Pre-warm namespaces relevant to a free-text task description.
     */
    public static function warmForTask(string $taskDescription): void
    {
        $namespaces = self::namespacesForText($taskDescription);
        self::preload(...$namespaces);
    }

    /**
     * Pre-warm when you know the risk tag (e.g. from DebateOrchestrator).
     * High-risk tags always warm security + debate.
     */
    public static function warmForRiskTag(string $riskTag): void
    {
        $ns = ['global'];

        $highRisk = ['payment', 'stripe', 'mollie', 'checkout', 'invoice',
                     'auth', 'login', 'register', 'password', '2fa', 'oauth',
                     'security', 'webhook', 'escrow', 'transaction', 'crypto'];

        if (in_array(strtolower($riskTag), $highRisk, true)) {
            $ns[] = 'security';
            $ns[] = 'debate';
        }

        $ns[] = 'bugfixes';
        self::preload(...array_unique($ns));
    }

    /**
     * Explicitly preload one or more namespaces into the static in-process cache.
     * Each namespace is loaded from disk once per worker lifetime.
     *
     * Cost: one disk read per namespace (if not already cached), then zero.
     */
    public static function preload(string ...$namespaces): void
    {
        foreach (array_unique($namespaces) as $ns) {
            if ($ns === '') {
                continue;
            }
            // Calling count() triggers load() which populates VectorMemoryService::$processCache
            (new VectorMemoryService($ns))->count();
        }
    }

    /**
     * Report which namespaces are currently warm (in-process cache).
     *
     * @return list<string>
     */
    public static function warmedNamespaces(): array
    {
        return VectorMemoryService::warmedNamespaces();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private static function namespacesForFile(string $filePath, string $taskHint): array
    {
        $lower = strtolower(str_replace('\\', '/', $filePath));
        $ns    = ['global'];

        // File-type mapping
        foreach (self::FILE_MAP as $keyword => $spaces) {
            if (str_contains($lower, $keyword)) {
                $ns = array_merge($ns, $spaces);
            }
        }

        // High-risk path keywords
        foreach (self::HIGH_RISK_PATH_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                $ns[] = 'security';
                $ns[] = 'debate';
                break;
            }
        }

        // Merge task hint namespaces
        if ($taskHint !== '') {
            $ns = array_merge($ns, self::namespacesForText($taskHint));
        }

        return array_values(array_unique($ns));
    }

    /**
     * @return list<string>
     */
    private static function namespacesForText(string $text): array
    {
        $lower = strtolower($text);
        $ns    = ['global'];

        foreach (self::HIGH_RISK_TASK_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                $ns[] = 'security';
                $ns[] = 'debate';
                break;
            }
        }

        if (str_contains($lower, 'bug')
            || str_contains($lower, 'fix')
            || str_contains($lower, 'error')
            || str_contains($lower, 'crash')) {
            $ns[] = 'bugfixes';
        }

        return array_values(array_unique($ns));
    }
}
