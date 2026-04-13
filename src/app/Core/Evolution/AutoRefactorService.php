<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Auto-Refactor ("Code Poetser"): autonomously refactors classes with low Code DNA scores.
 *
 * Constraints:
 * - Only classes with DNA score < threshold (default 4)
 * - Only in allowed namespaces (non-critical, configurable)
 * - May NOT change public method signatures (name, params, return type)
 * - May only clean method internals (DRY, reduce nesting, simplify logic)
 */
final class AutoRefactorService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Find classes eligible for auto-refactor and request AI refactoring.
     *
     * @return array{ok: bool, candidates: list<array{fqcn: string, score: int}>, refactored: list<array>, error?: string}
     */
    public function run(): array
    {
        $config = $this->container->get('config');
        $settings = $this->getSettings($config);
        if (!$settings['enabled']) {
            return ['ok' => false, 'candidates' => [], 'refactored' => [], 'error' => 'Auto-refactor disabled'];
        }

        $dna = new CodeDnaScoringService();
        $critical = $dna->getCriticalClasses($config, $settings['max_dna_score']);

        $candidates = [];
        foreach ($critical as $c) {
            if ($this->isInAllowedNamespace($c['fqcn'], $settings['allowed_namespaces'])) {
                $candidates[] = $c;
            }
        }

        if ($candidates === []) {
            return ['ok' => true, 'candidates' => [], 'refactored' => [], 'error' => 'No eligible classes'];
        }

        $maxCand = $settings['max_candidates_per_run'];
        $candidates = array_slice($candidates, 0, $maxCand);

        $prompt = $this->buildRefactorPrompt($candidates, $settings);

        $manager = new SelfHealingManager($this->container);
        $health = (new HealthSnapshotService())->snapshot($this->container);
        $result = $manager->architectChat(
            [['role' => 'user', 'content' => $prompt]],
            'core',
            false,
            1,
            ['user_id' => 0, 'listing_id' => 0],
            false,
            '',
            $health
        );

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'candidates' => $candidates, 'refactored' => [], 'error' => $result['error'] ?? 'AI call failed'];
        }

        $raw = $result['raw_json'] ?? [];
        $changes = $raw['suggested_changes'] ?? [];
        $refactored = [];

        if (is_array($changes)) {
            foreach ($changes as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $fqcn = trim((string)($change['fqcn'] ?? ''));
                $php = (string)($change['full_file_php'] ?? '');
                if ($fqcn === '' || trim($php) === '') {
                    continue;
                }

                if ($settings['forbid_signature_changes'] && !PublicSignatureComparer::publicSignaturesMatch($fqcn, $php)) {
                    EvolutionLogger::log('auto_refactor', 'signature_blocked', ['fqcn' => $fqcn]);
                    $refactored[] = ['fqcn' => $fqcn, 'ok' => false, 'error' => 'Blocked: public method signature change detected'];
                    continue;
                }

                if ($settings['refactor_only_autonomy']) {
                    $elig = RefactorOnlyEligibility::assertEligible($config, $fqcn, $php);
                    if (!$elig['ok']) {
                        $refactored[] = ['fqcn' => $fqcn, 'ok' => false, 'error' => $elig['error'] ?? 'refactor_only ineligible'];
                        continue;
                    }
                }

                $policyCheck = (new ArchitecturalPolicyGuard())->check($fqcn, $php, $config);
                if (!$policyCheck['passed']) {
                    $refactored[] = ['fqcn' => $fqcn, 'ok' => false, 'error' => 'Policy violation'];
                    continue;
                }

                $tg = $config->get('evolution.testing_gate', []);
                $gateRefactor = is_array($tg) && filter_var($tg['apply_to_auto_refactor'] ?? false, FILTER_VALIDATE_BOOL);
                if ($gateRefactor) {
                    $gate = EvolutionTestingService::gateShadowPhpApply(
                        $config,
                        $this->container,
                        $fqcn,
                        $php,
                        $change,
                        0
                    );
                    if (!$gate['ok']) {
                        $refactored[] = ['fqcn' => $fqcn, 'ok' => false, 'error' => $gate['error'] ?? 'testing_gate'];
                        continue;
                    }
                    if (empty($gate['apply_manually'])) {
                        $applyResult = ['ok' => true];
                    } else {
                        $manager2 = new SelfHealingManager($this->container);
                        $applyResult = $manager2->applyShadowPatch($fqcn, $php, 0, $change['reasoning_detail'] ?? null);
                    }
                } else {
                    $manager2 = new SelfHealingManager($this->container);
                    $applyResult = $manager2->applyShadowPatch($fqcn, $php, 0, $change['reasoning_detail'] ?? null);
                }

                if ($applyResult['ok'] ?? false) {
                    OpcacheIntelligenceService::invalidateForPatch($fqcn);
                    LearningLoopService::record([
                        'target' => $fqcn,
                        'type' => 'php',
                        'severity' => 'auto_refactor',
                        'ok' => true,
                    ]);
                }

                $refactored[] = [
                    'fqcn' => $fqcn,
                    'ok' => (bool)($applyResult['ok'] ?? false),
                    'error' => $applyResult['error'] ?? null,
                ];
            }
        }

        EvolutionLogger::log('auto_refactor', 'completed', [
            'candidates' => count($candidates),
            'refactored' => count(array_filter($refactored, fn(array $r) => $r['ok'])),
        ]);

        return ['ok' => true, 'candidates' => $candidates, 'refactored' => $refactored];
    }

    /**
     * @return array{enabled: bool, max_dna_score: int, allowed_namespaces: list<string>, forbid_signature_changes: bool, max_candidates_per_run: int, refactor_only_autonomy: bool}
     */
    private function getSettings(Config $config): array
    {
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];
        $ar = is_array($aa) ? ($aa['auto_refactor'] ?? []) : [];
        $eb = is_array($evo) ? ($evo['evolutionary_budget'] ?? []) : [];
        $ro = is_array($evo) ? ($evo['refactor_only_autonomy'] ?? []) : [];

        $defaultMax = 3;
        $maxCand = max(1, (int)($ar['max_candidates_per_run'] ?? $defaultMax));
        if (is_array($eb) && filter_var($eb['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $maxCand = max($maxCand, max(1, (int)($eb['max_classes_per_run'] ?? 5)));
        }

        return [
            'enabled' => is_array($ar) && filter_var($ar['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'max_dna_score' => max(1, (int)($ar['max_dna_score'] ?? 4)),
            'allowed_namespaces' => is_array($ar['allowed_namespaces'] ?? null) ? $ar['allowed_namespaces'] : [],
            'forbid_signature_changes' => !is_array($ar) || filter_var($ar['forbid_signature_changes'] ?? true, FILTER_VALIDATE_BOOL),
            'max_candidates_per_run' => $maxCand,
            'refactor_only_autonomy' => is_array($ro) && filter_var($ro['enabled'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    private function isInAllowedNamespace(string $fqcn, array $allowedNamespaces): bool
    {
        foreach ($allowedNamespaces as $ns) {
            if (str_starts_with($fqcn, (string)$ns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{fqcn: string, score: int, advice: string}> $candidates
     */
    private function buildRefactorPrompt(array $candidates, array $settings): string
    {
        $sev = $settings['refactor_only_autonomy']
            ? 'refactor_only_autofix'
            : 'low_autofix';
        $lines = [
            "AUTO-REFACTOR REQUEST — Clean up low-quality classes. Use severity \"{$sev}\".",
            '',
            'STRICT RULES:',
            '- You may ONLY change method internals (body logic).',
            '- Do NOT change any public method name, parameter list, or return type.',
            '- Do NOT add, remove, or rename public methods.',
            '- Focus on: reducing nesting depth, extracting private helpers, DRY patterns, removing dead code.',
            '- Provide complete full_file_php for each class.',
            '',
            'Candidates (sorted by worst score):',
        ];

        foreach ($candidates as $c) {
            $lines[] = "  - {$c['fqcn']} (DNA score {$c['score']}/10): {$c['advice']}";
        }

        return implode("\n", $lines);
    }
}
