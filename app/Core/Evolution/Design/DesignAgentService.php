<?php

declare(strict_types=1);

namespace App\Core\Evolution\Design;

use App\Core\Config;
use App\Core\Evolution\AnthropicMessagesClient;
use App\Core\Evolution\DeepSeekClient;
use App\Core\Evolution\EvolutionFigmaService;
use App\Core\Evolution\EvolutionProviderKeys;
use App\Core\Evolution\EvolutionLogger;

/**
 * DesignAgent — specialized sub-agent of the Architect for UI/UX/Tailwind generation.
 *
 * Uses DeepSeek-V3 (chat) for optimal HTML/CSS code output.
 * Specializations:
 *  - Tailwind CSS component generation from natural language
 *  - Figma-to-Tailwind conversion via FigmaTailwindParser
 *  - Component library persistence via DesignComponentLibrary
 *  - Brand-consistent output (Evolution color palette from evolution.json)
 *
 * Design principles enforced in system prompt:
 *  - Clean whitespace, modern font stacks, subtle shadows
 *  - Mobile-first responsive grids + flexbox
 *  - NO hallucinated Tailwind classes — standard utilities only
 *  - SVG-first for icons (text = free, no external requests)
 */
final class DesignAgentService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are DesignAgent, a specialist UI/UX engineer embedded in the Evolution Core framework.

## Your output rules (STRICT):
1. Generate only valid Tailwind CSS v3 utility classes — NO custom CSS, NO made-up classes.
2. Use only these colors unless overridden: slate, blue, indigo, emerald, amber, red (standard Tailwind palette).
3. All layouts MUST be responsive: start mobile-first, use sm:/md:/lg: breakpoints.
4. Use flexbox and grid for layout, never position:absolute for structure.
5. Every component MUST have a dark mode variant (dark: prefix).
6. Icons: use inline SVG only — never external icon fonts or libraries.
7. Output ONLY the HTML/Twig fragment — no <html>, no <head>, no <body> wrappers.
8. Add a <!-- COMPONENT: {name} --> comment at the top of every component.
9. Keep components self-contained: one file = one component.
10. Do NOT use arbitrary values like w-[327px] — use standard scale only.

## Typography:
- Headings: font-semibold tracking-tight
- Body: text-sm text-slate-600 dark:text-slate-400
- Labels: text-xs font-medium uppercase tracking-wide text-slate-500

## Card pattern:
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700 p-6">

## Button patterns:
- Primary: bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors
- Secondary: bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 px-4 py-2 rounded-lg text-sm font-medium transition-colors
- Danger: bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded-lg text-sm font-medium transition-colors

## Badge patterns:
- Success: bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400 px-2 py-0.5 rounded-full text-xs font-medium
- Warning: bg-amber-50 text-amber-700 px-2 py-0.5 rounded-full text-xs font-medium
- Error: bg-red-50 text-red-700 px-2 py-0.5 rounded-full text-xs font-medium
- Info: bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full text-xs font-medium

## Data tables:
<table class="w-full text-sm">
  <thead><tr class="border-b border-slate-100 dark:border-slate-700">
    <th class="text-left py-3 px-4 font-medium text-slate-500 text-xs uppercase tracking-wide">Column</th>
  </tr></thead>
  <tbody class="divide-y divide-slate-50 dark:divide-slate-800">

