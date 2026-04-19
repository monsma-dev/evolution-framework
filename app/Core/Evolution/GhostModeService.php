<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Ghost Mode ("Nachtwacht"): autonomous nightly analysis.
 *
 * Designed for cron: `0 3 * * * php /var/www/html/ai_bridge.php evolution:ghost-run`
 *
 * Collects CRO data + error logs from the past 24h, asks the AI for low_autofix
 * suggestions, detects dead templates, and auto-applies safe fixes.
 */
final class GhostModeService
{
    private const DEAD_TEMPLATE_THRESHOLD_DAYS = 30;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, analysis?: array<string, mixed>, auto_applied?: list<array>, dead_templates?: list<string>, error?: string}
     */
    public function run(): array
    {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        if (!is_array($evo) || !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'Evolution disabled'];
        }

        if (EvolutionKillSwitchService::isPaused($config)) {
            return ['ok' => false, 'error' => 'Evolution paused (kill-switch / EVOLUTION_PAUSE.lock)', 'kill_switch' => true];
        }

        AdaptivePriorityGovernor::applyCliNiceness($config);
        if (AdaptivePriorityGovernor::shouldDeferGhostRun($config)) {
            EvolutionLogger::log('ghost_mode', 'deferred_load', AdaptivePriorityGovernor::snapshot($config));

            return [
                'ok' => false,
                'error' => 'Ghost run uitgesteld: hoge load of Pulse-latency (adaptive_priority).',
                'deferred' => true,
                'adaptive_priority' => AdaptivePriorityGovernor::snapshot($config),
            ];
        }

        $arch = $evo['architect'] ?? [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];
        if (!is_array($aa) || !filter_var($aa['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'Auto-apply disabled'];
        }

        $health = (new HealthSnapshotService())->snapshot($this->container);
        $cro = (new CroInsightService())->buildReport($config);
        $deadTemplates = $this->detectDeadTemplates();

        $autopilot = (new CroAutopilotService())->evaluateExperiments($config);
        $smartDefaults = (new CroAutopilotService())->detectSmartDefaults($config);
        $critical = (new CodeDnaScoringService())->getCriticalClasses($config);

        $composerAudit = (new ComposerAuditService())->promptSection();
        $dbAdvisor = (new DatabaseIndexAdvisor($this->container))->promptSection();

        $chaosResults = (new ChaosEngineeringService($this->container))->promptSection();
        $apiWatchdog = (new ApiContractWatchdog())->promptSection($config);
        $leanAssets = (new LeanAssetAuditService())->promptSection($config);
        $composerShake = (new ComposerDependencyShakeService())->promptSection();

        $kbc = $config->get('evolution.knowledge_base', []);
        if (is_array($kbc) && filter_var($kbc['auto_rebuild_in_ghost'] ?? false, FILTER_VALIDATE_BOOL)) {
            (new EvolutionKnowledgeBaseService($this->container))->rebuildIndex();
        }

        $knowledgeSection = (new EvolutionKnowledgeBaseService($this->container))->promptSection();
        $schemaOptSection = (new EvolutionSchemaOptimizerService($this->container))->promptSection();
        $securitySection = (new EvolutionSecurityResearchService($this->container))->promptSection();

        $infraSentinel = (new EvolutionInfrasentinelService($this->container))->promptSection();
        $dbMigrator = (new EvolutionZeroDowntimeDbMigratorService($this->container))->promptSection();
        $growthHacker = (new EvolutionGrowthHackerService($this->container))->promptSection();
        $runtimeUpgrade = (new EvolutionComposerService($this->container))->promptRuntimeUpgradeSection();
        $frameworkHealth = (new EvolutionFrameworkHealthAuditService($this->container))->promptSection();
        $oracleSection = (new EvolutionOracleService($this->container))->promptSection();

        $prompt = $this->buildGhostPrompt(
            $health,
            $cro,
            $deadTemplates,
            $autopilot,
            $smartDefaults,
            $critical,
            $composerAudit,
            $dbAdvisor,
            $chaosResults,
            $apiWatchdog,
            $leanAssets,
            $composerShake,
            $knowledgeSection,
            $schemaOptSection,
            $securitySection,
            $infraSentinel,
            $dbMigrator,
            $growthHacker,
            $runtimeUpgrade,
            $frameworkHealth,
            $oracleSection
        );

        $manager = new SelfHealingManager($this->container);
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
            EvolutionLogger::log('ghost_mode', 'failed', ['error' => $result['error'] ?? 'unknown']);
            return ['ok' => false, 'error' => $result['error'] ?? 'AI call failed'];
        }

        $autoApply = new AutoApplyService($this->container);
        try {
            $applied = $autoApply->processFromChatResult($result, 0);
        } catch (EvolutionFatalException $e) {
            EvolutionLogger::log('ghost_mode', 'evolution_fatal', ['message' => $e->getMessage()]);

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'snapshot_restore' => $e->getSnapshotRestore(),
            ];
        }

        $refactorResult = (new AutoRefactorService($this->container))->run();

        (new SemanticDocService())->updateArchitectureDoc($this->container);

        EvolutionLogger::log('ghost_mode', 'completed', [
            'auto_applied' => count($applied),
            'auto_refactored' => count($refactorResult['refactored'] ?? []),
            'dead_templates' => count($deadTemplates),
            'cro_insights' => count($cro['insights'] ?? []),
            'errors_today' => $health['error_count_today'] ?? 0,
        ]);

        $ghostUi = (new GhostUiService())->maybeCreateProactiveExperiment($this->container);

        return [
            'ok' => true,
            'analysis' => [
                'summary' => $result['reply_text'] ?? '',
                'changes_proposed' => count($result['raw_json']['suggested_changes'] ?? []),
                'frontend_proposed' => count($result['raw_json']['suggested_frontend'] ?? []),
            ],
            'auto_applied' => $applied,
            'auto_refactored' => $refactorResult['refactored'] ?? [],
            'dead_templates' => $deadTemplates,
            'ghost_ui' => $ghostUi,
        ];
    }

    private function buildGhostPrompt(
        array $health,
        array $cro,
        array $deadTemplates,
        array $autopilot = [],
        array $smartDefaults = [],
        array $critical = [],
        string $composerAudit = '',
        string $dbAdvisor = '',
        string $chaosResults = '',
        string $apiWatchdog = '',
        string $leanAssets = '',
        string $composerShake = '',
        string $knowledgeSection = '',
        string $schemaOptSection = '',
        string $securitySection = '',
        string $infraSentinel = '',
        string $dbMigrator = '',
        string $growthHacker = '',
        string $runtimeUpgrade = '',
        string $frameworkHealth = '',
        string $oracleSection = ''
    ): string {
        $parts = [
            'GHOST MODE ANALYSIS — Autonomous nightly review. You may propose low_autofix and ui_autofix changes that will be auto-applied.',
            '',
            'SYSTEM HEALTH (last 24h):',
            json_encode($health, JSON_UNESCAPED_UNICODE),
            '',
            'CRO REPORT:',
            json_encode($cro, JSON_UNESCAPED_UNICODE),
        ];

        if ($deadTemplates !== []) {
            $parts[] = '';
            $parts[] = 'DEAD TEMPLATES (no CRO view events in ' . self::DEAD_TEMPLATE_THRESHOLD_DAYS . ' days):';
            foreach ($deadTemplates as $tpl) {
                $parts[] = '  - ' . $tpl;
            }
            $parts[] = 'Consider proposing archival or cleanup for these templates.';
        }

        $semanticAnalysis = (new SemanticLogAnalyzer())->promptSection();
        if ($semanticAnalysis !== '') {
            $parts[] = $semanticAnalysis;
        } else {
            $errorSummary = $this->recentErrorSummary();
            if ($errorSummary !== '') {
                $parts[] = '';
                $parts[] = 'ERROR LOG SUMMARY (last 24h, top patterns):';
                $parts[] = $errorSummary;
            }
        }

        if (!empty($autopilot['concluded'])) {
            $parts[] = '';
            $parts[] = 'A/B TEST RESULTS (auto-concluded):';
            foreach ($autopilot['concluded'] as $c) {
                $parts[] = "  - {$c['experiment_id']}: winner={$c['winner']} (+{$c['improvement_pct']}% vs {$c['loser']})";
            }
        }
        if ($smartDefaults !== []) {
            $parts[] = '';
            $parts[] = 'SMART DEFAULTS (CRO opportunities):';
            foreach (array_slice($smartDefaults, 0, 5) as $sd) {
                $parts[] = "  - [{$sd['step']}] {$sd['observation']} → {$sd['suggestion']}";
            }
        }
        if ($critical !== []) {
            $parts[] = '';
            $parts[] = 'CODE DNA WARNINGS (low maintainability, consider refactoring):';
            foreach (array_slice($critical, 0, 5) as $c) {
                $parts[] = "  - {$c['fqcn']} (score {$c['score']}/10): {$c['advice']}";
            }
        }

        if ($composerAudit !== '') {
            $parts[] = $composerAudit;
        }
        if ($dbAdvisor !== '') {
            $parts[] = $dbAdvisor;
        }
        if ($chaosResults !== '') {
            $parts[] = $chaosResults;
        }
        if ($apiWatchdog !== '') {
            $parts[] = $apiWatchdog;
        }
        if ($leanAssets !== '') {
            $parts[] = $leanAssets;
        }
        if ($composerShake !== '') {
            $parts[] = $composerShake;
        }
        if ($knowledgeSection !== '') {
            $parts[] = $knowledgeSection;
        }
        if ($schemaOptSection !== '') {
            $parts[] = $schemaOptSection;
        }
        if ($securitySection !== '') {
            $parts[] = $securitySection;
        }
        if ($infraSentinel !== '') {
            $parts[] = $infraSentinel;
        }
        if ($dbMigrator !== '') {
            $parts[] = $dbMigrator;
        }
        if ($growthHacker !== '') {
            $parts[] = $growthHacker;
        }
        if ($runtimeUpgrade !== '') {
            $parts[] = $runtimeUpgrade;
        }
        if ($frameworkHealth !== '') {
            $parts[] = $frameworkHealth;
        }
        if ($oracleSection !== '') {
            $parts[] = $oracleSection;
        }

        $necro = (new EvolutionDeadCodeNecromancyService())->promptSection($this->container->get('config'));
        if ($necro !== '') {
            $parts[] = $necro;
        }

        $figmaSection = (new EvolutionFigmaService($this->container))->promptSection();
        if ($figmaSection !== '') {
            $parts[] = $figmaSection;
        }

        $agenticSection = AgenticDashboardService::promptSection($this->container);
        if ($agenticSection !== '') {
            $parts[] = $agenticSection;
        }

        $parts[] = '';
        $parts[] = 'Tasks:';
        $parts[] = '1. Identify security issues (XSS, injection, CSRF) → critical_autofix with full_file_php.';
        $parts[] = '2. Find inefficient patterns, missing null checks, deprecated calls → low_autofix.';
        $parts[] = '3. Detect CSS/Twig issues visible in CRO drop-offs → ui_autofix.';
        $parts[] = '4. Flag dead templates for archival (summary only, no auto-fix needed).';
        $parts[] = '5. If JIT buffer is above 85%, note the recommended opcache.jit_buffer_size increase.';
        $parts[] = '6. Review composer advisories: propose patch-level updates as low_autofix, major updates as high.';
        $parts[] = '7. If CPU load is high, defer non-critical patches and note in summary.';
        $parts[] = '8. Address chaos engineering weaknesses with resilience patches.';
        $parts[] = '9. Fix API contract drift issues before they break production.';
        $parts[] = '10. CODE DNA / DEBLOAT: when a class scores low due to oversized methods, propose refactor_only_autofix or medium: extract a new App\\Support or App\\Core\\Evolution-collocated Service, move logic, keep public API of the original class identical.';
        $parts[] = '11. SRE / EOL: if infrastructure_sentinel risk is high or infra_signals mention MySQL/Node/PHP EOL, outline a staged upgrade (staging snapshot → tests → chaos → cutover). Do not fabricate AWS credentials or run cloud CLIs.';
        $parts[] = '12. DB upgrades: reference ONLY_FULL_GROUP_BY and reserved keywords; propose validation queries and shadow deploy — no destructive DDL.';
        $parts[] = '13. GROWTH: if search + CRO suggest a missing tool (e.g. compare), propose evolution_routing + virtual page as medium/high with shadow path.';
        $parts[] = '14. FRAMEWORK HEALTH: prefer modern PHP (readonly, enums), Twig best practices, Tailwind tokens over ad-hoc CSS.';
        $parts[] = 'Only propose changes you are confident about. Use severity correctly.';

        return implode("\n", $parts);
    }

    /**
     * Detects Twig templates that haven't had any CRO view events in N days.
     *
     * @return list<string>
     */
    private function detectDeadTemplates(): array
    {
        $viewsDir = BASE_PATH . '/resources/views';
        if (!is_dir($viewsDir)) {
            return [];
        }

        $allTemplates = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($viewsDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($viewsDir) + 1));
                if (!str_starts_with($rel, 'layouts/') && !str_starts_with($rel, 'partials/') && !str_starts_with($rel, 'emails/')) {
                    $allTemplates[] = $rel;
                }
            }
        }

        $croPath = BASE_PATH . '/data/evolution/cro_events.jsonl';
        $activeTemplates = [];
        if (is_file($croPath)) {
            $cutoff = gmdate('c', time() - self::DEAD_TEMPLATE_THRESHOLD_DAYS * 86400);
            $lines = @file($croPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $j = @json_decode($line, true);
                if (!is_array($j)) {
                    continue;
                }
                $ts = (string)($j['timestamp'] ?? $j['ts'] ?? '');
                if ($ts < $cutoff) {
                    continue;
                }
                $step = (string)($j['step'] ?? '');
                if ($step !== '') {
                    $activeTemplates[$step] = true;
                }
                $tpl = (string)($j['metadata']['template'] ?? '');
                if ($tpl !== '') {
                    $activeTemplates[$tpl] = true;
                }
            }
        }

        $cfg = $this->container->get('config');
        $dead = [];
        foreach ($allTemplates as $tpl) {
            if (EvolutionIgnoreRegistry::matches($tpl, $cfg)) {
                continue;
            }
            $base = pathinfo($tpl, PATHINFO_FILENAME);
            $step = str_replace(['pages/', '.twig'], '', $tpl);
            if (!isset($activeTemplates[$tpl]) && !isset($activeTemplates[$base]) && !isset($activeTemplates[$step])) {
                $dead[] = $tpl;
            }
        }

        return $dead;
    }

    private function recentErrorSummary(): string
    {
        $file = BASE_PATH . '/data/logs/errors/' . date('Y-m-d') . '.log';
        if (!is_file($file)) {
            return '';
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return '';
        }

        $patterns = [];
        foreach (array_slice($lines, -200) as $line) {
            $j = @json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $msg = (string)($j['message'] ?? '');
            $short = substr(preg_replace('/\d+/', 'N', $msg) ?? '', 0, 120);
            if ($short === '') {
                continue;
            }
            $patterns[$short] = ($patterns[$short] ?? 0) + 1;
        }

        arsort($patterns);
        $top = array_slice($patterns, 0, 5, true);
        $lines = [];
        foreach ($top as $pattern => $count) {
            $lines[] = "  ({$count}x) {$pattern}";
        }

        return implode("\n", $lines);
    }
}
