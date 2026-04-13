<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use App\Domain\Web\Services\Payments\FinancialInsightService;
use OpenAI;
use Throwable;

/**
 * Architect chat: Claude for PHP refactoring (core), OpenAI GPT-4o for UX/Twig/CSS (ux mode).
 */
final class ArchitectChatService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param string $mode core | ux (UX uses design-oriented model; core uses code refactoring model)
     * @param array{user_id?: int, listing_id?: int}|null $creditContext optional Budget-Guard (per user/listing)
     * @param string|null $taskSeverity optioneel: light|standard|premium (of aliassen typo/bugfix/architecture) — zie ModelRouterService wanneer architect.model_router.enabled
     * @return array{ok: bool, raw_json?: array, reply_text?: string, error?: string, provider?: string, budget_guard?: array, patch_token_warning?: array|null, web_search?: array}
     */
    public function complete(
        array $messages,
        string $mode = 'core',
        bool $includeFinancialContext = false,
        int $financialDays = 30,
        ?array $creditContext = null,
        bool $includeWebSearch = false,
        string $webSearchQuery = '',
        ?array $healthSnapshot = null,
        ?string $taskSeverity = null
    ): array {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        if (!is_array($evo) || !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'Evolution / Architect is disabled in evolution.json'];
        }

        $licResult = $this->container->get('license');
        if (is_array($licResult) && !($licResult['ok'] ?? true)) {
            return ['ok' => false, 'error' => 'Unlicensed Evolution Core: ' . ($licResult['reason'] ?? 'license invalid')];
        }

        EvolutionWarmupService::warm($config, $messages);
        $arch = $evo['architect'] ?? [];
        if (!is_array($arch) || !filter_var($arch['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'Architect chat is disabled'];
        }

        EvolutionPoliceService::maybeArrestOnHighLoad($config, null);

        if (EvolutionPoliceService::isAgentInCell($config, 'architect')) {
            $cell = EvolutionPoliceService::getCellInfo($config, 'architect');

            return [
                'ok' => false,
                'error' => 'Architect API blocked (police cell until ' . (string) ($cell['until'] ?? '?') . ').',
                'police_cell' => $cell,
            ];
        }

        $sleepDefer = EvolutionSleepService::shouldDeferArchitectCall($config, $mode, $taskSeverity);
        if ($sleepDefer['defer'] ?? false) {
            return [
                'ok' => false,
                'error' => (string) ($sleepDefer['reason'] ?? 'Evolution sleep protocol'),
                'sleep' => $sleepDefer,
            ];
        }

        $maxTokens = max(512, min(8192, (int)($arch['max_tokens'] ?? 4096)));
        $financialDays = max(7, min(90, $financialDays));

        $healthBlock = '';
        if (is_array($healthSnapshot) && $healthSnapshot !== []) {
            $healthBlock = "\n\nCURRENT_SYSTEM_HEALTH: " . json_encode($healthSnapshot, JSON_UNESCAPED_UNICODE);
        }

        // Auto-detect design tasks and route to ux/DesignAgent mode
        if ($mode !== 'ux') {
            $lastUserMsg = '';
            foreach (array_reverse($messages) as $m) {
                if (($m['role'] ?? '') === 'user') {
                    $lastUserMsg = (string)($m['content'] ?? '');
                    break;
                }
            }
            if ($lastUserMsg !== '' && \App\Core\Evolution\Design\DesignAgentService::isDesignTask($lastUserMsg)) {
                $mode = 'ux';
            }
        }

        if ($mode === 'ux') {
            return $this->completeUxMode($config, $arch, $messages, $maxTokens, $includeFinancialContext, $financialDays, $creditContext, $includeWebSearch, $webSearchQuery, $healthBlock, $taskSeverity);
        }

        return $this->completeCoreMode($config, $arch, $messages, $maxTokens, $includeFinancialContext, $financialDays, $creditContext, $includeWebSearch, $webSearchQuery, $healthBlock, $taskSeverity);
    }

    /**
     * @param array<string, mixed> $arch
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{ok: bool, raw_json?: array, reply_text?: string, error?: string, provider?: string}
     */
    private function completeCoreMode(
        $config,
        array $arch,
        array $messages,
        int $maxTokens,
        bool $includeFinancialContext,
        int $financialDays,
        ?array $creditContext,
        bool $includeWebSearch,
        string $webSearchQuery,
        string $healthBlock = '',
        ?string $taskSeverity = null
    ): array {
        $fallbackModel = (string)($arch['model'] ?? 'gpt-4o-mini');
        $evoBg = $config->get('evolution.budget_guard', []);
        $cheapCore = is_array($evoBg) ? (string)($evoBg['cheap_core_model'] ?? $fallbackModel) : $fallbackModel;
        $tier1 = trim((string)($arch['tier1_chat_model'] ?? $cheapCore));
        if ($tier1 === '') {
            $tier1 = $cheapCore;
        }
        $tier1Only = filter_var($arch['chat_uses_tier1_only'] ?? true, FILTER_VALIDATE_BOOL);
        $routerEnabled = ModelRouterService::isRouterEnabled($arch);

        $system = <<<'PROMPT'
You are the "Framework Architect" for a PHP 8.3 marketplace application (App\ namespace, strict_types, PDO in Models only).
When suggesting code, respond with a single JSON object (no markdown fences) using this shape:
{
  "summary": "short plain-language explanation including WHY the change helps (performance, safety, clarity)",
  "risks": ["optional risk strings"],
  "suggested_changes": [
    {
      "fqcn": "App\\Fully\\Qualified\\ClassName",
      "severity": "critical_autofix",
      "rationale": "why this change",
      "full_file_php": "<?php\n...complete valid PHP file...",
      "cache_clear_tags": ["optional_cache_key_prefix"],
      "reasoning_detail": {
        "bottleneck": "what was slow or risky in the previous design",
        "arm64_note": "why the new code behaves well on ARM64 PHP (fewer allocations, less indirection, etc.)",
        "expected_gain_ms": 12,
        "original_baseline_ms": 10
      },
      "phpunit_test": {
        "class_name": "YourClassEvolutionTest",
        "file_contents": "<?php declare(strict_types=1); full file: namespace App\\Tests\\Evolution\\Generated; use PHPUnit\\Framework\\TestCase; tests that validate the NEW behavior after full_file_php is applied."
      }
    }
  ],
  "suggested_frontend": [
    {
      "kind": "css",
      "severity": "ui_autofix",
      "append_css": "/* scoped rules only */",
      "rationale": ""
    },
    {
      "kind": "twig",
      "severity": "ui_autofix",
      "template": "pages/admin/example.twig",
      "full_template": "{# complete twig #}",
      "rationale": ""
    }
  ],
  "evolution_routing": {
    "new_controllers": [
      {
        "fqcn": "App\\Domain\\Web\\Controllers\\Evolution\\ExampleToolController",
        "severity": "low_autofix",
        "full_file_php": "<?php ... complete controller in Evolution namespace only ...",
        "rationale": ""
      }
    ],
    "routes": [
      {
        "method": "GET",
        "path": "/admin/tools/example",
        "controller": "App\\Domain\\Web\\Controllers\\Evolution\\ExampleToolController",
        "action": "index",
        "middleware": ["App\\Domain\\Web\\Middleware\\AdminMiddleware"]
      }
    ],
    "twig_templates": [
      {
        "severity": "ui_autofix",
        "template": "pages/evolution/example.twig",
        "full_template": "{# shadow twig under storage/evolution/twig_overrides #}"
      }
    ],
    "virtual_pages": [
      {
        "slug": "optional-db-page",
        "title": "Title",
        "template_html": "<main>...</main>",
        "is_published": false,
        "is_admin_only": true
      }
    ]
  },
  "evolution_assets": {
    "severity": "ui_autofix",
    "twig_functions": {
      "filters": {
        "smart_time": { "handler_id": "relative_time" }
      },
      "functions": {
        "get_cro_recommendation": { "handler_id": "cro_snippet" }
      }
    },
    "page_libraries": {
      "rules": [
        {
          "path_prefix": "/admin/tools/example",
          "styles": [],
          "scripts": [
            "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
          ]
        }
      ]
    },
    "theme_append_css": ":root { --spacing-unit: 0.28rem; }\n@media (max-width: 640px) { :root { --spacing-unit: 0.32rem; } }"
  },
  "update_blueprint_notes": ["optioneel: architecturale notities voor blueprint_notes.json"],
  "update_lessons_from_master": ["optioneel: langetermijn lessen / aforismen"]
}

SEVERITY (required on every suggested_changes and suggested_frontend entry):
- "critical_autofix": security vulnerability (XSS, SQL injection, CSRF bypass, open redirect, path traversal, insecure deserialization). Applied immediately without asking.
- "low_autofix": small bug fix, typo, missing null check, deprecated function, coding standard violation. Applied immediately.
- "ui_autofix": small CSS/Twig visual fix (z-index, overflow, contrast, spacing, padding, mobile responsiveness, missing aspect-ratio, CLS fix). Applied immediately if <=2 files.
- "medium": moderate refactor, performance optimization, adding validation. Explain and wait for user approval.
- "high": large change. Normally manual approval. Autonomous auto-apply ONLY if evolution.evolutionary_budget.enabled AND allowed_severities includes \"high\" — then identical public API + DNA gain + weekly cap apply.
- "refactor_only_autofix": INTERNAL refactor only — public method signatures (names, params, return types) must stay identical to the live class; Code DNA score must improve by at least min_dna_gain. Requires evolution.refactor_only_autonomy.enabled and this token in allowed_severities.

When severity is "critical_autofix", "low_autofix" or "ui_autofix", you MUST provide full_file_php or full_template/append_css.
When severity is "refactor_only_autofix", you MUST provide full_file_php.
When severity is "medium" or "high", you MAY provide the code or just explain the plan.

TESTING_GATE (when evolution.testing_gate.enabled in config): for every suggested_changes[] PHP patch you MUST include phpunit_test with a complete PHPUnit class (namespace App\\Tests\\Evolution\\Generated, class name ending in Test). The framework runs this test after applying the shadow patch; failure rolls the patch back.

EVOLUTION_ASSETS (optional — requires "evolution_assets" in allowed_severities):
- twig_functions merges into storage/evolution/twig_functions.json. Only declare filter/function names and handler_id values that exist in the framework whitelist (e.g. relative_time, identity, cro_snippet, empty_string). Templates then use |smart_time or {{ get_cro_recommendation() }} — no raw PHP in JSON.
- page_libraries merges into storage/evolution/page_libraries.json: rules with path_prefix and HTTPS script/style URLs from allowed CDNs (jsdelivr, unpkg, cdnjs, esm.sh, ga.jspm.io; extra hosts via evolution.evolution_assets.cdn_hosts).
- theme_append_css appends to public/storage/evolution/theme_overrides.css (Tailwind v4 @theme / :root variables). Keep mobile-first token tweaks here.
- In Twig use {{ image_optimized('/assets/…', 'alt', 'classes') }} for lazy &lt;picture&gt; when .webp/.avif siblings exist; Cloudinary URLs get f_auto,q_auto when configured.
- Pages include a small FID (first-input) beacon; metrics appear in HealthSnapshot.web_vitals — if fid_p75_ms is high, prefer defer on heavy page_libraries scripts.

DYNAMIC_ROUTING (optional — requires evolution.dynamic_routing.enabled AND "evolution_routing" in allowed_severities):
- Put new page controllers ONLY under App\\Domain\\Web\\Controllers\\Evolution\\ (shadow patches).
- Route paths MUST stay within allowed_route_prefixes (e.g. /lp/, /tools/, /info/, /admin/tools/) — never /login, /api/, bare /admin.
- evolution_routing.virtual_pages store HTML in DB and are served at /v/{slug} (logic_php is never executed in v1).

AEO/GEO (Answer + Generative Engine optimization — virtual_pages template_html):
- Start with a **Direct Answer** block: one plain-language paragraph, max 50 words, suitable for LLM citation; put it before marketing fluff.
- Keep the brand entity (evolution.aeo.brand_entity in evolution.json) aligned with core activities in the title and first paragraph.
- Prefer semantic HTML (main, section, h1) and one short FAQ-style Q&A when the page explains a concept.

OUTREACH (evolution.outreach — EU media / PR; **disabled by default**):
- When enabled, use licensed databases or legitimate press contacts; follow docs/OUTREACH_LEGAL.md (GDPR, anti-spam, AI transparency). evolution.outreach.dry_run_only should stay true until SMTP keys + counsel sign-off.
- Drafts: cheap model in outreach config; polyglot: wire TranslationEvolutionService / DeepL; personalization via WebSearchAdapter headlines.
- Budget: evolution.outreach.max_test_round_eur caps estimated API spend; subject-line A/B via EvolutionOutreachEngine::registerSubjectLineAb.

NATIVE_COMPILER (evolution.native_compiler — Rust/ext-php-rs; disabled by default):
- Never emit raw C that touches buffers without templates. Prefer Rust + docs/native_templates.md building blocks; codegen drafts: evolution.native_compiler.cheap_model; final safety pass: safety_model.
- Compile and test only inside EvolutionNativeSandbox (Docker cargo test / isolated tree under storage/evolution/native_sandbox) — never write .so directly into production PHP without DualExecutionGuard (EvolutionDualExecutionGuard) matching PHP output for dual_execution_iterations.
- Shadow naming: expose native symbols under a distinct prefix (e.g. native_*), keep PHP implementation callable until Hall-of-Fame promotion.
- Staged ini snippets go to storage/evolution/native_pending/*.ini; merge into docker/php/99-framework.ini manually unless evolution.native_compiler.allow_live_ini_write (discouraged).

TRANSLATIONS (evolution.translations.enabled + DeepL key):
- Lang files live under src/resources/lang/{locale}/*.json. When adding UI strings, keep keys in sync: missing keys in non-source locales can be filled via TranslationEvolutionService (tone hint from existing common.json).
- Do not embed secrets in lang JSON; run `php ai_bridge.php evolution:translation-sync --file=… --source=… --targets=…` after adding keys.

KNOWLEDGE_GRAPH (storage/evolution/knowledge_graph.json):
- Rebuild with `php ai_bridge.php evolution:knowledge-rebuild` so Ghost and humans have a class/relation index without full-repo grep.

SCHEMA_OPTIMIZER (advisory):
- Suggestions from slow query heuristics may propose denormalization or extra indexes — never run destructive DDL on production; use shadow deploy / staging first.

SHREDDER (evolution.shredder.enabled):
- Cron old AI artifacts: `php ai_bridge.php evolution:shredder-run` to cap disk use from versioned_backups and shadow_deploys.

SECURITY_RESEARCH:
- Static scans target Evolution controllers; treat findings as hints and validate with review and tests.

STORAGE_LAYOUT:
- Large logs, Evolution backups, and AI artifacts belong under Framework/storage; public/storage should be symlinks into ../storage where needed so the document root does not silently duplicate huge trees.

CONFIG_EVOLUTION:
- After StructuralRefactorService moves a class, EvolutionConfigService may rewrite FQCN strings in src/config/*.json, .env.example, and src/bootstrap/app.php (bootstrap: backup + php -l gate).

COMPOSER_EVOLUTION (evolution.composer_evolution.enabled):
- Optional `composer require` / `remove` via EvolutionComposerService — Ghost mode blocks major version bumps unless explicitly allowed.

RESOURCE_CLEANUP (evolution.resource_cleanup.enabled):
- Prunes aged/oversized logs and cold JSON cache entries; Redis INFO memory hint only (no production FLUSH).

OPENAPI_WIKI (storage/evolution/openapi.json):
- EvolutionOpenApiService reflects dynamic_routes.json for virtual AI endpoints.

TOTAL_SYNC:
- `php ai_bridge.php evolution:total-sync` runs shredder, resource cleanup, knowledge rebuild, composer audit + security scan, optional structural_refactor_queue.json relocations, dump-autoload, openapi rebuild, and DeepL sync (unless --skip-deepl).

SRE / FUTURE_PROOF (infrastructure_sentinel, db_zero_downtime_migrator, growth_hacker, runtime_upgrade_hints, framework_health_audit):
- Feed EOL notices into storage/evolution/infra_signals.json — HealthSnapshot shows infrastructure_risk_score.
- Major DB upgrades: staging snapshot → PHPUnit + chaos → human-run Terraform/aws CLI — never auto-apply RDS changes.
- Search query JSONL + CRO → propose /tools/* virtual pages when demand is clear.
- Align PHP runtime, composer platform, package.json engines, GitHub Actions, Dockerfiles when Node/PHP EOL signals appear.
- Monthly: `php ai_bridge.php evolution:framework-health` for architectural debt heuristics.

AWS_SNS_INGEST:
- POST /api/v1/evolution/aws-ingest with X-Evolution-Ingest-Key — see storage/evolution/AWS_SNS_SETUP.txt for AWS CLI subscribe/publish.

PULSE_ORACLE_HALL_OF_FAME:
- `evolution:pulse-run` (cron) — DB/cache/HTTP pulse; dashboard + HealthSnapshot.pulse.
- Oracle writes oracle_forecast.json (opportunity score); Hall of Fame uses timeline.jsonl — surfaced under evolution on /api/v1/dashboard/summary when dashboard_widgets enabled.
- .env DB changes: use EvolutionConfigService::updateEnvKeys (backup + HotSwap arm + DB verify).

CACHE_POLICY:
- When changing data structures, Models, or API responses: include "cache_clear_tags" with affected cache key prefixes.
- Prevent cache stampedes: always propose jitter (random offset) on TTL for new cache implementations.
- Cache fixes (forgotten invalidation, excessive TTL, invalid keys) qualify as "low_autofix".

UI_POLICY:
- For ui_autofix: use ONLY specific CSS classes or IDs — never global selectors (div, section, a, p).
- CSS fixes go into suggested_frontend with kind "css" + append_css (appended to architect-overrides.css, loaded after Tailwind).
- Twig fixes go into suggested_frontend with kind "twig" + template + full_template (shadow override in storage/evolution/twig_overrides/).
- No Sass/SCSS in auto-fixes — only vanilla CSS (Vite build does not run at runtime).
- ASSET_HYGIENE: In CURRENT_CODEBASE_BLUEPRINT zie je ⚠️ [FAT_ASSET_WARNING] voor CSS/JS > drempel. Geef bij je volgende ui_autofix prioriteit aan verwijderen van ongebruikte selectors, splitsen, of lazy-load — gebruik de Impact Map (Twig↔CSS) om geen stylesheet weg te halen dat nog door een template wordt geraakt.
- ASSET_HYGIENE (Master): Zie je [FAT_ASSET_WARNING] in je blueprint — prioriteit bij volgende UI-fix: opruimen, splitsen, lazy-load; vermijd half werk.

HOT_PATH_IMMUNITY:
- Some critical classes are marked "immune" from auto-apply. If you propose changes to immune files, ALWAYS use severity "high" (requires manual approval).
- If a previous auto-apply failed, you may receive a SELF-CORRECTION request. Be more conservative in your second attempt: use narrower selectors, simpler logic.

DB_EVOLUTION_POLICY:
- ADD INDEX and ADD COLUMN are considered non-destructive and can use severity "low_autofix" for auto-apply.
- DROP TABLE, DROP COLUMN, RENAME TABLE, RENAME COLUMN, TRUNCATE, DELETE FROM are DESTRUCTIVE — always use severity "high" (manual approval required).
- When proposing an index, include the full CREATE INDEX SQL and the sample query it optimizes.

AUTO_REFACTOR_POLICY:
- Classes with Code DNA score < 4 in non-critical namespaces may be auto-refactored.
- When refactoring: NEVER change public method signatures (name, parameters, return type).
- Only clean internals: reduce nesting, extract private helpers, apply DRY patterns, remove dead code.

If you are only answering a conceptual question, use empty suggested_changes and suggested_frontend arrays.
BLUEPRINT_NOTEPAD:
- Optioneel in raw_json: "update_blueprint_notes": ["korte string", ...] voor architecturale ontdekkingen (impact, verboden verbindingen, centrale hubs). Dit wordt opgeslagen in storage/evolution/blueprint_notes.json en teruggegeven als ARCHITECTURAL_NOTES.
- Optioneel: "update_lessons_from_master": ["aforisme of les", ...] voor LESSONS_FROM_MASTER (langetermijn geheugen; combineer met Master-afwijzingen).
- Houd notities beknopt; geen secrets.

REQUIRED: the "summary" field must NEVER be an empty string. Always write at least one clear sentence for the human (even when arrays are empty).
Never include secrets, API keys, or credentials. Preserve separation: SQL only in Model classes.
Omit reasoning_detail if not applicable; numeric fields may be null.

Socratic_Inquiry — stel jezelf deze drie vragen vóór je codeert (Master-discipline):
1) Is dit DRY — geen dubbele kennis die op termijn divergeert?
2) Is de intentie van deze wijziging in één oogopslag duidelijk voor een junior over twee jaar?
3) Maak ik het systeem structureel beter, of verberg ik alleen een symptoom?

MASTER_MENTOR: De Master kijkt over je schouder mee (master_wisdom + Second Opinion op zware patches). Hij verafschuwt spaghetti en over-engineering.
Als je een oplossing kiest die werkt maar onnodig complex is, kan je success-streak breken (elegance < 7 in de learning loop).
Kies de meest elegante weg; zo niet, herontwerp voordat je JSON indient.
PROMPT;

        $system .= (new EvolutionMentorService($this->container))->promptCompact($config);
        $system .= $this->appendFigmaPolicy($config);

        $system .= <<<'LEGEND_AUTONOMY'
LEGEND_AUTONOMY_MODULES:
- Butterfly (regression intelligence): na een batch auto-apply meet het systeem pulse + A/B wall time opnieuw; een kleine UI-wijziging kan onbedoeld latency elders verhogen — houd patches minimaal en lokaal.
- Visual memory: geslaagde ui_autofix bewaart before/after in de Hall of Fame / visual timeline — audit trail voor ontwerp-evolutie.
- Kill-switch: bij STOP/EVOLUTION_PAUSE.lock stopt auto-apply — geen riskante bulk-wijzigingen voorstellen als een mens moet kunnen ingrijpen.
- Elegance: PHP moet niet alleen correct zijn maar ook leesbaar (lage nesting, duidelijke namen); anders wordt een patch geweigerd vóór apply.
- Master Second Opinion (evolution.master_mentor): op critical_autofix / refactor_only / high / medium kan een onafhankelijke GPT-4o-check patches + phpunit_test weigeren (master_score < drempel) — dan geen AutoApply; lees master_last_opinion + Hall of Wisdom op het dashboard.

LEGEND_AUTONOMY;

        $system .= $this->appendImmunePaths($config);
        $system .= EvolutionFrameworkContext::appendReadmeAndLocalDocs($config);
        $system .= CostGuard::promptAppend($config);
        $system .= AiCreditMonitor::promptAppendBudgetHints($config);
        $system .= $this->financialContextAppend($includeFinancialContext, $financialDays);
        $system .= EvolutionFrameworkContext::appendLiveFinance($config, $this->container);

        $webSearchMeta = $this->appendWebSearchBlock($config, $messages, $includeWebSearch, $webSearchQuery);
        $system .= $webSearchMeta['block'];
        $system .= $healthBlock;
        $system .= (new CodeDnaScoringService())->promptAppend($config);
        $system .= EvolutionaryBudgetService::promptAppend($config);
        $system .= LearningLoopService::promptAppend();
        $system .= PromptDNAEvolutionService::promptAppend($config);
        $system .= EvolutionHeuristicsService::promptAppend($config);
        $system .= EvolutionLibraryScoutService::promptAppend($config);
        $system .= EvolutionGenesisService::promptAppend($config);
        $system .= SurvivalKitService::getRugzakContext($config);
        $system .= RespawnEngine::latestDeathSummaryForPrompt($config);
        $system .= self::appendRespawnRugzakInstruction($config);
        $system .= EvolutionBlueprintService::promptBlueprintTxt($config);
        $system .= EvolutionNotepadService::promptSection($config);
        $system .= EvolutionFlightRecorder::latestSummaryForPrompt($config);
        $system .= LearningVectorMemoryService::promptAppend($config, $messages);
        $system .= BudgetAwareContextLoader::appendToSystemPrompt($config, $messages);
        $system .= (new SemanticLogAnalyzer())->microCachePromptSection($config);
        $system .= AbPerformanceService::promptSection();
        $system .= InfrastructureAsCodeBridgeService::promptAppend($this->container);
        $system .= OpcacheIntelligenceService::architectPromptAppend($config);
        $system .= AcademyService::loadPromptSnippet();
        $system .= $this->injectJitLesson($messages, $arch);
        $system .= $this->injectXpStatus();
        $system .= KnowledgeRetrieverService::retrieve(['architecture', 'database', 'ai-agents']);

        $monitor = new AiCreditMonitor($config);
        $ctx = is_array($creditContext) ? $creditContext : [];
        $picked = ModelRouterService::pickCoreModel($arch, $taskSeverity, $fallbackModel, $cheapCore, $tier1, $tier1Only);
        $pricingModel = $picked['model'];
        $eval = $monitor->evaluateBeforeCall('core', $pricingModel, $maxTokens, $system, $messages, $ctx);
        $forceCheap = (bool)($eval['force_cheap'] ?? false);

        $system .= $monitor->formatSaldoInstructionForPrompt($config, $eval);

        $chatProvider = strtolower(trim((string)($arch['chat_provider'] ?? 'openai')));
        [$chatApiKey, $chatKeyError] = $this->resolveChatApiKey($chatProvider, $config);
        if ($chatApiKey === '') {
            return ['ok' => false, 'error' => $chatKeyError];
        }
        $openaiKey = $chatApiKey;

        if ($routerEnabled) {
            $openAiModel = $forceCheap ? $cheapCore : $picked['model'];
        } elseif ($tier1Only) {
            $openAiModel = $tier1;
        } else {
            $openAiModel = $forceCheap ? $cheapCore : $fallbackModel;
        }

        $logDowngraded = $forceCheap && (!$tier1Only || $routerEnabled);
        $modelRouterMeta = [
            'enabled' => $routerEnabled,
            'tier' => $picked['tier'],
        ];
        if ($taskSeverity !== null && $taskSeverity !== '') {
            $modelRouterMeta['requested_severity'] = $taskSeverity;
        }

        $thinkCfg = $arch['think_step'] ?? [];
        $thinkOn = is_array($thinkCfg) && filter_var($thinkCfg['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $ghostThink = is_array($thinkCfg) && filter_var($thinkCfg['run_in_ghost'] ?? false, FILTER_VALIDATE_BOOL);
        $isGhostPrompt = false;
        foreach ($messages as $gm) {
            if (isset($gm['content']) && str_contains((string) $gm['content'], 'GHOST MODE')) {
                $isGhostPrompt = true;
                break;
            }
        }
        if ($thinkOn && (!$isGhostPrompt || $ghostThink)) {
            return $this->completeCoreModeThinkStep(
                $system,
                $config,
                $messages,
                $openAiModel,
                $maxTokens,
                $monitor,
                $eval,
                $logDowngraded,
                $ctx,
                $openaiKey,
                $webSearchMeta,
                $arch,
                $taskSeverity,
                $modelRouterMeta
            );
        }

        try {
            $text = $this->openAiCompatibleComplete($chatProvider, $chatApiKey, $openAiModel, $system, $messages, $maxTokens, $config);

            $monitor->recordEstimatedTurn(
                $openAiModel,
                (int)($eval['estimated_input_tokens'] ?? 0),
                (int)ceil($maxTokens * 0.45),
                $ctx
            );

            $out = $this->finalizeJsonResponse(
                $text,
                $chatProvider,
                $openAiModel,
                'core',
                $config,
                $eval,
                $logDowngraded,
                $ctx,
                $taskSeverity,
                $modelRouterMeta
            );
            if ($webSearchMeta['meta'] !== null) {
                $out['web_search'] = $webSearchMeta['meta'];
            }

            // Micro-Context: persist 3-sentence summary snippet for future session resume
            if (($out['ok'] ?? false) && isset($out['reply_text']) && $out['reply_text'] !== '') {
                MicroContextService::save($out['reply_text'], 'core', $messages);
            }

            // Academy Curiosity Trigger: laat de Architect reflecteren op de taak
            if (($out['ok'] ?? false) && isset($out['reply_text']) && $out['reply_text'] !== '') {
                $this->triggerCuriosityReflection($messages, $out['reply_text'], 'core');
            }

            // XP Award: shadow_patch_ok voor succesvolle kern-mode taken
            if (($out['ok'] ?? false)) {
                $this->awardTaskXp('shadow_patch_ok', 'core task completed');
            }

            return $out;
        } catch (Throwable $e) {
            EvolutionLogger::log('architect', 'error', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Two-phase core chat: (1) strategy_plan JSON only — validated without code — (2) full implementation.
     *
     * @param array<string, mixed> $webSearchMeta
     * @param array<string, mixed> $arch
     * @param array<string, mixed>|null $modelRouterMeta
     *
     * @return array{ok: bool, raw_json?: array, reply_text?: string, error?: string, provider?: string, think_step?: array, web_search?: array}
     */
    private function completeCoreModeThinkStep(
        string $system,
        \App\Core\Config $config,
        array $messages,
        string $openAiModel,
        int $maxTokens,
        AiCreditMonitor $monitor,
        array $eval,
        bool $logDowngraded,
        array $ctx,
        string $openaiKey,
        array $webSearchMeta,
        array $arch,
        ?string $taskSeverity,
        ?array $modelRouterMeta
    ): array {
        $planTok = min(1400, max(512, (int) ($arch['think_step']['plan_max_tokens'] ?? 1200)));
        $phase2Tok = max($maxTokens, 1024);

        $planSystem = $system . <<<'PLAN'

THINK_STEP_PHASE_1 (plan only — bespaart tokens):
- Output MUST include "strategy_plan": { "steps": [ { "target_fqcn": "App\\... OR twig path", "change_kind": "php|twig|css", "severity": "...", "rationale": "..." } ], "notes": "optional" }.
- Do NOT put real PHP/Twig/CSS in this response: omit "suggested_changes" and "suggested_frontend", OR use [].
- If you include those arrays for shape only, every "full_file_php", "full_template", and "append_css" MUST be "" (empty string) — no placeholders, no "<?php ..." snippets.
- NO production code in this phase. "summary" must explain the plan in natural language.
PLAN;

        $chatProvider = strtolower(trim((string)($arch['chat_provider'] ?? 'openai')));
        $payload = $this->openAiMessagePayload($messages);

        try {
            $text1 = $this->openAiCompatibleComplete($chatProvider, $openaiKey, $openAiModel, $planSystem, $messages, $planTok, $config);
            if ($text1 === '') {
                return ['ok' => false, 'error' => 'Think-step phase 1: empty model response'];
            }
            $decoded1 = self::decodeJsonObject($text1);
            if (!is_array($decoded1)) {
                return ['ok' => false, 'error' => 'Think-step phase 1: invalid JSON'];
            }

            $sanitized = StrategyPlanGuard::sanitizePlanPhaseCodeFields($decoded1);
            if ($sanitized > 0) {
                EvolutionLogger::log('architect', 'think_step_plan_sanitized', ['cleared_code_fields' => $sanitized]);
            }

            $noCode = StrategyPlanGuard::assertNoCodeInPlanPhase($decoded1);
            if (!$noCode['ok']) {
                EvolutionLogger::log('architect', 'think_step_blocked', ['phase' => 1, 'errors' => $noCode['errors']]);

                return [
                    'ok' => false,
                    'error' => 'Think-step: code in plan-fase: ' . implode('; ', $noCode['errors']),
                    'think_step' => ['phase' => 1, 'errors' => $noCode['errors']],
                ];
            }

            $plan = isset($decoded1['strategy_plan']) && is_array($decoded1['strategy_plan']) ? $decoded1['strategy_plan'] : null;
            $validated = StrategyPlanGuard::validate($plan, $config);
            if (!$validated['ok']) {
                EvolutionLogger::log('architect', 'think_step_blocked', ['phase' => 'validate', 'errors' => $validated['errors']]);

                return [
                    'ok' => false,
                    'error' => 'StrategyPlanGuard weigerde strategy_plan: ' . implode('; ', $validated['errors']),
                    'think_step' => ['phase' => 'validate', 'errors' => $validated['errors'], 'strategy_plan' => $plan],
                ];
            }

            $peer = ArchitectPeerReviewService::review($config, $openaiKey, $plan);
            if (!$peer['ok'] || !$peer['approved']) {
                $msg = 'Peer review weigerde strategy_plan';
                if (!empty($peer['issues']) && is_array($peer['issues'])) {
                    $msg .= ': ' . implode('; ', $peer['issues']);
                } elseif (!empty($peer['error'])) {
                    $msg .= ': ' . (string)$peer['error'];
                }
                if (!empty($peer['summary'])) {
                    $msg .= ' — ' . (string)$peer['summary'];
                }
                EvolutionLogger::log('architect', 'think_step_blocked', ['phase' => 'peer_review', 'peer' => $peer]);

                return [
                    'ok' => false,
                    'error' => $msg,
                    'think_step' => ['phase' => 'peer_review', 'peer_review' => $peer, 'strategy_plan' => $plan],
                ];
            }

            IntentLogService::append($config, 'think_step_approved', [
                'strategy_plan' => $plan,
                'peer_review' => $peer,
                'phase1_summary' => trim((string)($decoded1['summary'] ?? '')),
            ]);

            StrategyLibraryService::recordApprovedPlan($config, $messages, $plan);

            $phase2System = $system . <<<'P2'

THINK_STEP_PHASE_2:
The strategy_plan was approved by ArchitecturalPolicyGuard (logic-only). Output the SAME JSON shape as normal Architect mode:
- Fill "suggested_changes" / "suggested_frontend" with FULL code matching the approved steps.
- Include "strategy_plan" again unchanged for traceability (echo the approved plan).
P2;

            $planJson = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $msg2 = array_merge(
                $payload,
                [
                    ['role' => 'assistant', 'content' => $text1],
                    [
                        'role' => 'user',
                        'content' => "Implementeer nu het goedgekeurde strategy_plan als werkende patches. Approved plan JSON:\n{$planJson}",
                    ],
                ]
            );

            $text2 = $this->openAiCompatibleComplete($chatProvider, $openaiKey, $openAiModel, $phase2System, $msg2, $phase2Tok, $config);
            if ($text2 === '') {
                return ['ok' => false, 'error' => 'Think-step phase 2: empty model response', 'think_step' => ['phase' => 2]];
            }

            $monitor->recordEstimatedTurn(
                $openAiModel,
                (int)($eval['estimated_input_tokens'] ?? 0),
                (int)ceil(($planTok + $phase2Tok) * 0.4),
                $ctx
            );

            $decoded2 = self::decodeJsonObject($text2);
            if (!is_array($decoded2)) {
                return ['ok' => false, 'error' => 'Think-step phase 2: invalid JSON'];
            }
            if (!isset($decoded2['strategy_plan']) && $plan !== null) {
                $decoded2['strategy_plan'] = $plan;
            }
            $mergedText = json_encode($decoded2, JSON_UNESCAPED_UNICODE);
            $out = $this->finalizeJsonResponse(
                $mergedText,
                $chatProvider,
                $openAiModel,
                'core',
                $config,
                $eval,
                $logDowngraded,
                $ctx,
                $taskSeverity,
                $modelRouterMeta
            );
            $out['think_step'] = [
                'enabled' => true,
                'plan_validated' => true,
                'peer_review_ok' => true,
                'phase1_summary' => trim((string)($decoded1['summary'] ?? '')),
            ];
            if ($webSearchMeta['meta'] !== null) {
                $out['web_search'] = $webSearchMeta['meta'];
            }

            return $out;
        } catch (Throwable $e) {
            EvolutionLogger::log('architect', 'error', ['message' => $e->getMessage(), 'think_step' => true]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $arch
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{ok: bool, raw_json?: array, reply_text?: string, error?: string, provider?: string}
     */
    private function completeUxMode(
        $config,
        array $arch,
        array $messages,
        int $maxTokens,
        bool $includeFinancialContext,
        int $financialDays,
        ?array $creditContext,
        bool $includeWebSearch,
        string $webSearchQuery,
        string $healthBlock = '',
        ?string $taskSeverity = null
    ): array {
        $uxModel = (string)($arch['ux_model'] ?? 'gpt-4o');
        $evoBg = $config->get('evolution.budget_guard', []);
        $cheapUx = is_array($evoBg) ? (string)($evoBg['cheap_ux_model'] ?? 'gpt-4o-mini') : 'gpt-4o-mini';
        $tier1Ux = trim((string)($arch['tier1_ux_model'] ?? $cheapUx));
        if ($tier1Ux === '') {
            $tier1Ux = $cheapUx;
        }
        $tier1Only = filter_var($arch['chat_uses_tier1_only'] ?? true, FILTER_VALIDATE_BOOL);
        $routerEnabled = ModelRouterService::isRouterEnabled($arch);

        $system = <<<'PROMPT'
You are the senior UX/UI + Framework Architect for a PHP 8.3 app using Twig templates and Tailwind-oriented CSS.
You may propose shadow frontend changes (Twig/CSS) without breaking MVC: never put SQL in templates.
Respond with a single JSON object (no markdown fences):
{
  "summary": "short explanation including WHY the UX change should lift conversion or clarity",
  "risks": ["optional"],
  "suggested_changes": [
    {
      "fqcn": "App\\...",
      "severity": "medium",
      "rationale": "",
      "full_file_php": "<?php ... only if backend change is required",
      "cache_clear_tags": [],
      "reasoning_detail": {
        "bottleneck": "e.g. tap target too small on mobile",
        "arm64_note": "optional: lighter DOM/CSS work on mobile ARM",
        "expected_gain_ms": null,
        "original_baseline_ms": null
      }
    }
  ],
  "suggested_frontend": [
    {
      "kind": "twig",
      "severity": "ui_autofix",
      "template": "pages/marketplace/home.twig",
      "full_template": "{# full twig file content #}",
      "rationale": ""
    },
    {
      "kind": "css",
      "severity": "ui_autofix",
      "append_css": "/* scoped rules; use media (max-width:600px) for mobile CRO */",
      "rationale": ""
    }
  ],
  "ab_idea": {
    "experiment_id": "optional_snake_case",
    "variants": [{"name":"A","css_snippet":""},{"name":"B","css_snippet":""}]
  },
  "update_blueprint_notes": ["optioneel: korte architecturale notities voor het kladblok"],
  "update_lessons_from_master": ["optioneel: langetermijn UX/lessen"]
}

SEVERITY (required on every suggested_changes and suggested_frontend entry):
- "critical_autofix": security vulnerability. Applied immediately.
- "low_autofix": small bug fix, typo, missing null check. Applied immediately.
- "ui_autofix": small CSS/Twig visual fix (z-index, overflow, contrast, spacing, CLS). Applied immediately if <=2 files.
- "medium": moderate refactor. Explain and wait for approval.
- "high": large change. Full plan, wait for approval.

CACHE_POLICY:
- Include "cache_clear_tags" when changing data structures or API responses.
- Cache fixes qualify as "low_autofix".

UI_POLICY:
- For ui_autofix: ONLY specific CSS classes/IDs — never global selectors.
- CSS: kind "css" + append_css (appended to architect-overrides.css after Tailwind).
- Twig: kind "twig" + template + full_template (shadow in storage/evolution/twig_overrides/).
- No Sass/SCSS — vanilla CSS only (no runtime Vite build).
- ASSET_HYGIENE: Respecteer ⚠️ [FAT_ASSET_WARNING] in CURRENT_CODEBASE_BLUEPRINT — prioriteit aan opschonen/splitsen; gebruik Impact Map zodat je geen CSS verwijdert dat nog door een template wordt gebruikt.

Use empty arrays when not applicable. Never include secrets.
REQUIRED: the "summary" field must NEVER be an empty string — always include a clear UX/architecture answer for the human.

Socratic_Inquiry (UX): DRY? Intentie direct leesbaar? Structureel beter of alleen cosmetisch?
MASTER_MENTOR: Kies de eenvoudigste UX-fix; over-engineering breekt elegantie-streaks bij zware PHP-wijzigingen.
PROMPT;

        $system .= (new EvolutionMentorService($this->container))->promptCompact($config);
        $system .= $this->appendFigmaPolicy($config);

        $system .= <<<'LEGEND_AUTONOMY_UX'
LEGEND_AUTONOMY_MODULES (UX):
- Butterfly: systeem brede metrics kunnen na een kleine CSS/Twig-fix verslechteren — vermijd brede selectors en onnodige DOM-work.
- Visual memory: elke geslaagde ui_autofix kan als before/after worden vastgelegd — ontwerp dus bewust en consistent met het design system.
- Kill-switch: respecteer dat een admin alles kan pauzeren — geen destructieve of irreversible voorstellen zonder duidelijke rationale.
- Elegance: Twig en CSS moeten onderhoudbaar blijven (geen diep geneste logica in templates; CSS met duidelijke blokken).

LEGEND_AUTONOMY_UX;

        $system .= EvolutionFrameworkContext::appendReadmeAndLocalDocs($config);
        $system .= CostGuard::promptAppend($config);
        $system .= AiCreditMonitor::promptAppendBudgetHints($config);
        $system .= $this->financialContextAppend($includeFinancialContext, $financialDays);
        $system .= EvolutionFrameworkContext::appendLiveFinance($config, $this->container);

        $webSearchMeta = $this->appendWebSearchBlock($config, $messages, $includeWebSearch, $webSearchQuery);
        $system .= $webSearchMeta['block'];
        $system .= $healthBlock;
        $system .= LearningLoopService::promptAppend();
        $system .= PromptDNAEvolutionService::promptAppend($config);
        $system .= EvolutionHeuristicsService::promptAppend($config);
        $system .= EvolutionLibraryScoutService::promptAppend($config);
        $system .= EvolutionGenesisService::promptAppend($config);
        $system .= SurvivalKitService::getRugzakContext($config);
        $system .= RespawnEngine::latestDeathSummaryForPrompt($config);
        $system .= self::appendRespawnRugzakInstruction($config);
        $system .= EvolutionBlueprintService::promptBlueprintTxt($config);
        $system .= EvolutionNotepadService::promptSection($config);
        $system .= EvolutionFlightRecorder::latestSummaryForPrompt($config);
        $system .= LearningVectorMemoryService::promptAppend($config, $messages);
        $system .= BudgetAwareContextLoader::appendToSystemPrompt($config, $messages);
        $system .= AcademyService::loadPromptSnippet();

        $monitor = new AiCreditMonitor($config);
        $ctx = is_array($creditContext) ? $creditContext : [];
        $pickedUx = ModelRouterService::pickUxModel($arch, $taskSeverity, $uxModel, $cheapUx, $tier1Ux, $tier1Only);
        $pricingUx = $pickedUx['model'];
        $eval = $monitor->evaluateBeforeCall('ux', $pricingUx, $maxTokens, $system, $messages, $ctx);
        $forceCheap = (bool)($eval['force_cheap'] ?? false);
        $system .= $monitor->formatSaldoInstructionForPrompt($config, $eval);

        if ($routerEnabled) {
            $useModel = $forceCheap ? $cheapUx : $pickedUx['model'];
        } elseif ($tier1Only) {
            $useModel = $tier1Ux;
        } else {
            $useModel = $forceCheap ? $cheapUx : $uxModel;
        }

        $logDowngraded = $forceCheap && (!$tier1Only || $routerEnabled);
        $modelRouterMeta = [
            'enabled' => $routerEnabled,
            'tier' => $pickedUx['tier'],
        ];
        if ($taskSeverity !== null && $taskSeverity !== '') {
            $modelRouterMeta['requested_severity'] = $taskSeverity;
        }

        $uxProvider = strtolower(trim((string)($arch['ux_provider'] ?? 'openai')));
        [$uxApiKey, $uxKeyError] = $this->resolveChatApiKey($uxProvider, $config);
        if ($uxApiKey === '') {
            return ['ok' => false, 'error' => $uxKeyError];
        }

        try {
            $text = $this->openAiCompatibleComplete($uxProvider, $uxApiKey, $useModel, $system, $messages, $maxTokens, $config);

            $monitor->recordEstimatedTurn(
                $useModel,
                (int)($eval['estimated_input_tokens'] ?? 0),
                (int)ceil($maxTokens * 0.45),
                $ctx
            );

            $out = $this->finalizeJsonResponse($text, $uxProvider, $useModel, 'ux', $config, $eval, $logDowngraded, $ctx, $taskSeverity, $modelRouterMeta);
            if ($webSearchMeta['meta'] !== null) {
                $out['web_search'] = $webSearchMeta['meta'];
            }

            return $out;
        } catch (Throwable $e) {
            EvolutionLogger::log('architect', 'error', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{block: string, meta: array<string, mixed>|null}
     */
    private function appendWebSearchBlock(
        \App\Core\Config $config,
        array $messages,
        bool $includeWebSearch,
        string $webSearchQuery
    ): array {
        if (!$includeWebSearch) {
            return ['block' => '', 'meta' => null];
        }
        $q = trim($webSearchQuery);
        if ($q === '') {
            $q = WebSearchAdapter::queryFromMessages($messages);
        }
        $ws = WebSearchAdapter::buildContextBlock($config, $q);
        $meta = [
            'requested' => true,
            'ok' => $ws['ok'],
            'query' => $q,
        ];
        if (isset($ws['error'])) {
            $meta['error'] = $ws['error'];
        }

        return ['block' => $ws['block'] ?? '', 'meta' => $meta];
    }

    /**
     * Optional PSP + tax JSON for checkout / fee questions.
     */
    private function financialContextAppend(bool $includeFinancialContext, int $financialDays): string
    {
        if (!$includeFinancialContext) {
            return '';
        }
        $svc = new FinancialInsightService($this->container);
        $blob = $svc->compactJsonForPrompt($financialDays);

        return "\n\n=== LIVE FINANCIAL SNAPSHOT (Mollie + Stripe + DB + tax probes; summarize, do not dump raw JSON to the user) ===\n"
            . $blob . "\n";
    }

    /**
     * Resolve the API key for a given chat provider.
     *
     * @return array{0: string, 1: string} [apiKey, errorMessage]
     */
    private function resolveChatApiKey(string $provider, \App\Core\Config $config): array
    {
        if ($provider === 'deepseek') {
            $key = trim((string)$config->get('ai.deepseek.api_key', ''));
            if ($key === '') {
                return ['', 'Missing DEEPSEEK_API_KEY / ai.deepseek.api_key (chat_provider=deepseek).'];
            }

            return [$key, ''];
        }

        $key = trim((string)$config->get('ai.openai.api_key', $config->get('assistant.api_key', '')));
        if ($key === '') {
            return ['', 'Missing API key for Architect chat (ai.openai.api_key). Claude is reserved for Apply Patch / consensus.'];
        }

        return [$key, ''];
    }

    /**
     * Single-call OpenAI-compatible completion (openai or deepseek) with graceful fallback.
     *
     * If DeepSeek returns an empty response (429/503/timeout), the method automatically
     * falls back to the secondary provider configured in evolution.json architect.fallback.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function openAiCompatibleComplete(
        string $provider,
        string $apiKey,
        string $model,
        string $system,
        array $messages,
        int $maxTokens,
        ?\App\Core\Config $config = null
    ): string {
        if ($provider === 'deepseek') {
            $client = new DeepSeekClient($apiKey);
            $text   = $client->complete($system, $messages, $model, $maxTokens, true);

            if ($text !== '') {
                return $text;
            }

            // DeepSeek failed (429/503/timeout) — attempt graceful fallback
            $httpStatus = $client->getLastHttpStatus();
            EvolutionLogger::log('architect', 'deepseek_failed', [
                'model'       => $model,
                'http_status' => $httpStatus,
                'fallback'    => $config !== null,
            ]);

            if ($config === null) {
                return '';
            }

            $evo  = $config->get('evolution', []);
            $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
            $fb   = is_array($arch['fallback'] ?? null) ? $arch['fallback'] : [];

            if (!filter_var($fb['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
                return '';
            }

            $fbProvider = strtolower(trim((string)($fb['provider'] ?? 'openai')));
            $fbModel    = trim((string)($fb['model'] ?? 'gpt-4o'));

            if ($fbProvider === 'deepseek') {
                return '';
            }

            [$fbKey, $fbErr] = $this->resolveChatApiKey($fbProvider, $config);
            if ($fbKey === '') {
                EvolutionLogger::log('architect', 'deepseek_fallback_no_key', ['error' => $fbErr]);

                return '';
            }

            EvolutionLogger::log('architect', 'deepseek_fallback_active', [
                'to_provider' => $fbProvider,
                'to_model'    => $fbModel,
                'from_status' => $httpStatus,
            ]);

            return $this->openAiCompatibleComplete($fbProvider, $fbKey, $fbModel, $system, $messages, $maxTokens);
        }

        $client = OpenAI::factory()->withApiKey($apiKey)->make();
        $response = $client->chat()->create([
            'model'           => $model,
            'messages'        => array_merge(
                [['role' => 'system', 'content' => $system]],
                $this->openAiMessagePayload($messages)
            ),
            'max_tokens'      => $maxTokens,
            'response_format' => ['type' => 'json_object'],
        ]);
        $choice = $response->choices[0] ?? null;

        return trim((string)($choice?->message->content ?? ''));
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    private function openAiMessagePayload(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (!isset($m['role'], $m['content']) || !is_string($m['role']) || !is_string($m['content'])) {
                continue;
            }
            if (!in_array($m['role'], ['user', 'assistant'], true)) {
                continue;
            }
            $out[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $eval
     * @param array{user_id?: int, listing_id?: int} $ctx
     * @param array<string, mixed>|null $modelRouterMeta
     *
     * @return array{ok: bool, raw_json?: array, reply_text?: string, error?: string, provider?: string, budget_guard?: array, patch_token_warning?: array|null}
     */
    private function finalizeJsonResponse(
        string $text,
        string $provider,
        string $model,
        string $mode,
        \App\Core\Config $config,
        array $eval,
        bool $downgraded,
        array $ctx,
        ?string $taskSeverity = null,
        ?array $modelRouterMeta = null
    ): array {
        if ($text === '') {
            return ['ok' => false, 'error' => 'Empty model response'];
        }
        $decoded = self::decodeJsonObject($text);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Model returned non-JSON'];
        }
        $summary = trim((string)($decoded['summary'] ?? ''));

        $logPayload = [
            'model' => $model,
            'provider' => $provider,
            'mode' => $mode,
            'changes' => count($decoded['suggested_changes'] ?? []),
            'budget_downgraded' => $downgraded,
        ];
        if ($summary === '') {
            $logPayload['empty_summary'] = true;
        }
        EvolutionLogger::log('architect', 'chat_turn', $logPayload);

        $monitor = new AiCreditMonitor($config);
        $patchWarn = $monitor->analyzePatchTokenRisk($decoded, $config);
        $saldo = $monitor->computeSaldoFields($eval);

        $budgetGuard = [
            'index' => 1,
            'downgraded_to_cheap_model' => $downgraded,
            'tier' => '1_daily_driver',
            'specialist_claude_note' => 'Claude (Tier 2) is used for consensus / second opinion with cached framework context — not for this chat.',
            'estimated_input_tokens' => (int)($eval['estimated_input_tokens'] ?? 0),
            'estimated_turn_eur_max' => (float)($eval['estimated_turn_eur_max'] ?? 0.0),
            'daily_spend_est_eur' => (float)($eval['daily_spend_est_eur'] ?? 0.0),
            'daily_cap_eur' => (float)($eval['daily_cap_eur'] ?? 0.0),
            'remaining_budget_eur_before_turn' => $saldo['remaining_before_eur'],
            'remaining_budget_eur_after_turn_est' => $saldo['remaining_after_turn_eur'],
            'spent_today_est_eur' => $saldo['spent_today_est_eur'],
            'this_turn_max_est_eur' => $saldo['this_turn_max_est_eur'],
            'user_tokens_today' => (int)($eval['user_tokens_today'] ?? 0),
            'listing_tokens_today' => (int)($eval['listing_tokens_today'] ?? 0),
            'warnings' => is_array($eval['warnings'] ?? null) ? $eval['warnings'] : [],
            'model_used' => $model,
            'reason' => $eval['reason'] ?? null,
            'user_id' => (int)($ctx['user_id'] ?? 0),
            'listing_id' => (int)($ctx['listing_id'] ?? 0),
        ];
        if ($taskSeverity !== null && $taskSeverity !== '') {
            $budgetGuard['task_severity'] = $taskSeverity;
        }
        if ($modelRouterMeta !== null && $modelRouterMeta !== []) {
            $budgetGuard['model_router'] = $modelRouterMeta;
        }

        $replyText = $this->composeReplyTextForChat($decoded, $summary);

        return [
            'ok' => true,
            'raw_json' => $decoded,
            'reply_text' => $replyText,
            'provider' => $provider,
            'budget_guard' => $budgetGuard,
            'patch_token_warning' => $patchWarn,
        ];
    }

    /**
     * Never expose raw JSON to the chat when summary is empty (previous behaviour used json_encode).
     *
     * @param array<string, mixed> $decoded
     */
    private function composeReplyTextForChat(array $decoded, string $summaryTrimmed): string
    {
        if ($summaryTrimmed !== '') {
            return $summaryTrimmed;
        }
        $risks = $decoded['risks'] ?? [];
        if (is_array($risks)) {
            $lines = [];
            foreach ($risks as $r) {
                $s = trim((string) $r);
                if ($s !== '') {
                    $lines[] = '• ' . $s;
                }
            }
            if ($lines !== []) {
                return "Het model gaf geen samenvatting; wel risico's in het JSON-antwoord:\n" . implode("\n", $lines);
            }
        }

        return 'Het model gaf een lege samenvatting (lege JSON). Probeer opnieuw, stel een kortere vraag, of controleer API-limieten en budget.';
    }

    /**
     * Na een respawn moet de Architect expliciet de golden-config-rugzak gebruiken en geen fout herhalen.
     */
    private function appendRespawnRugzakInstruction(\App\Core\Config $config): string
    {
        $evo = $config->get('evolution', []);
        $sk = is_array($evo) ? ($evo['survival_kit'] ?? []) : [];
        $r = is_array($evo) ? ($evo['respawn'] ?? []) : [];
        if ((!is_array($sk) || !filter_var($sk['enabled'] ?? false, FILTER_VALIDATE_BOOL))
            && (!is_array($r) || !filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOL))) {
            return '';
        }

        return <<<'RESPAWN_INSTR'


RESPAWN_RUGZAK_INSTRUCTION (Level 7):
- Als er een recente death-log of flight-recorder staat: het systeem is mogelijk **gerespawnd** na een fatale fout. Je bent opnieuw ingeladen vlak vóór die toestand.
- Gebruik je **rugzak** (golden configs hierboven): herstel consistentie met die snapshots; herhaal de fout niet.
- Blijf binnen ArchitecturalPolicyGuard; houd patches klein; nooit secrets of .env-waarden in output.
- Controleer mentaal syntax, imports en hot-path immuniteit voordat je destructieve wijzigingen voorstelt.

RESPAWN_INSTR;
    }

    /**
     * Reverse sync: repo / live CSS+Twig is authoritative over stale Figma layers.
     */
    private function appendFigmaPolicy(\App\Core\Config $config): string
    {
        if (!EvolutionFigmaService::isEnabled($config)) {
            return '';
        }

        return <<<'END_FIGMA_POLICY'


FIGMA_POLICY (figma_bridge enabled):
- De **code en live site** (CSS-variabelen, Twig-structuur, tokens) zijn leidend ten opzichte van een bestaand Figma-ontwerp.
- Indien de gebruiker vraagt om een **Reverse Sync** of om het huidige website-design naar Figma te pushen, beschouw het bestaande ontwerp in Figma als **verouderd**.
- Gebruik waar mogelijk **delete-acties** via de Figma API (GET /v1/files/:key → top-level node ids per pagina → DELETE batches) om het canvas te legen vóór injectie van de actuele framework-structuur; als de REST-API geen DELETE ondersteunt, geef dan de verzamelde `node_ids` door voor **Figma Plugin** (`node.remove()`) of **MCP use_figma**, en vermeld een **duplicate** van het bestand als backup.
- Volgorde **Clean Slate**: (1) canvas legen / ids verzamelen → (2) lokale `storage/evolution/figma/reverse_sync_manifest.json` wissen → (3) `pushDesignToFigma` / `EvolutionFigmaService::cleanSlatePushToFigma()` — destructieve stappen vereisen bevestiging (admin `confirm:true`) of handmatige goedkeuring in het dashboard wanneer ArchitecturalPolicyGuard dit als **high severity** markeert.
- **Server-CLI:** `php ai_bridge.php evolution:figma status` (token + file + endpoint-check); `evolution:figma register-webhook` registreert FILE_UPDATE → `/api/v1/evolution/figma-webhook` (FIGMA_WEBHOOK_PASSCODE + publieke `site.url`/APP_URL). **EvolutionOutreach** is voor e-mail/PR — **niet** voor Figma-webhooks.
- **Cursor / MCP:** een leeg bestand vullen of de huidige UI vastleggen gaat via **Cursor → MCP → Figma** (of plugin); PHP levert manifests/plugin JSON (`pushDesignToFigma`), geen ingebouwde `capture_ui` op de server. Zonder **PAT** (`FIGMA_ACCESS_TOKEN`) faalt schrijven naar de Figma API — dat is geen aparte "Police"-blokkade op api.figma.com.
END_FIGMA_POLICY;
    }

    private function appendImmunePaths(\App\Core\Config $config): string
    {
        $evo = $config->get('evolution', []);
        $arch = is_array($evo) ? ($evo['architect'] ?? []) : [];
        $aa = is_array($arch) ? ($arch['auto_apply'] ?? []) : [];
        $immune = is_array($aa) ? ($aa['immune_paths'] ?? []) : [];
        if (!is_array($immune) || $immune === []) {
            return '';
        }

        $lines = ["\nIMMUNE_FILES (always use severity 'high' for these — no auto-apply):"];
        foreach ($immune as $path) {
            $lines[] = '  - App\\' . str_replace('/', '\\', (string)$path);
        }

        return implode("\n", $lines);
    }

    /**
     * Strips optional ```json fences from model output (Anthropic).
     *
     * @return array<string, mixed>|null
     */
    private static function decodeJsonObject(string $raw): ?array
    {
        $t = trim($raw);
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $t, $m)) {
            $t = trim($m[1]);
        }
        $decoded = json_decode($t, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * JIT Learning: detecteer topic in de taak en laad de meest relevante GitHub-les
     * in het system prompt. Geeft '' terug als academy.github.enabled = false.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $arch
     */
    private function injectJitLesson(array $messages, array $arch): string
    {
        try {
            $cfg = $this->container->get('config');
            $academyCfg = is_object($cfg) && method_exists($cfg, 'get')
                ? ($cfg->get('evolution.academy') ?? [])
                : [];

            if (!($academyCfg['jit_enabled'] ?? true)) {
                return '';
            }

            // Bouw task-tekst uit laatste user-berichten
            $taskText = '';
            foreach (array_reverse($messages) as $m) {
                if (($m['role'] ?? '') === 'user') {
                    $taskText = (string)($m['content'] ?? '');
                    break;
                }
            }

            if ($taskText === '') {
                return '';
            }

            $githubCfg = (array)($academyCfg['github'] ?? []);
            $lesson    = GitHubAcademyService::findLesson($taskText, $githubCfg);

            if ($lesson === null || strlen($lesson) < 50) {
                return '';
            }

            // Award XP voor laden van JIT les
            if ($academyCfg['xp_on_lesson_load'] ?? true) {
                $this->awardTaskXp('jit_lesson_loaded', 'JIT lesson injected');
            }

            $topic  = GitHubAcademyService::detectTopic($taskText) ?? 'unknown';
            $excerpt = mb_substr($lesson, 0, 1200);

            return "\n\n--- ACADEMY JIT LES [{$topic}] ---\n{$excerpt}\n--- (Pas deze les toe in je antwoord) ---\n";
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Injecteer een één-regel XP-statusmelding in het system prompt van de Architect.
     */
    private function injectXpStatus(): string
    {
        try {
            $cfg = $this->container->get('config');
            $xpCfg = is_object($cfg) && method_exists($cfg, 'get')
                ? ($cfg->get('evolution.xp') ?? [])
                : [];

            if (!($xpCfg['enabled'] ?? true) || !($xpCfg['inject_status_in_prompt'] ?? true)) {
                return '';
            }

            $db = $this->container->get('db');
            if (!$db instanceof \PDO) {
                return '';
            }

            return AgentXpService::promptStatusLine($db, 'architect');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Geef XP aan de Architect-agent na een actie.
     */
    private function awardTaskXp(string $event, string $context = ''): void
    {
        try {
            $db = $this->container->get('db');
            if (!$db instanceof \PDO) {
                return;
            }

            $cfg = $this->container->get('config');
            $xpCfg = is_object($cfg) && method_exists($cfg, 'get')
                ? ($cfg->get('evolution.xp') ?? [])
                : [];

            if (!($xpCfg['enabled'] ?? true)) {
                return;
            }

            AgentXpService::award($db, 'architect', $event, (array)$xpCfg, $context);
        } catch (\Throwable $e) {
            // Stil falen
        }
    }

    /**
     * Curiosity Trigger — vraagt Ollama (lokaal, goedkoop) of de Architect ergens
     * onzeker over was. Als ja → leervraag in de Academy-wachtrij.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function triggerCuriosityReflection(
        array  $messages,
        string $replyText,
        string $mode,
    ): void {
        try {
            $db = $this->container->get('db');
            if (!$db instanceof \PDO) {
                return;
            }

            // Bouw een korte samenvattende context van de taak
            $lastUserMsg = '';
            foreach (array_reverse($messages) as $m) {
                if (($m['role'] ?? '') === 'user') {
                    $lastUserMsg = mb_substr((string)($m['content'] ?? ''), 0, 400);
                    break;
                }
            }

            $reflectionPrompt = "Je hebt zojuist deze taak uitgevoerd:\n"
                . "VRAAG: " . $lastUserMsg . "\n"
                . "JOUW ANTWOORD (samenvatting): " . mb_substr($replyText, 0, 300) . "\n\n"
                . "Reflecteer eerlijk: Was er iets wat je NIET zeker wist, of had je iets efficiënter kunnen doen? "
                . "Als JA: formuleer ONE concrete leervraag in maximaal 2 zinnen. "
                . "Als NEE: antwoord precies 'NONE'.\n"
                . "Antwoord ALLEEN met de leervraag of 'NONE'. Geen uitleg.";

            // Gebruik Ollama (lokaal, gratis, snelle timeout)
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/json\r\n",
                    'content'       => json_encode([
                        'model'  => 'deepseek-r1:1.5b',
                        'prompt' => $reflectionPrompt,
                        'stream' => false,
                        'options'=> ['num_predict' => 80, 'temperature' => 0.3],
                    ]),
                    'timeout'       => 8,
                    'ignore_errors' => true,
                ],
            ]);

            @set_time_limit(20);
            $raw = @file_get_contents('http://ollama:11434/api/generate', false, $ctx);
            if (!is_string($raw) || $raw === '') {
                return;
            }

            $j        = json_decode($raw, true);
            $question = trim((string)($j['response'] ?? ''));

            // Verwijder denk-tags van DeepSeek
            $question = (string)preg_replace('/<think>[\s\S]*?<\/think>/i', '', $question);
            $question = trim($question);

            if ($question === '' || strtoupper($question) === 'NONE' || strlen($question) < 10) {
                return;
            }

            // Bereken curiosity score op basis van lengte/inhoud
            $score = min(1.0, max(0.3, strlen($question) / 200));

            $lessonId = AcademyService::requestLesson(
                db:            $db,
                taskSummary:   mb_substr($lastUserMsg, 0, 500),
                question:      $question,
                contextSnippet:mb_substr($replyText, 0, 500),
                curiosityScore:$score,
                sourceMode:    $mode,
            );

            if ($lessonId > 0) {
                $this->awardTaskXp('curiosity_trigger', "Academy vraag #{$lessonId}");
            }
        } catch (\Throwable $e) {
            // Stil falen — Academy mag de hoofdflow nooit blokkeren
            EvolutionLogger::log('academy', 'curiosity_error', ['error' => $e->getMessage()]);
        }
    }
}
