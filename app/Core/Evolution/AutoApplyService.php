<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use App\Domain\Web\Models\EvolutionPageModel;

/**
 * Processes auto-apply patches from a chat result based on severity classification.
 * Handles PHP shadow patches, Twig overrides, and CSS appends.
 * Respects Guard Dog rate limits, Hot-Path immunity, and schedules error-spike checks.
 * Failed applies trigger the Self-Correction Loop for immediate retry.
 */
final class AutoApplyService
{
    private const AUTO_SEVERITIES_DEFAULT = ['critical_autofix', 'low_autofix', 'ui_autofix'];

    private int $currentActorUserId = 0;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Check if a target (FQCN or template path) is immune from auto-apply.
     * Immune files always require human approval regardless of severity.
     */
    private function isImmune(string $target, \App\Core\Config $cfg): bool
    {
        return ImmunePathChecker::isImmune($target, $cfg);
    }

    /**
     * @param array<string, mixed> $chatResult from ArchitectChatService
     * @return list<array{type: string, target: string, severity: string, ok: bool, error?: string, cache_flushed?: list<string>}>
     */
    public function processFromChatResult(array $chatResult, int $actorUserId): array
    {
        $this->currentActorUserId = $actorUserId;
        $cfg = $this->container->get('config');
        $guard = new GuardDogService();

        if (EvolutionKillSwitchService::isPaused($cfg)) {
            return [[
                'type' => 'kill_switch',
                'target' => 'evolution',
                'severity' => '',
                'ok' => false,
                'error' => 'Evolution kill-switch actief — zie storage/evolution/EVOLUTION_PAUSE.lock en admin Resume.',
            ]];
        }

        if (!$guard->isAutoApplyAllowed($cfg)) {
            return [];
        }

        $allowed = $guard->allowedSeverities($cfg);
        if ($allowed === []) {
            $allowed = self::AUTO_SEVERITIES_DEFAULT;
        }

        $rateCheck = $guard->checkRateLimit($cfg);
        if (!$rateCheck['allowed']) {
            EvolutionLogger::log('auto_apply', 'rate_limited', [
                'count' => $rateCheck['count'],
                'max' => $rateCheck['max'],
            ]);

            return [['type' => 'rate_limit', 'target' => '', 'severity' => '', 'ok' => false, 'error' => "Rate limit: {$rateCheck['count']}/{$rateCheck['max']} per uur"]];
        }

        $maxFiles = $guard->maxFilesPerAuto($cfg);

        $raw = $chatResult['raw_json'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        if (self::chatResultNeedsSnapshot($raw)) {
            EvolutionSnapshotService::create($cfg, 'auto_apply_high_risk');
        }

        if (self::rawHasAutoApplyTargets($raw)) {
            $ank = RespawnEngine::createAnchor($cfg, 'Pre-apply snapshot');
            if (!($ank['ok'] ?? false)) {
                EvolutionLogger::log('respawn', 'anchor', [
                    'ok' => false,
                    'error' => $ank['error'] ?? 'unknown',
                ]);
            }
        }

        try {
            return $this->processFromChatResultBody($raw, $cfg, $guard, $actorUserId, $maxFiles, $allowed);
        } catch (\Throwable $e) {
            RespawnEngine::recordDeath($cfg, $e->getMessage());
            EvolutionLogger::log('auto_apply', 'respawn_throwable', [
                'message' => $e->getMessage(),
                'class' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $restore = EvolutionSnapshotService::restoreLatest($cfg);
            OpcacheIntelligenceService::ghostWarmupAfterPatch($this->container, null, null);

            throw new EvolutionFatalException(
                'Auto-apply faalde; snapshot restore: ' . (($restore['ok'] ?? false) ? 'ok' : (string) ($restore['error'] ?? 'fail')) . ' — ' . $e->getMessage(),
                0,
                $e,
                $restore
            );
        }
    }

    /**
     * @param list<string> $allowed
     * @param array<string, mixed> $raw
     *
     * @return list<array{type: string, target: string, severity: string, ok: bool, error?: string, cache_flushed?: list<string>}>
     */
    private function processFromChatResultBody(array $raw, \App\Core\Config $cfg, GuardDogService $guard, int $actorUserId, int $maxFiles, array $allowed): array
    {
        $results = [];
        $applied = 0;
        $butterflyBefore = EvolutionButterflyService::captureBaseline($this->container);
        $hadSuccessfulApply = false;

        $changes = $raw['suggested_changes'] ?? [];
        if (is_array($changes)) {
            foreach ($changes as $change) {
                if (!is_array($change) || $applied >= $maxFiles) {
                    break;
                }
                $severity = strtolower(trim((string)($change['severity'] ?? '')));
                if (!in_array($severity, $allowed, true)) {
                    continue;
                }
                $fqcn = trim((string)($change['fqcn'] ?? ''));
                $php = (string)($change['full_file_php'] ?? '');
                if ($fqcn === '' || trim($php) === '') {
                    continue;
                }

                if ($severity === 'refactor_only_autofix') {
                    $elig = RefactorOnlyEligibility::assertEligible($cfg, $fqcn, $php);
                    if (!$elig['ok']) {
                        $results[] = [
                            'type' => 'php',
                            'target' => $fqcn,
                            'severity' => $severity,
                            'ok' => false,
                            'error' => $elig['error'] ?? 'refactor_only not eligible',
                        ];
                        continue;
                    }
                } elseif ($severity === 'high') {
                    $eb = $cfg->get('evolution.evolutionary_budget', []);
                    if (!is_array($eb) || !filter_var($eb['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
                        $results[] = [
                            'type' => 'php',
                            'target' => $fqcn,
                            'severity' => 'high',
                            'ok' => false,
                            'error' => 'Auto-apply severity "high" requires evolution.evolutionary_budget.enabled + identical public API and DNA gain.',
                        ];
                        continue;
                    }
                    if (!EvolutionaryBudgetService::canConsumeHighSeverity($cfg)) {
                        $st = EvolutionaryBudgetService::status($cfg);
                        $results[] = [
                            'type' => 'php',
                            'target' => $fqcn,
                            'severity' => 'high',
                            'ok' => false,
                            'error' => 'Evolutionary budget exhausted for week ' . $st['week_id'] . ' (' . $st['used'] . '/' . $st['cap'] . ').',
                        ];
                        continue;
                    }
                    $elig = RefactorOnlyEligibility::assertForHighBudget($cfg, $fqcn, $php);
                    if (!$elig['ok']) {
                        $results[] = [
                            'type' => 'php',
                            'target' => $fqcn,
                            'severity' => 'high',
                            'ok' => false,
                            'error' => $elig['error'] ?? 'high budget eligibility failed',
                        ];
                        continue;
                    }
                }

                if ($this->isImmune($fqcn, $cfg)) {
                    $results[] = [
                        'type' => 'php',
                        'target' => $fqcn,
                        'severity' => 'high',
                        'ok' => false,
                        'error' => 'Hot-path immune: ' . $fqcn . ' vereist altijd handmatige goedkeuring.',
                        'immune' => true,
                    ];
                    continue;
                }

                $policyCheck = (new ArchitecturalPolicyGuard())->check($fqcn, $php, $cfg);
                if (!$policyCheck['passed']) {
                    $violationMsg = implode('; ', array_map(fn(array $v) => "[{$v['rule']}] {$v['message']}", $policyCheck['violations']));
                    $results[] = [
                        'type' => 'php',
                        'target' => $fqcn,
                        'severity' => $severity,
                        'ok' => false,
                        'error' => 'Policy violation: ' . $violationMsg,
                    ];
                    continue;
                }

                $mm = $cfg->get('evolution.master_mentor', []);
                $masterSeverities = is_array($mm) ? ($mm['auto_apply_severities'] ?? ['critical_autofix', 'refactor_only_autofix', 'high', 'medium']) : [];
                if (!is_array($masterSeverities)) {
                    $masterSeverities = ['critical_autofix', 'refactor_only_autofix', 'high', 'medium'];
                }
                $masterOpinion = null;
                if (is_array($mm) && filter_var($mm['enabled'] ?? false, FILTER_VALIDATE_BOOL)
                    && in_array($severity, $masterSeverities, true)) {
                    $pu = isset($change['phpunit_test']) && is_array($change['phpunit_test']) ? $change['phpunit_test'] : null;
                    $masterOpinion = (new EvolutionMasterOpinionService($this->container))->evaluatePhpAndTests($php, $pu, $cfg, $fqcn);
                    if (!($masterOpinion['ok'] ?? false) || !($masterOpinion['approved'] ?? false)) {
                        $verdict = trim((string) ($masterOpinion['master_verdict'] ?? ''));
                        if ($verdict === '') {
                            $verdict = (string) ($masterOpinion['error'] ?? 'Master Second Opinion rejected');
                        }
                        $results[] = [
                            'type' => 'php',
                            'target' => $fqcn,
                            'severity' => $severity,
                            'ok' => false,
                            'error' => 'Master Second Opinion: ' . $verdict . ' — zie ARCHITECTURAL_NOTES / Kladblok; herschrijf eenvoudiger of scherpere tests.',
                            'master_score' => (float) ($masterOpinion['master_score'] ?? 0),
                            'master_verdict' => $verdict,
                        ];
                        LearningLoopService::record([
                            'target' => $fqcn,
                            'type' => 'php',
                            'severity' => $severity,
                            'ok' => false,
                            'error' => 'Master Second Opinion: ' . $verdict,
                            'master_score' => (float) ($masterOpinion['master_score'] ?? 0),
                            'elegance_rating' => (float) ($masterOpinion['master_score'] ?? 0),
                            'master_verdict' => $verdict,
                        ]);

                        continue;
                    }
                }

                $elegant = EvolutionEleganceService::rejectIfUgly($php, $cfg);
                if ($elegant !== null && !in_array($severity, ['high', 'refactor_only_autofix'], true)) {
                    $results[] = [
                        'type' => 'php',
                        'target' => $fqcn,
                        'severity' => $severity,
                        'ok' => false,
                        'error' => $elegant,
                    ];
                    continue;
                }

                $gate = EvolutionTestingService::gateShadowPhpApply(
                    $cfg,
                    $this->container,
                    $fqcn,
                    $php,
                    $change,
                    $actorUserId
                );
                if (!$gate['ok']) {
                    $results[] = [
                        'type' => 'php',
                        'target' => $fqcn,
                        'severity' => $severity,
                        'ok' => false,
                        'error' => $gate['error'] ?? 'testing_gate failed',
                        'testing_gate' => true,
                    ];
                    continue;
                }

                $manager = new SelfHealingManager($this->container);
                if (!empty($gate['apply_manually'])) {
                    $result = $manager->applyShadowPatch($fqcn, $php, $actorUserId, $change['reasoning_detail'] ?? null);
                } else {
                    $result = ['ok' => true];
                }

                $cacheTags = is_array($change['cache_clear_tags'] ?? null) ? $change['cache_clear_tags'] : [];
                $flushed = [];
                if ($result['ok'] ?? false) {
                    $applied++;
                    $hadSuccessfulApply = true;
                    if ($severity === 'high') {
                        EvolutionaryBudgetService::recordHighSeverityApply($cfg);
                    }
                    OpcacheIntelligenceService::invalidateForPatch($fqcn);
                    $flushed = SelfHealingManager::flushCacheTags($cacheTags, $this->container);
                    $guard->scheduleErrorCheck($fqcn, $this->container);
                    SemanticDocService::recordChange(['target' => $fqcn, 'type' => 'php', 'severity' => $severity, 'summary' => (string)($change['rationale'] ?? '')]);
                    EvolutionLogger::log('auto_apply', 'php_applied', [
                        'fqcn' => $fqcn,
                        'severity' => $severity,
                        'actor' => $actorUserId,
                        'cache_flushed' => $flushed,
                    ]);
                }

                $entry = [
                    'type' => 'php',
                    'target' => $fqcn,
                    'severity' => $severity,
                    'ok' => (bool)($result['ok'] ?? false),
                    'error' => $result['error'] ?? null,
                    'cache_flushed' => $flushed,
                ];
                if ($masterOpinion !== null && ($entry['ok'] ?? false)) {
                    $entry['master_score'] = (float) ($masterOpinion['master_score'] ?? 0);
                    $entry['elegance_rating'] = (float) ($masterOpinion['master_score'] ?? 0);
                    $entry['master_verdict'] = (string) ($masterOpinion['master_verdict'] ?? '');
                }
                LearningLoopService::record($entry);
                $results[] = $entry;
            }
        }

        $frontend = $raw['suggested_frontend'] ?? [];
        if (is_array($frontend)) {
            foreach ($frontend as $fe) {
                if (!is_array($fe) || $applied >= $maxFiles) {
                    break;
                }
                $severity = strtolower(trim((string)($fe['severity'] ?? '')));
                if (!in_array($severity, $allowed, true)) {
                    continue;
                }
                $kind = strtolower(trim((string)($fe['kind'] ?? '')));

                if ($kind === 'css' && isset($fe['append_css']) && trim((string)$fe['append_css']) !== '') {
                    $cssText = (string)$fe['append_css'];
                    RevenueGuardService::snapshotBefore('css:architect-overrides');
                    $feService = new FrontendEvolutionService($this->container);
                    $beforeCss = $feService->readCurrentCss();

                    $vrResult = $this->visualRegressionTest($cfg, function () use ($feService, $cssText, $actorUserId) {
                        return $feService->appendCss($cssText, $actorUserId);
                    });

                    $result = $vrResult['apply_result'] ?? ['ok' => false];
                    $afterCss = $feService->readCurrentCss();
                    if (($result['ok'] ?? false) && !($vrResult['regression'] ?? false)) {
                        $applied++;
                        $hadSuccessfulApply = true;
                        if (in_array($severity, ['ui_autofix', 'low_autofix'], true)) {
                            (new VisualTimelineService($this->container))->recordAutofix(
                                'css',
                                'architect-overrides.css',
                                $beforeCss,
                                $afterCss
                            );
                        }
                        EvolutionLogger::log('auto_apply', 'css_applied', [
                            'severity' => $severity,
                            'actor' => $actorUserId,
                            'visual_change_pct' => $vrResult['change_pct'] ?? 0,
                        ]);
                        AgentCodeChangeLogger::append([
                            'kind' => 'css_applied',
                            'file' => 'css:architect-overrides',
                            'line_start' => 0,
                            'line_end' => 0,
                            'agent' => 'Architect',
                            'note' => 'architect-overrides.css append',
                            'severity' => $severity,
                        ]);
                    }
                    $cssEntry = [
                        'type' => 'css',
                        'target' => 'architect-overrides.css',
                        'severity' => $severity,
                        'ok' => (bool)($result['ok'] ?? false) && !($vrResult['regression'] ?? false),
                        'error' => ($vrResult['regression'] ?? false) ? 'Visual regression detected (' . ($vrResult['change_pct'] ?? 0) . '% change)' : ($result['error'] ?? null),
                        'visual_regression' => $vrResult['regression'] ?? false,
                        'visual_change_pct' => $vrResult['change_pct'] ?? 0,
                    ];
                    LearningLoopService::record($cssEntry);
                    $results[] = $cssEntry;
                } elseif ($kind === 'twig' && isset($fe['template'], $fe['full_template'])) {
                    $tpl = trim((string)$fe['template']);
                    $content = (string)$fe['full_template'];
                    if ($tpl === '' || trim($content) === '') {
                        continue;
                    }
                    $twigPolicy = (new ArchitecturalPolicyGuard())->checkTemplate($tpl, $content, $cfg);
                    if (!$twigPolicy['passed']) {
                        $violationMsg = implode('; ', array_map(fn(array $v) => "[{$v['rule']}] {$v['message']}", $twigPolicy['violations']));
                        $results[] = [
                            'type' => 'twig',
                            'target' => $tpl,
                            'severity' => $severity,
                            'ok' => false,
                            'error' => 'Policy violation: ' . $violationMsg,
                        ];
                        continue;
                    }
                    $feService = new FrontendEvolutionService($this->container);
                    $beforeTwig = $feService->existingTwigOverrideContent($tpl);
                    $result = $feService->writeTwigOverride($tpl, $content, $actorUserId);
                    if ($result['ok'] ?? false) {
                        $applied++;
                        $hadSuccessfulApply = true;
                        if (in_array($severity, ['ui_autofix', 'low_autofix'], true)) {
                            (new VisualTimelineService($this->container))->recordAutofix('twig', $tpl, $beforeTwig, $content);
                        }
                        SelfHealingManager::clearTwigCache();
                        OpcacheIntelligenceService::precompileTwigTemplates($this->container, [$tpl]);
                        $guard->scheduleErrorCheck('twig:' . $tpl, $this->container);
                        EvolutionLogger::log('auto_apply', 'twig_applied', [
                            'template' => $tpl,
                            'severity' => $severity,
                            'actor' => $actorUserId,
                        ]);
                        AgentCodeChangeLogger::append([
                            'kind' => 'twig_applied',
                            'file' => 'twig:' . $tpl,
                            'line_start' => 0,
                            'line_end' => 0,
                            'agent' => 'Architect',
                            'note' => 'template override',
                            'severity' => $severity,
                        ]);
                    }
                    $twigEntry = [
                        'type' => 'twig',
                        'target' => $tpl,
                        'severity' => $severity,
                        'ok' => (bool)($result['ok'] ?? false),
                        'error' => $result['error'] ?? null,
                    ];
                    LearningLoopService::record($twigEntry);
                    $results[] = $twigEntry;
                }
            }
        }

        $this->applyEvolutionRoutingBlock($results, $raw, $cfg, $guard, $actorUserId, $applied, $maxFiles);

        $this->applyEvolutionAssetsBlock($results, $raw, $cfg, $guard, $applied, $maxFiles);

        $results = $this->runSelfCorrections($results, $raw, $actorUserId);

        $bf = EvolutionButterflyService::evaluateAfterBatch($this->container, $butterflyBefore, $hadSuccessfulApply);
        if ($bf['regression'] ?? false) {
            $results[] = [
                'type' => 'butterfly',
                'target' => 'system',
                'severity' => 'low_autofix',
                'ok' => false,
                'error' => $bf['message'] ?? 'Butterfly regression detected',
            ];
        }

        $jw = $cfg->get('evolution.jit_warmup_after_auto_apply', []);
        if ($hadSuccessfulApply
            && !($bf['regression'] ?? false)
            && is_array($jw)
            && filter_var($jw['enabled'] ?? true, FILTER_VALIDATE_BOOL)
        ) {
            $minOk = max(1, (int) ($jw['min_successful_applies'] ?? 1));
            $okCount = 0;
            foreach ($results as $r) {
                if (is_array($r) && ($r['ok'] ?? false)) {
                    $okCount++;
                }
            }
            if ($okCount >= $minOk) {
                $warm = OpcacheIntelligenceService::ghostWarmupAfterPatch($this->container, null, null);
                EvolutionLogger::log('jit_warmup', 'after_auto_apply', [
                    'warmed' => $warm['warmed'] ?? null,
                    'ok' => $warm['ok'] ?? false,
                ]);
            }
        }

        if ($hadSuccessfulApply) {
            EvolutionSnapshotService::markPatchCompleted([
                'butterfly_regression' => (bool)($bf['regression'] ?? false),
            ]);
        }

        return $results;
    }

    /**
     * True when the JSON payload contains at least one block that can trigger file/DB writes in this service.
     *
     * @param array<string, mixed> $raw
     */
    private static function rawHasAutoApplyTargets(array $raw): bool
    {
        foreach ($raw['suggested_changes'] ?? [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            if (trim((string) ($c['fqcn'] ?? '')) !== '' && trim((string) ($c['full_file_php'] ?? '')) !== '') {
                return true;
            }
        }
        if (is_array($raw['suggested_frontend'] ?? null) && $raw['suggested_frontend'] !== []) {
            return true;
        }
        if (is_array($raw['evolution_routing'] ?? null) && $raw['evolution_routing'] !== []) {
            return true;
        }
        if (is_array($raw['evolution_assets'] ?? null) && $raw['evolution_assets'] !== []) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function chatResultNeedsSnapshot(array $raw): bool
    {
        foreach ($raw['suggested_changes'] ?? [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $s = strtolower(trim((string)($c['severity'] ?? '')));
            if (in_array($s, ['critical_autofix', 'high', 'refactor_only_autofix'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge evolution_assets JSON: Twig handler map, CDN libraries, optional theme token CSS (whitelist only).
     *
     * @param list<array<string, mixed>> $results
     */
    private function applyEvolutionAssetsBlock(
        array &$results,
        array $raw,
        \App\Core\Config $cfg,
        GuardDogService $guard,
        int &$applied,
        int $maxFiles
    ): void {
        $block = $raw['evolution_assets'] ?? null;
        if (!is_array($block)) {
            return;
        }

        $ea = $cfg->get('evolution.evolution_assets', []);
        if (is_array($ea) && !filter_var($ea['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            $results[] = [
                'type' => 'evolution_assets',
                'target' => '',
                'severity' => 'evolution_assets',
                'ok' => false,
                'error' => 'evolution.evolution_assets.enabled is false',
            ];

            return;
        }

        if (!$guard->isAutoApplyAllowed($cfg)) {
            return;
        }

        $allowed = $guard->allowedSeverities($cfg);
        if ($allowed === []) {
            $allowed = self::AUTO_SEVERITIES_DEFAULT;
        }
        if (!in_array('evolution_assets', $allowed, true)) {
            $results[] = [
                'type' => 'evolution_assets',
                'target' => '',
                'severity' => 'evolution_assets',
                'ok' => false,
                'error' => 'Add "evolution_assets" to architect.auto_apply.allowed_severities om Twig-extensions en CDN-libraries te mergen.',
            ];

            return;
        }

        $severity = strtolower(trim((string) ($block['severity'] ?? 'ui_autofix')));
        if (!in_array($severity, $allowed, true)) {
            return;
        }

        if ($applied >= $maxFiles) {
            return;
        }

        $did = false;

        if (isset($block['twig_functions']) && is_array($block['twig_functions'])) {
            $r = EvolutionAssetConfigMergeService::mergeTwigFunctions($cfg, $block['twig_functions']);
            $entry = [
                'type' => 'evolution_twig_functions_json',
                'target' => 'storage/evolution/twig_functions.json',
                'severity' => $severity,
                'ok' => (bool) ($r['ok'] ?? false),
                'error' => $r['error'] ?? null,
            ];
            $results[] = $entry;
            LearningLoopService::record($entry);
            if ($r['ok'] ?? false) {
                $did = true;
                SelfHealingManager::clearTwigCache();
                EvolutionLogger::log('auto_apply', 'evolution_twig_functions', ['actor' => $this->currentActorUserId]);
            }
        }

        if (isset($block['page_libraries']) && is_array($block['page_libraries'])) {
            $r = EvolutionAssetConfigMergeService::mergePageLibraries($cfg, $block['page_libraries']);
            $entry = [
                'type' => 'evolution_page_libraries_json',
                'target' => 'storage/evolution/page_libraries.json',
                'severity' => $severity,
                'ok' => (bool) ($r['ok'] ?? false),
                'error' => $r['error'] ?? null,
            ];
            $results[] = $entry;
            LearningLoopService::record($entry);
            if ($r['ok'] ?? false) {
                $did = true;
                EvolutionLogger::log('auto_apply', 'evolution_page_libraries', ['actor' => $this->currentActorUserId]);
            }
        }

        if (isset($block['theme_append_css']) && is_string($block['theme_append_css']) && trim($block['theme_append_css']) !== '') {
            $fe = new FrontendEvolutionService($this->container);
            $tr = $fe->appendThemeTokensCss((string) $block['theme_append_css'], $this->currentActorUserId);
            $entry = [
                'type' => 'evolution_theme_tokens_css',
                'target' => 'theme_overrides.css',
                'severity' => $severity,
                'ok' => (bool) ($tr['ok'] ?? false),
                'error' => $tr['error'] ?? null,
            ];
            $results[] = $entry;
            LearningLoopService::record($entry);
            if ($tr['ok'] ?? false) {
                $did = true;
                EvolutionLogger::log('auto_apply', 'evolution_theme_tokens', ['actor' => $this->currentActorUserId]);
            }
        }

        if ($did) {
            $applied++;
        }
    }

    private function applyEvolutionRoutingBlock(
        array &$results,
        array $raw,
        \App\Core\Config $cfg,
        GuardDogService $guard,
        int $actorUserId,
        int &$applied,
        int $maxFiles
    ): void {
        $block = $raw['evolution_routing'] ?? null;
        if (!is_array($block)) {
            return;
        }

        $dr = $cfg->get('evolution.dynamic_routing', []);
        if (!is_array($dr) || !filter_var($dr['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $results[] = [
                'type' => 'evolution_routing',
                'target' => '',
                'severity' => '',
                'ok' => false,
                'error' => 'evolution.dynamic_routing is disabled',
            ];

            return;
        }

        if (!$guard->isAutoApplyAllowed($cfg)) {
            return;
        }

        $allowed = $guard->allowedSeverities($cfg);
        if ($allowed === []) {
            $allowed = self::AUTO_SEVERITIES_DEFAULT;
        }
        if (!in_array('evolution_routing', $allowed, true)) {
            $results[] = [
                'type' => 'evolution_routing',
                'target' => '',
                'severity' => 'evolution_routing',
                'ok' => false,
                'error' => 'Add "evolution_routing" to architect.auto_apply.allowed_severities to enable dynamic routes + Evolution controllers.',
            ];

            return;
        }

        $routeBatch = [];

        foreach ($block['new_controllers'] ?? [] as $nc) {
            if ($applied >= $maxFiles) {
                break;
            }
            if (!is_array($nc)) {
                continue;
            }
            $severity = strtolower(trim((string) ($nc['severity'] ?? 'low_autofix')));
            if (!in_array($severity, $allowed, true)) {
                $results[] = [
                    'type' => 'evolution_controller',
                    'target' => (string) ($nc['fqcn'] ?? ''),
                    'severity' => $severity,
                    'ok' => false,
                    'error' => 'Severity not allowed for this controller',
                ];
                continue;
            }
            $fqcn = trim((string) ($nc['fqcn'] ?? ''));
            $php = (string) ($nc['full_file_php'] ?? '');
            if ($fqcn === '' || trim($php) === '') {
                continue;
            }

            $nsErr = SelfHealingManager::assertEvolutionDynamicControllerFqcn($fqcn);
            if ($nsErr !== null) {
                $results[] = [
                    'type' => 'evolution_controller',
                    'target' => $fqcn,
                    'severity' => $severity,
                    'ok' => false,
                    'error' => $nsErr,
                ];
                continue;
            }

            if ($this->isImmune($fqcn, $cfg)) {
                $results[] = [
                    'type' => 'evolution_controller',
                    'target' => $fqcn,
                    'severity' => $severity,
                    'ok' => false,
                    'error' => 'Immune path',
                    'immune' => true,
                ];
                continue;
            }

            $policyCheck = (new ArchitecturalPolicyGuard())->check($fqcn, $php, $cfg);
            if (!$policyCheck['passed']) {
                $violationMsg = implode('; ', array_map(fn(array $v) => "[{$v['rule']}] {$v['message']}", $policyCheck['violations']));
                $results[] = [
                    'type' => 'evolution_controller',
                    'target' => $fqcn,
                    'severity' => $severity,
                    'ok' => false,
                    'error' => 'Policy violation: ' . $violationMsg,
                ];
                continue;
            }

            $gate = EvolutionTestingService::gateShadowPhpApply(
                $cfg,
                $this->container,
                $fqcn,
                $php,
                $nc,
                $actorUserId
            );
            if (!$gate['ok']) {
                $results[] = [
                    'type' => 'evolution_controller',
                    'target' => $fqcn,
                    'severity' => $severity,
                    'ok' => false,
                    'error' => $gate['error'] ?? 'testing_gate failed',
                    'testing_gate' => true,
                ];
                continue;
            }

            $manager = new SelfHealingManager($this->container);
            if (!empty($gate['apply_manually'])) {
                $result = $manager->applyShadowPatch($fqcn, $php, $actorUserId, $nc['reasoning_detail'] ?? null);
            } else {
                $result = ['ok' => true];
            }
            if ($result['ok'] ?? false) {
                $applied++;
                OpcacheIntelligenceService::invalidateForPatch($fqcn);
                $guard->scheduleErrorCheck($fqcn, $this->container);
                EvolutionLogger::log('auto_apply', 'evolution_controller', ['fqcn' => $fqcn]);
            }
            $entry = [
                'type' => 'evolution_controller',
                'target' => $fqcn,
                'severity' => $severity,
                'ok' => (bool) ($result['ok'] ?? false),
                'error' => $result['error'] ?? null,
            ];
            $results[] = $entry;
            LearningLoopService::record($entry);
        }

        foreach ($block['routes'] ?? [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $routeBatch[] = [
                'method' => (string) ($r['method'] ?? 'GET'),
                'path' => (string) ($r['path'] ?? ''),
                'controller' => trim((string) ($r['controller'] ?? '')),
                'action' => trim((string) ($r['action'] ?? '')),
                'middleware' => $r['middleware'] ?? [],
            ];
        }

        if ($routeBatch !== []) {
            $merged = EvolutionDynamicRoutingService::mergeRoutes($this->container, $routeBatch);
            $results[] = [
                'type' => 'evolution_routes_json',
                'target' => EvolutionDynamicRoutingService::ROUTES_FILE,
                'severity' => 'evolution_routing',
                'ok' => (bool) ($merged['ok'] ?? false),
                'error' => $merged['error'] ?? null,
            ];
        }

        $fe = new FrontendEvolutionService($this->container);
        foreach ($block['twig_templates'] ?? [] as $tw) {
            if ($applied >= $maxFiles) {
                break;
            }
            if (!is_array($tw)) {
                continue;
            }
            $severity = strtolower(trim((string) ($tw['severity'] ?? 'ui_autofix')));
            if (!in_array($severity, $allowed, true)) {
                continue;
            }
            $tpl = trim((string) ($tw['template'] ?? ''));
            $content = (string) ($tw['full_template'] ?? '');
            if ($tpl === '' || trim($content) === '') {
                continue;
            }
            $twigPolicy = (new ArchitecturalPolicyGuard())->checkTemplate($tpl, $content, $cfg);
            if (!$twigPolicy['passed']) {
                continue;
            }
            $res = $fe->writeTwigOverride($tpl, $content, $actorUserId);
            if ($res['ok'] ?? false) {
                $applied++;
                SelfHealingManager::clearTwigCache();
            }
            $results[] = [
                'type' => 'evolution_twig',
                'target' => $tpl,
                'severity' => $severity,
                'ok' => (bool) ($res['ok'] ?? false),
                'error' => $res['error'] ?? null,
            ];
        }

        foreach ($block['virtual_pages'] ?? [] as $vp) {
            if (!is_array($vp)) {
                continue;
            }
            $model = new EvolutionPageModel($this->container);
            $ok = $model->upsertVirtualPage([
                'slug' => (string) ($vp['slug'] ?? ''),
                'title' => (string) ($vp['title'] ?? ''),
                'template_html' => (string) ($vp['template_html'] ?? ''),
                'is_published' => filter_var($vp['is_published'] ?? false, FILTER_VALIDATE_BOOL),
                'is_admin_only' => filter_var($vp['is_admin_only'] ?? true, FILTER_VALIDATE_BOOL),
            ]);
            $results[] = [
                'type' => 'evolution_virtual_page',
                'target' => (string) ($vp['slug'] ?? ''),
                'severity' => 'evolution_routing',
                'ok' => $ok,
                'error' => $ok ? null : 'upsert failed (invalid slug or DB)',
            ];
        }
    }

    /**
     * Self-Correction Loop: retry failed auto-applies by sending the error
     * back to the AI for an immediate corrected attempt (max 2 retries per target).
     *
     * @param list<array> $results current results with failures
     * @param array<string, mixed> $rawJson original AI response
     * @return list<array> updated results with correction attempts appended
     */
    private function runSelfCorrections(array $results, array $rawJson, int $actorUserId): array
    {
        $corrector = new SelfCorrectionService($this->container);

        foreach ($results as $i => $entry) {
            if ($entry['ok'] ?? true) {
                continue;
            }
            if ($entry['immune'] ?? false) {
                continue;
            }
            $error = (string)($entry['error'] ?? '');
            if ($error === '' || str_starts_with($error, 'Rate limit')) {
                continue;
            }

            $target = (string)($entry['target'] ?? '');
            $type = (string)($entry['type'] ?? 'php');

            $originalChange = $this->findOriginalChange($rawJson, $target, $type);
            if ($originalChange === null) {
                continue;
            }

            $correction = $corrector->attemptCorrection($entry, $originalChange, $actorUserId);
            if ($correction['ok'] ?? false) {
                $results[$i]['self_corrected'] = true;
                $results[$i]['correction_retries'] = $correction['retries'] ?? 1;
                if (is_array($correction['corrected_result'] ?? null)) {
                    foreach ($correction['corrected_result'] as $cr) {
                        $results[] = array_merge($cr, ['self_correction_of' => $target]);
                    }
                }
            } else {
                $results[$i]['self_correction_attempted'] = true;
                $results[$i]['self_correction_error'] = $correction['error'] ?? 'Correction failed';
            }
        }

        return $results;
    }

    /**
     * Find the original AI suggestion that matches a failed apply target.
     */
    private function findOriginalChange(array $rawJson, string $target, string $type): ?array
    {
        if ($type === 'php') {
            foreach ($rawJson['suggested_changes'] ?? [] as $c) {
                if (is_array($c) && trim((string)($c['fqcn'] ?? '')) === $target) {
                    return $c;
                }
            }
        } elseif ($type === 'css' || $type === 'twig') {
            foreach ($rawJson['suggested_frontend'] ?? [] as $f) {
                if (is_array($f) && (($f['kind'] ?? '') === $type || $target === 'architect-overrides.css')) {
                    return $f;
                }
            }
        }

        return null;
    }

    /**
     * Run visual regression test around a UI patch apply.
     * Only runs if VisualCaptureService is available (Node + Playwright).
     *
     * @param callable $applyFn must return the apply result array
     * @return array{apply_result: mixed, regression: bool, change_pct: float}
     */
    private function visualRegressionTest(\App\Core\Config $cfg, callable $applyFn): array
    {
        $baseUrl = rtrim((string)$cfg->get('site.url', ''), '/');
        if ($baseUrl === '') {
            return ['apply_result' => $applyFn(), 'regression' => false, 'change_pct' => 0];
        }

        $script = BASE_PATH . '/tooling/scripts/architect-screenshot.mjs';
        if (!is_file($script)) {
            return ['apply_result' => $applyFn(), 'regression' => false, 'change_pct' => 0];
        }

        try {
            $vr = new VisualRegressionService($this->container);

            return $vr->testWithRegression($baseUrl . '/', $applyFn);
        } catch (\Throwable) {
            return ['apply_result' => $applyFn(), 'regression' => false, 'change_pct' => 0];
        }
    }
}
