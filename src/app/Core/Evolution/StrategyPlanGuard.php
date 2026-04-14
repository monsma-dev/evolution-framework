<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Validates an AI "strategy_plan" (phase 1) before code is generated — no source code, only intent & targets.
 */
final class StrategyPlanGuard
{
    /**
     * @param array<string, mixed>|null $rawPlan strategy_plan key from model JSON
     * @return array{ok: bool, errors: list<string>}
     */
    public static function validate(?array $rawPlan, Config $config): array
    {
        $errors = [];
        if (!is_array($rawPlan) || $rawPlan === []) {
            return ['ok' => false, 'errors' => ['strategy_plan ontbreekt of is leeg.']];
        }

        $steps = $rawPlan['steps'] ?? null;
        if (!is_array($steps) || $steps === []) {
            $intent = strtolower(trim((string) ($rawPlan['intent'] ?? '')));
            if (in_array($intent, ['conceptual', 'no_code', 'answer_only'], true)) {
                return ['ok' => true, 'errors' => []];
            }

            return ['ok' => false, 'errors' => ['strategy_plan.steps is leeg — gebruik intent "conceptual" voor uitsluitend uitleg, of voeg stappen toe.']];
        }

        $guard = new GuardDogService();
        $maxFiles = $guard->maxFilesPerAuto($config);
        $allowed = $guard->allowedSeverities($config);
        if ($allowed === []) {
            $allowed = ['critical_autofix', 'low_autofix', 'ui_autofix'];
        }

        $think = $config->get('evolution.architect.think_step', []);
        $maxSteps = is_array($think) ? (int) ($think['max_plan_targets'] ?? 8) : 8;
        $maxSteps = max(1, min(20, $maxSteps));

        if (count($steps) > $maxSteps) {
            $errors[] = "Te veel plan-stappen: " . count($steps) . " (max {$maxSteps}).";
        }
        if (count($steps) > $maxFiles) {
            $errors[] = 'Meer stappen dan max_files_per_auto (' . $maxFiles . ').';
        }

        foreach ($steps as $i => $step) {
            if (!is_array($step)) {
                $errors[] = "Step #{$i} is geen object.";
                continue;
            }
            $kind = strtolower(trim((string) ($step['change_kind'] ?? 'php')));
            if (!in_array($kind, ['php', 'twig', 'css', 'sql', 'config', 'route', 'virtual_page', 'evolution_assets', 'theme_tokens'], true)) {
                $errors[] = "Step #{$i}: onbekend change_kind '{$kind}'.";
            }
            $sev = strtolower(trim((string) ($step['severity'] ?? '')));
            if ($sev === '' || !in_array($sev, $allowed, true)) {
                $errors[] = "Step #{$i}: severity '{$sev}' niet toegestaan voor auto-apply.";
            }
            $target = trim((string) ($step['target_fqcn'] ?? $step['target'] ?? $step['template'] ?? ''));
            if ($target === '') {
                $errors[] = "Step #{$i}: target ontbreekt (target_fqcn of template).";
                continue;
            }
            if (ImmunePathChecker::isImmune($target, $config)) {
                $errors[] = "Step #{$i}: '{$target}' staat op immune_paths — alleen handmatige goedkeuring (high).";
            }
        }

        $destructive = (string) ($rawPlan['destructive_sql'] ?? '');
        if ($destructive !== '' && preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|RENAME)\b/i', $destructive)) {
            $errors[] = 'Plan vermeldt destructieve SQL — niet toegestaan in think-step auto-flow.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Think-step phase 1: models often echo JSON-schema examples or partial snippets in code fields.
     * Clear those so validation passes; phase 2 generates full patches from the approved strategy_plan.
     *
     * @param array<string, mixed> $decoded
     * @return int number of non-empty fields cleared
     */
    public static function sanitizePlanPhaseCodeFields(array &$decoded): int
    {
        $cleared = 0;
        foreach ($decoded['suggested_changes'] ?? [] as $i => &$ch) {
            if (!is_array($ch)) {
                continue;
            }
            $php = trim((string) ($ch['full_file_php'] ?? ''));
            if ($php !== '') {
                $ch['full_file_php'] = '';
                $cleared++;
            }
        }
        unset($ch);
        foreach ($decoded['suggested_frontend'] ?? [] as $i => &$fe) {
            if (!is_array($fe)) {
                continue;
            }
            if (trim((string) ($fe['full_template'] ?? '')) !== '') {
                $fe['full_template'] = '';
                $cleared++;
            }
            if (trim((string) ($fe['append_css'] ?? '')) !== '') {
                $fe['append_css'] = '';
                $cleared++;
            }
        }
        unset($fe);

        return $cleared;
    }

    /**
     * Phase 1 must not ship full code blobs.
     *
     * @param array<string, mixed> $decoded full model JSON
     * @return array{ok: bool, errors: list<string>}
     */
    public static function assertNoCodeInPlanPhase(array $decoded): array
    {
        $errors = [];
        foreach ($decoded['suggested_changes'] ?? [] as $i => $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $php = trim((string) ($ch['full_file_php'] ?? ''));
            if ($php !== '') {
                $errors[] = 'suggested_changes[' . $i . '] bevat full_file_php in plan-fase (verboden).';
            }
        }
        foreach ($decoded['suggested_frontend'] ?? [] as $i => $fe) {
            if (!is_array($fe)) {
                continue;
            }
            if (trim((string) ($fe['full_template'] ?? '')) !== '') {
                $errors[] = 'suggested_frontend[' . $i . '] bevat full_template in plan-fase (verboden).';
            }
            if (trim((string) ($fe['append_css'] ?? '')) !== '') {
                $errors[] = 'suggested_frontend[' . $i . '] bevat append_css in plan-fase (verboden).';
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
