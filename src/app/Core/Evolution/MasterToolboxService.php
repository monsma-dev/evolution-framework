<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Divine Oversight: pre-API static analysis (complexity, AST-nesting, JIT hints, token guard, test saboteur heuristics).
 * Optional: Node worker visual/Lighthouse via nodeMasterAudit().
 */
final class MasterToolboxService
{
    /**
     * Full toolbox report for a PHP patch (+ optional PHPUnit snippet + FQCN for golden compare).
     *
     * @return array<string, mixed>
     */
    public static function analyzePhpPatch(
        Config $config,
        string $php,
        string $testPhp,
        ?string $fqcn
    ): array {
        $tb = $config->get('evolution.master_toolbox', []);
        if (!is_array($tb) || !filter_var($tb['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['enabled' => false];
        }

        $maxCc = max(5, min(50, (int) ($tb['max_cyclomatic'] ?? 10)));
        $maxNest = max(3, min(20, (int) ($tb['max_brace_nesting'] ?? 6)));
        $maxTok = max(2000, min(100000, (int) ($tb['token_guard_max_estimated_tokens'] ?? 14000)));

        $cc = self::approximateCyclomaticComplexity($php);
        $nest = self::maxBraceNesting($php);
        $ast = self::architectureAstScan($php, $maxNest);
        $jit = self::jitIntegrityScan($php);
        $tint = self::typeIntegrityScan($php, $tb);
        $tok = self::tokenGuard($php, $maxTok);
        $sab = self::testSaboteurHeuristic($php, $testPhp, $tb);
        $golden = $fqcn !== null && $fqcn !== ''
            ? self::goldenComparative($config, $fqcn, $php)
            : ['available' => false, 'reason' => 'no_fqcn'];

        $violations = [];
        if ($cc > $maxCc) {
            $violations[] = "cyclomatic_complexity {$cc} > {$maxCc}";
        }
        if ($nest > $maxNest) {
            $violations[] = "brace_nesting {$nest} > {$maxNest}";
        }
        foreach ($ast['violations'] ?? [] as $v) {
            $violations[] = $v;
        }
        foreach ($jit['violations'] ?? [] as $v) {
            $violations[] = $v;
        }
        foreach ($tint['violations'] ?? [] as $v) {
            $violations[] = $v;
        }
        if (!($tok['ok'] ?? true)) {
            $violations[] = (string) ($tok['reason'] ?? 'token_guard');
        }
        if (($sab['verdict'] ?? '') === 'fragile') {
            $violations[] = 'test_saboteur: tests likely too shallow to catch regressions';
        }

        $typeBlock = (bool) ($tint['should_block'] ?? false);

        $hardFail = ($cc > $maxCc) || ($nest > $maxNest) || !($tok['ok'] ?? true) || $typeBlock
            || (($tb['reject_fragile_tests'] ?? false) && (($sab['verdict'] ?? '') === 'fragile'));

        return [
            'enabled' => true,
            'cyclomatic_complexity' => $cc,
            'max_cyclomatic_allowed' => $maxCc,
            'brace_nesting' => $nest,
            'max_nesting_allowed' => $maxNest,
            'ast_scan' => $ast,
            'jit_integrity' => $jit,
            'type_integrity' => $tint,
            'token_guard' => $tok,
            'test_saboteur' => $sab,
            'golden_compare' => $golden,
            'violations' => $violations,
            'toolbox_blocks_apply' => $hardFail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function analyzeTwigTemplate(string $twig): array
    {
        $lines = substr_count($twig, "\n") + 1;
        $extends = preg_match_all('/\{%\s*extends\s+/i', $twig) ?: 0;
        $blocks = preg_match_all('/\{%\s*block\s+/i', $twig) ?: 0;
        $staticRatio = self::twigStaticRatio($twig);

        return [
            'lines' => $lines,
            'extends_count' => $extends,
            'block_count' => $blocks,
            'static_text_ratio_approx' => $staticRatio,
            'cacheable_segments_hint' => $staticRatio >= 0.35 && $blocks > 0
                ? 'Consider TwigEvolutionCompiler / static fragments for high static_ratio blocks.'
                : 'Dynamic-heavy template — cache wins may be limited.',
        ];
    }

    /**
     * POST to evolution-worker /master/audit (Playwright visual + optional Lighthouse).
     *
     * @return array<string, mixed>
     */
    public static function nodeMasterAudit(Config $config, string $pageUrl, array $selectors = ['body', 'a', 'button']): array
    {
        $tb = $config->get('evolution.master_toolbox', []);
        $node = is_array($tb) ? ($tb['node_audit'] ?? []) : [];
        if (!is_array($node) || !filter_var($node['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'skipped' => 'node_audit disabled'];
        }
        $base = rtrim((string) ($node['base_url'] ?? 'http://127.0.0.1:3791'), '/');
        $path = (string) ($node['path'] ?? '/master/audit');
        $key = (string) ($node['toolbox_key'] ?? getenv('EVOLUTION_TOOLBOX_KEY') ?: '');

        if (!str_starts_with($pageUrl, 'http://') && !str_starts_with($pageUrl, 'https://')) {
            return ['ok' => false, 'error' => 'invalid_page_url'];
        }

        $payload = json_encode([
            'url' => $pageUrl,
            'selectors' => array_values(array_slice($selectors, 0, 25)),
            'lighthouse' => filter_var($node['lighthouse'] ?? false, FILTER_VALIDATE_BOOL),
        ], JSON_UNESCAPED_UNICODE);

        $url = $base . $path;
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                    . ($key !== '' ? "X-Evolution-Toolbox-Key: {$key}\r\n" : ''),
                'content' => $payload,
                'timeout' => min(180, max(15, (int) ($node['timeout_seconds'] ?? 90))),
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'error' => 'node_audit_http_fail', 'url' => $url];
        }
        $j = json_decode($raw, true);

        return is_array($j) ? $j : ['ok' => false, 'error' => 'bad_json', 'raw' => substr($raw, 0, 500)];
    }

    public static function recordToolboxMilestoneIfNeeded(Container $container): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        $flag = BASE_PATH . '/storage/evolution/.master_toolbox_milestone_done';
        if (is_file($flag)) {
            return;
        }
        (new EvolutionHallOfFameService($container))->recordMilestone(
            'Master\'s Toolbox Active: The Eye of Providence is Open.',
            'milestone',
            ['master_toolbox' => true]
        );
        @file_put_contents($flag, gmdate('c') . "\n");
    }

    public static function recordLeanToolboxMilestoneIfNeeded(Container $container): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        $flag = BASE_PATH . '/storage/evolution/.lean_master_toolbox_milestone_done';
        if (is_file($flag)) {
            return;
        }
        (new EvolutionHallOfFameService($container))->recordMilestone(
            'Lean Master Toolbox Active: Efficiency & Elegance Synthesized.',
            'milestone',
            ['lean_master_toolbox' => true]
        );
        @file_put_contents($flag, gmdate('c') . "\n");
    }

    public static function recordNodeBridgeMilestoneIfNeeded(Container $container): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        $flag = BASE_PATH . '/storage/evolution/.node_master_bridge_milestone_done';
        if (is_file($flag)) {
            return;
        }
        (new EvolutionHallOfFameService($container))->recordMilestone(
            'Node-Master Bridge Active: Objective Reality Checks Established.',
            'milestone',
            ['node_master_bridge' => true]
        );
        @file_put_contents($flag, gmdate('c') . "\n");
    }

    /**
     * @return array{available: bool, similarity_percent?: float, golden_bytes?: int, new_bytes?: int, relative_path?: string, reason?: string}
     */
    private static function goldenComparative(Config $config, string $fqcn, string $newPhp): array
    {
        $rel = self::fqcnToRelativePath($fqcn);
        if ($rel === '') {
            return ['available' => false, 'reason' => 'fqcn_not_under_app'];
        }
        $golden = RespawnEngine::readRelativeFromLatestAnchor($config, $rel);
        if ($golden === null) {
            return ['available' => false, 'reason' => 'no_anchor_or_file_missing', 'relative_path' => $rel];
        }
        similar_text($golden, $newPhp, $pct);

        return [
            'available' => true,
            'relative_path' => $rel,
            'similarity_percent' => round((float) $pct, 2),
            'golden_bytes' => strlen($golden),
            'new_bytes' => strlen($newPhp),
        ];
    }

    private static function fqcnToRelativePath(string $fqcn): string
    {
        return self::relativePathFromFqcn($fqcn);
    }

    /**
     * Public for NeuroTemporalBridge / tooling.
     */
    public static function relativePathFromFqcn(string $fqcn): string
    {
        $fqcn = trim($fqcn);
        if (!str_starts_with($fqcn, 'App\\')) {
            return '';
        }
        $rest = substr($fqcn, strlen('App\\'));

        return 'src/app/' . str_replace('\\', '/', $rest) . '.php';
    }

    /**
     * TypeIntegrityGuard: loose equality (==), mixed overuse — hurts JIT specialization.
     *
     * @param array<string, mixed> $tb master_toolbox config
     *
     * @return array{violations: list<string>, loose_equality: int, mixed_hits: int, should_block: bool}
     */
    private static function typeIntegrityScan(string $php, array $tb): array
    {
        $ti = $tb['type_integrity'] ?? [];
        if (!is_array($ti) || !filter_var($ti['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['violations' => [], 'loose_equality' => 0, 'mixed_hits' => 0, 'should_block' => false];
        }

        $maxLoose = max(0, min(100, (int) ($ti['max_loose_equality'] ?? 5)));
        $maxMixed = max(0, min(200, (int) ($ti['max_mixed_hits'] ?? 8)));
        $enforce = filter_var($ti['enforce_block'] ?? false, FILTER_VALIDATE_BOOL);
        $rejectNoStrict = filter_var($ti['reject_without_strict_types'] ?? false, FILTER_VALIDATE_BOOL);

        $loose = self::countLooseEqualityTokens($php);
        $mixedHits = (int) (preg_match_all('/\bmixed\b/', $php) ?: 0);
        $hasStrict = str_contains($php, 'declare(strict_types=1)');

        $violations = [];
        if ($loose > $maxLoose) {
            $violations[] = "type_integrity: {$loose} loose (==) vergelijkingen — JIT verliest type-specialisatie; gebruik === of expliciete casts.";
        }
        if ($mixedHits > $maxMixed) {
            $violations[] = "type_integrity: mixed te vaak ({$mixedHits}) — vermijd type-pollution voor voorspelbare JIT-paden.";
        }
        if ($rejectNoStrict && !$hasStrict) {
            $violations[] = 'type_integrity: declare(strict_types=1) verplicht voor deze patch.';
        }

        $shouldBlock = $enforce && $violations !== [];

        return [
            'violations' => $violations,
            'loose_equality' => $loose,
            'mixed_hits' => $mixedHits,
            'strict_types' => $hasStrict,
            'should_block' => $shouldBlock,
        ];
    }

    private static function countLooseEqualityTokens(string $php): int
    {
        if (!function_exists('token_get_all')) {
            return 0;
        }
        $tokens = @token_get_all($php);
        if (!is_array($tokens)) {
            return 0;
        }
        $n = 0;
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_IS_EQUAL) {
                $n++;
            }
        }

        return $n;
    }

    private static function approximateCyclomaticComplexity(string $php): int
    {
        $s = preg_replace('/^\s*#.*$/m', '', $php);
        $s = preg_replace('/\/\*[\s\S]*?\*\//', '', (string) $s);
        $s = preg_replace('/\/\/.*$/m', '', (string) $s);
        $d = 0;
        $d += preg_match_all('/\bif\s*\(/', $s) ?: 0;
        $d += preg_match_all('/\belseif\s*\(/', $s) ?: 0;
        $d += preg_match_all('/\bcase\s+/i', $s) ?: 0;
        $d += preg_match_all('/\bcatch\s*\(/', $s) ?: 0;
        $d += preg_match_all('/\bwhile\s*\(/', $s) ?: 0;
        $d += preg_match_all('/\bfor\s*\(/', $s) ?: 0;
        $d += preg_match_all('/\bforeach\s*\(/', $s) ?: 0;
        $d += preg_match_all('/\b(and|or)\b/i', $s) ?: 0;
        $d += preg_match_all('/\?[^\n]+:/', $s) ?: 0;

        return 1 + (int) $d;
    }

    private static function maxBraceNesting(string $php): int
    {
        $max = 0;
        $depth = 0;
        $len = strlen($php);
        for ($i = 0; $i < $len; $i++) {
            $c = $php[$i];
            if ($c === '{') {
                $depth++;
                $max = max($max, $depth);
            } elseif ($c === '}') {
                $depth = max(0, $depth - 1);
            }
        }

        return $max;
    }

    /**
     * @return array{violations: list<string>, max_if_chain: int}
     */
    private static function architectureAstScan(string $php, int $maxNestAllowed): array
    {
        $violations = [];
        if ($maxNestAllowed > 0 && self::maxBraceNesting($php) > $maxNestAllowed) {
            $violations[] = 'ast: excessive brace nesting (Master wisdom: flatten / extract)';
        }
        $ifChain = preg_match_all('/\belseif\b/i', $php) ?: 0;
        if ($ifChain > 12) {
            $violations[] = 'ast: long elseif chain — prefer match/switch/strategy';
        }

        return ['violations' => $violations, 'max_if_chain' => (int) $ifChain];
    }

    /**
     * @return array{violations: list<string>, strict_types: bool, functions_without_return_type: int}
     */
    private static function jitIntegrityScan(string $php): array
    {
        $violations = [];
        $strict = str_contains($php, 'declare(strict_types=1)');
        if (!$strict) {
            $violations[] = 'jit: missing declare(strict_types=1); — JIT-friendly code uses strict typing';
        }
        $fnKw = (int) (preg_match_all('/\bfunction\s+/i', $php) ?: 0);
        $withRt = (int) (preg_match_all('/\)\s*:\s*(?:[a-zA-Z0-9_|\\\\\s?&]+)\s*\{/m', $php) ?: 0);
        $noRt = max(0, $fnKw - $withRt);
        if ($fnKw > 0 && $withRt < $fnKw) {
            $violations[] = "jit: ca. {$noRt} functie(s) zonder zichtbare return-type (heuristiek) — expliciete types helpen JIT.";
        }

        return [
            'violations' => $violations,
            'strict_types' => $strict,
            'functions_without_return_type' => $noRt,
        ];
    }

    /**
     * @return array{ok: bool, estimated_tokens: int, max: int, reason?: string}
     */
    private static function tokenGuard(string $php, int $maxEstimated): array
    {
        $est = (int) ceil(strlen($php) / 3.8);

        return $est > $maxEstimated
            ? ['ok' => false, 'estimated_tokens' => $est, 'max' => $maxEstimated, 'reason' => "token_guard: estimated {$est} > {$maxEstimated}"]
            : ['ok' => true, 'estimated_tokens' => $est, 'max' => $maxEstimated];
    }

    /**
     * Lightweight "mutation" mindset without running Infection: assertion density + negative-path hints.
     *
     * @param array<string, mixed> $tb
     * @return array{verdict: string, assertion_count: int, hints: list<string>}
     */
    private static function testSaboteurHeuristic(string $php, string $testPhp, array $tb): array
    {
        $asserts = preg_match_all('/\b(assert[A-Za-z0-9_]*|expectException)\b/', $testPhp) ?: 0;
        $hints = [];
        $neg = preg_match_all('/\b(assertFalse|assertNull|expectException|markTestSkipped)\b/', $testPhp) ?: 0;
        if ($asserts < 2) {
            $hints[] = 'Very few assertions — mutation survival unproven.';
        }
        if ($neg < 1 && strlen($testPhp) > 80) {
            $hints[] = 'No obvious negative-path tests — Master suspects happy-path-only theatre.';
        }
        $verdict = 'ok';
        if ($asserts < 2 || ($neg < 1 && strlen($testPhp) > 120)) {
            $verdict = 'fragile';
        }
        if (filter_var($tb['saboteur_strict'] ?? false, FILTER_VALIDATE_BOOL) && $asserts < 3) {
            $verdict = 'fragile';
        }

        return [
            'verdict' => $verdict,
            'assertion_count' => (int) $asserts,
            'negative_path_signals' => (int) $neg,
            'hints' => $hints,
        ];
    }

    private static function twigStaticRatio(string $twig): float
    {
        $len = strlen($twig);
        if ($len < 1) {
            return 0.0;
        }
        $stripped = preg_replace('/\{#.*?#\}/s', '', $twig);
        $stripped = preg_replace('/\{%[\s\S]*?%\}/', '', (string) $stripped);
        $stripped = preg_replace('/\{\{[\s\S]*?\}\}/', '', (string) $stripped);

        return round(strlen((string) $stripped) / $len, 3);
    }
}
