<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Architectural immune system: enforces configurable code policies before auto-apply.
 *
 * Rules are defined in evolution.json under architect.policy_guard.rules.
 * Each rule has a pattern (regex on source), a scope (namespace/layer), and a message.
 * Violations block AutoApplyService with a clear explanation.
 */
final class ArchitecturalPolicyGuard
{
    /**
     * @return array{passed: bool, violations: list<array{rule: string, message: string}>}
     */
    public function check(string $fqcn, string $phpSource, Config $config): array
    {
        $rules = $this->loadRules($config);
        if ($rules === []) {
            return ['passed' => true, 'violations' => []];
        }

        $violations = [];
        foreach ($rules as $rule) {
            if (!$this->ruleApplies($fqcn, $rule)) {
                continue;
            }
            if ($this->ruleViolated($phpSource, $rule)) {
                $violations[] = [
                    'rule' => (string)($rule['id'] ?? $rule['name'] ?? 'unnamed'),
                    'message' => (string)($rule['message'] ?? 'Architectural policy violation'),
                ];
            }
        }

        if (str_starts_with($fqcn, 'App\\Core\\')
            && preg_match('/^use\s+App\\\\Domain\\\\Web\\\\Controllers\\\\/m', $phpSource) === 1) {
            $violations[] = [
                'rule' => 'inverse_layer_core_to_controllers',
                'message' => 'Kernel/App\\Core mag niet importeren uit App\\Domain\\Web\\Controllers — omgekeerde laag. Gebruik services of verplaats logica; leg uitzonderingen vast in ARCHITECTURAL_NOTES (kladblok).',
            ];
        }

        if ($violations !== []) {
            EvolutionLogger::log('policy_guard', 'violations_detected', [
                'fqcn' => $fqcn,
                'violations' => $violations,
            ]);
        }

        return ['passed' => $violations === [], 'violations' => $violations];
    }

    /**
     * Check a Twig template source against UI policies.
     *
     * @return array{passed: bool, violations: list<array{rule: string, message: string}>}
     */
    public function checkTemplate(string $templatePath, string $source, Config $config): array
    {
        $rules = $this->loadRules($config);
        $violations = [];

        foreach ($rules as $rule) {
            $scope = strtolower((string)($rule['scope'] ?? ''));
            if ($scope !== 'twig' && $scope !== 'frontend' && $scope !== 'all') {
                continue;
            }
            if ($this->ruleViolated($source, $rule)) {
                $violations[] = [
                    'rule' => (string)($rule['id'] ?? $rule['name'] ?? 'unnamed'),
                    'message' => (string)($rule['message'] ?? 'Template policy violation'),
                ];
            }
        }

        return ['passed' => $violations === [], 'violations' => $violations];
    }

    /**
     * @return list<array{id: string, scope: string, pattern: string, forbidden: bool, message: string}>
     */
    private function loadRules(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $pg = is_array($arch) ? ($arch['policy_guard'] ?? []) : [];
        if (!is_array($pg) || !filter_var($pg['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $rules = $pg['rules'] ?? [];

        return is_array($rules) ? $rules : [];
    }

    private function ruleApplies(string $fqcn, array $rule): bool
    {
        $scope = strtolower((string)($rule['scope'] ?? 'all'));
        if ($scope === 'all') {
            return true;
        }
        $fqcnLower = strtolower($fqcn);

        return match ($scope) {
            'controller', 'controllers' => str_contains($fqcnLower, '\\controllers\\'),
            'model', 'models' => str_contains($fqcnLower, '\\models\\'),
            'service', 'services' => str_contains($fqcnLower, '\\services\\'),
            'middleware' => str_contains($fqcnLower, '\\middleware\\'),
            'evolution' => str_contains($fqcnLower, '\\evolution\\'),
            default => str_contains($fqcnLower, '\\' . $scope . '\\'),
        };
    }

    private function ruleViolated(string $source, array $rule): bool
    {
        $pattern = (string)($rule['pattern'] ?? '');
        if ($pattern === '') {
            return false;
        }

        $forbidden = filter_var($rule['forbidden'] ?? true, FILTER_VALIDATE_BOOL);
        $found = @preg_match($pattern, $source) === 1;

        return $forbidden ? $found : !$found;
    }

    /**
     * Check if a SQL migration is non-destructive (safe for auto-apply).
     * ADD INDEX / ADD COLUMN are safe; DROP / RENAME / TRUNCATE are destructive.
     *
     * @return array{safe: bool, reason: string}
     */
    public function checkSqlMigration(string $sql, Config $config): array
    {
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];
        $db = is_array($aa) ? ($aa['db_evolution'] ?? []) : [];
        $blockDestructive = !is_array($db) || filter_var($db['block_destructive'] ?? true, FILTER_VALIDATE_BOOL);
        $autoAddIndex = is_array($db) && filter_var($db['auto_add_index'] ?? true, FILTER_VALIDATE_BOOL);

        $upper = strtoupper(trim($sql));

        if ($blockDestructive) {
            $destructivePatterns = [
                '/\bDROP\s+(TABLE|COLUMN|INDEX|DATABASE)\b/i',
                '/\bRENAME\s+(TABLE|COLUMN)\b/i',
                '/\bTRUNCATE\b/i',
                '/\bALTER\s+TABLE\s+\S+\s+DROP\b/i',
                '/\bDELETE\s+FROM\b/i',
            ];
            foreach ($destructivePatterns as $pattern) {
                if (preg_match($pattern, $sql)) {
                    return ['safe' => false, 'reason' => 'Destructieve SQL (DROP/RENAME/TRUNCATE/DELETE) vereist handmatige goedkeuring.'];
                }
            }
        }

        if ($autoAddIndex && preg_match('/\b(CREATE|ADD)\s+(UNIQUE\s+)?INDEX\b/i', $sql)) {
            return ['safe' => true, 'reason' => 'ADD INDEX is auto-apply safe'];
        }
        if (preg_match('/\bADD\s+COLUMN\b/i', $sql) || preg_match('/\bALTER\s+TABLE\s+\S+\s+ADD\b/i', $sql)) {
            return ['safe' => true, 'reason' => 'ADD COLUMN is non-destructive'];
        }

        return ['safe' => false, 'reason' => 'Onbekende SQL-operatie — vereist handmatige goedkeuring.'];
    }
}