Respond with ONLY the HTML fragment. No explanations. No markdown fences.
PROMPT;

    public function __construct(
        private readonly Config $config,
        private readonly DesignComponentLibrary $library
    ) {}

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Generate a Tailwind CSS component from a natural-language description.
     * Saves the result to the component library automatically.
     *
     * @return array{ok: bool, html?: string, name?: string, saved_to?: string, error?: string}
     */
    public function generate(
        string $prompt,
        string $componentName = '',
        string $componentType = 'generic'
    ): array {
        $apiKey = trim((string)$this->config->get('ai.deepseek.api_key', ''));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'DeepSeek API key not configured'];
        }

        $name = $componentName !== ''
            ? preg_replace('/[^a-z0-9\-_]/', '-', strtolower($componentName))
            : 'component-' . gmdate('YmdHis');

        $messages = [
            ['role' => 'user', 'content' => "Generate a Tailwind CSS component for: {$prompt}\n\nComponent name: {$name}\nType: {$componentType}\n\nOutput ONLY the HTML fragment."],
        ];

        try {
            $client = new DeepSeekClient($apiKey);
            $html = $client->complete(self::SYSTEM_PROMPT, $messages, DeepSeekClient::MODEL_CHAT, 4096);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $html = $this->sanitizeHtml($html);
        $savedTo = $this->library->save($name, $html, ['type' => $componentType, 'prompt' => $prompt]);

        EvolutionLogger::log('design_agent', 'component_generated', ['name' => $name, 'type' => $componentType]);

        return ['ok' => true, 'html' => $html, 'name' => $name, 'saved_to' => $savedTo];
    }

    /**
     * Generate a Tailwind component from a Figma URL.
     *
     * @return array{ok: bool, html?: string, name?: string, saved_to?: string, figma_data?: array, error?: string}
     */
    public function generateFromFigma(string $figmaUrl, string $componentName = ''): array
    {
        $pat = EvolutionFigmaService::accessTokenForBridge($this->config);
        if ($pat === '') {
            return [
                'ok' => false,
                'error' => 'Figma PAT missing: FIGMA_ACCESS_TOKEN in .env, or evolution.figma.api_token / evolution.figma_bridge.access_token',
            ];
        }

        $fileErr = FigmaBaselineGuard::validateFileMatchesBaseline($this->config, $figmaUrl);
        if ($fileErr !== null) {
            return ['ok' => false, 'error' => $fileErr];
        }
        $strictErr = FigmaBaselineGuard::validateStrictNotBaselineRoot($this->config, $figmaUrl);
        if ($strictErr !== null) {
            return ['ok' => false, 'error' => $strictErr];
        }

        $parser = new FigmaTailwindParser();
        $parsed = $parser->parseUrl($figmaUrl, $pat);

        if (!($parsed['ok'] ?? false)) {
            return ['ok' => false, 'error' => $parsed['error'] ?? 'Figma parse failed'];
        }

        $contextJson = json_encode($parsed['context'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $prompt = "Convert this Figma design context to a Tailwind CSS component:\n\n{$contextJson}\n\nOutput ONLY the HTML fragment.";
        $prompt .= FigmaBaselineGuard::designAgentPromptSuffix($this->config, $figmaUrl);

        $name = $componentName !== ''
            ? preg_replace('/[^a-z0-9\-_]/', '-', strtolower($componentName))
            : 'figma-' . ($parsed['node_name'] ?? gmdate('YmdHis'));

        $html = $this->completeDesignPrompt($prompt);
        if ($html === null) {
            return [
                'ok' => false,
                'error' => 'No LLM configured for design (set ai.deepseek.api_key, or Anthropic via evolution.anthropic / ANTHROPIC_API_KEY)',
            ];
        }

        $html = $this->sanitizeHtml($html);
        $savedTo = $this->library->save($name, $html, [
            'type'      => 'figma_generated',
            'figma_url' => $figmaUrl,
            'node_name' => $parsed['node_name'] ?? '',
        ]);

        EvolutionLogger::log('design_agent', 'figma_component_generated', ['name' => $name, 'url' => $figmaUrl]);

        return [
            'ok'        => true,
            'html'      => $html,
            'name'      => $name,
            'saved_to'  => $savedTo,
            'figma_data' => $parsed['context'] ?? [],
        ];
    }

    /**
     * Detect if a message is design/UI related — used by ArchitectChatService for routing.
     */
    public static function isDesignTask(string $message): bool
    {
        $lower = strtolower($message);
        $keywords = [
            'ui', 'ux', 'design', 'layout', 'component', 'tailwind', 'css', 'button',
            'card', 'dashboard', 'table', 'form', 'modal', 'sidebar', 'navbar',
            'figma', 'responsive', 'mobile', 'dark mode', 'theme', 'color',
            'font', 'typography', 'grid', 'flex', 'style', 'interface', 'widget',
            'badge', 'chart', 'graph', 'visual', 'hero', 'landing',
        ];
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return false;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * DeepSeek when configured; otherwise Anthropic (same stack as evolve:figma-build).
     */
    private function completeDesignPrompt(string $prompt): ?string
    {
        $messages = [['role' => 'user', 'content' => $prompt]];
        $deepseek = trim((string) $this->config->get('ai.deepseek.api_key', ''));
        if ($deepseek !== '') {
            try {
                $client = new DeepSeekClient($deepseek);

                return $client->complete(self::SYSTEM_PROMPT, $messages, DeepSeekClient::MODEL_CHAT, 4096);
            } catch (\Throwable) {
                // try Anthropic below
            }
        }
        $anth = EvolutionProviderKeys::anthropic($this->config);
        if ($anth === '') {
            return null;
        }
        $model = trim((string) $this->config->get('evolution.architect.model', 'claude-3-5-sonnet-20241022'));
        try {
            $client = new AnthropicMessagesClient($anth);

            return $client->complete(self::SYSTEM_PROMPT, $messages, $model, 4096);
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizeHtml(string $html): string
    {
        // Strip markdown code fences if model returned them
        $html = preg_replace('/^```(?:html|twig|blade)?\s*\n?/m', '', $html) ?? $html;
        $html = preg_replace('/\n?```\s*$/m', '', $html) ?? $html;
        return trim($html);
    }
}
