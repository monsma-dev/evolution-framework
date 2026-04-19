<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * AEO/GEO: llms.txt, .ai-context, robots.txt, ai-sitemap, Schema.org JSON-LD, agent digest under .cursor/rules.
 */
final class EvolutionAeoService
{
    public const AI_VISIBILITY_STATE = 'storage/evolution/ai_visibility_state.json';

    public const HOF_ONCE = 'storage/evolution/.aeo_geo_hof_done';

    /**
     * @return array{ok: bool, error?: string, paths?: list<string>}
     */
    public static function sync(Container $container): array
    {
        $cfg = $container->get('config');
        $a = $cfg->get('evolution.aeo', []);
        if (!is_array($a) || !filter_var($a['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'aeo disabled'];
        }

        $siteName = (string) $cfg->get('site.name', 'Framework');
        $siteUrl = rtrim((string) $cfg->get('site.url', ''), '/');
        if ($siteUrl === '') {
            $siteUrl = 'http://localhost';
        }

        $brand = trim((string) ($a['brand_entity'] ?? $siteName));
        $canonicalQ = trim((string) ($a['canonical_question'] ?? 'What is ' . $brand . ' and what problem does it solve?'));
        $activities = $a['core_activities'] ?? ['marketplace', 'auctions', 'secure payments', 'Evolution AI stack'];
        $activities = is_array($activities) ? array_values(array_map('strval', $activities)) : [];
        $signature = trim((string) ($a['signature_line'] ?? 'The machine is now yours.'));

        $kgPath = BASE_PATH . '/data/evolution/knowledge_graph.json';
        $kg = [];
        if (is_file($kgPath)) {
            $rawKg = @file_get_contents($kgPath);
            $kg = is_string($rawKg) ? (json_decode($rawKg, true) ?: []) : [];
        }
        $nodes = is_array($kg['nodes'] ?? null) ? $kg['nodes'] : [];
        $edges = is_array($kg['edges'] ?? null) ? $kg['edges'] : [];
        $nodeSample = array_slice(array_map(static fn ($n) => is_array($n) ? (string) ($n['id'] ?? '') : '', $nodes), 0, 40);
        $nodeSample = array_values(array_filter($nodeSample, static fn ($x) => $x !== ''));

        $wikiExcerpt = self::readWikiExcerpt($cfg);

        $llmsShort = self::buildLlmsShort($canonicalQ, $brand, $activities, $siteUrl, $wikiExcerpt, count($nodes), count($edges));
        $llmsFull = self::buildLlmsFull($llmsShort, $brand, $activities, $siteUrl, $nodeSample, $edges, $wikiExcerpt);

        $publicDir = BASE_PATH . '/web';
        if (!is_dir($publicDir) && !@mkdir($publicDir, 0755, true) && !is_dir($publicDir)) {
            return ['ok' => false, 'error' => 'cannot create web/'];
        }

        $written = [];
        foreach ([
            'llms.txt' => $llmsShort,
            'llms-full.txt' => $llmsFull,
        ] as $name => $body) {
            $p = $publicDir . '/' . $name;
            if (@file_put_contents($p, $body) === false) {
                return ['ok' => false, 'error' => 'cannot write ' . $name];
            }
            $written[] = $p;
        }

        $aiContext = self::buildAiContextFile($brand, $canonicalQ, $signature, $siteUrl, $nodeSample, $edges, $kg);
        $rootCtx = BASE_PATH . '/.ai-context';
        if (@file_put_contents($rootCtx, $aiContext) === false) {
            return ['ok' => false, 'error' => 'cannot write .ai-context'];
        }
        $written[] = $rootCtx;

        $robots = self::buildRobotsTxt($siteUrl, $a);
        if (@file_put_contents($publicDir . '/robots.txt', $robots) === false) {
            return ['ok' => false, 'error' => 'cannot write robots.txt'];
        }
        $written[] = $publicDir . '/robots.txt';

        $paths = $a['public_paths'] ?? ['/', '/browse', '/help/how-it-works'];
        $paths = is_array($paths) ? array_values(array_map('strval', $paths)) : ['/', '/browse'];
        $aiSitemap = self::buildAiSitemapXml($siteUrl, $paths);
        if (@file_put_contents($publicDir . '/ai-sitemap.xml', $aiSitemap) === false) {
            return ['ok' => false, 'error' => 'cannot write ai-sitemap.xml'];
        }
        $written[] = $publicDir . '/ai-sitemap.xml';

        self::writeCursorRulesDigest($brand, $nodeSample, $edges, $canonicalQ);

        $score = self::computeVisibilityScore(is_file($publicDir . '/llms.txt'), is_file($publicDir . '/robots.txt'), count($nodes));

        $state = [
            'updated_at' => gmdate('c'),
            'llms_txt_bytes' => strlen($llmsShort),
            'llms_full_bytes' => strlen($llmsFull),
            'ai_context_bytes' => strlen($aiContext),
            'ai_visibility_score' => $score,
            'brand_entity' => $brand,
            'canonical_question' => $canonicalQ,
            'knowledge_nodes' => count($nodes),
            'perplexity_audit' => [
                'note' => 'Run a manual brand check in Perplexity/ChatGPT or wire evolution.web_search for automated probes.',
                'last_query' => null,
                'brand_mentioned' => null,
            ],
        ];
        $statePath = BASE_PATH . '/' . self::AI_VISIBILITY_STATE;
        $stateDir = dirname($statePath);
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0755, true);
        }
        @file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

        self::recordHallOfFameOnce($container);

        EvolutionLogger::log('aeo', 'sync', ['score' => $score, 'nodes' => count($nodes)]);

        return ['ok' => true, 'paths' => $written];
    }

    /**
     * @return array{ok: bool, score?: int, note?: string, llms_txt?: bool, robots_txt?: bool}
     */
    public static function pulseCheck(Config $config): array
    {
        $a = $config->get('evolution.aeo', []);
        if (!is_array($a) || !filter_var($a['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'note' => 'aeo disabled', 'score' => 0];
        }

        $llms = is_file(BASE_PATH . '/web/llms.txt');
        $robots = is_file(BASE_PATH . '/web/robots.txt');
        $kgPath = BASE_PATH . '/data/evolution/knowledge_graph.json';
        $nodes = 0;
        if (is_file($kgPath)) {
            $raw = @file_get_contents($kgPath);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            $nodes = is_array($j) && isset($j['nodes']) && is_array($j['nodes']) ? count($j['nodes']) : 0;
        }

        $score = self::computeVisibilityScore($llms, $robots, $nodes);
        $statePath = BASE_PATH . '/' . self::AI_VISIBILITY_STATE;
        $auditNote = 'ok';
        if (is_file($statePath)) {
            $st = json_decode((string) @file_get_contents($statePath), true);
            if (is_array($st) && isset($st['perplexity_audit']['note'])) {
                $auditNote = (string) $st['perplexity_audit']['note'];
            }
        }

        return [
            'ok' => $score >= 40,
            'score' => $score,
            'llms_txt' => $llms,
            'robots_txt' => $robots,
            'knowledge_nodes' => $nodes,
            'note' => $auditNote,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function readDashboardState(): array
    {
        $path = BASE_PATH . '/' . self::AI_VISIBILITY_STATE;
        if (!is_file($path)) {
            return [
                'ok' => false,
                'error' => 'Run: php ai_bridge.php evolution:aeo-sync',
            ];
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($j) ? array_merge(['ok' => true], $j) : ['ok' => false, 'error' => 'invalid state'];
    }

    public static function headJsonLdScriptTags(Config $config, string $siteUrl, string $requestPath): string
    {
        $a = $config->get('evolution.aeo', []);
        if (!is_array($a) || !filter_var($a['json_ld_enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $siteUrl = rtrim($siteUrl, '/');
        $name = (string) $config->get('site.name', 'Framework');
        $brand = trim((string) ($a['brand_entity'] ?? $name));
        $desc = trim((string) ($a['site_description'] ?? $name . ' — marketplace platform with Evolution AI tooling.'));
        $logo = trim((string) ($a['logo_url'] ?? ($siteUrl . '/assets/images/logo.png')));

        $org = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $brand,
            'url' => $siteUrl . '/',
            'description' => $desc,
        ];
        if ($logo !== '' && str_starts_with($logo, 'http')) {
            $org['logo'] = $logo;
        }

        $offers = $a['software_application_offers'] ?? ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'];
        $offers = is_array($offers) ? $offers : ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'];

        $app = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => $brand,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $siteUrl . '/',
            'description' => $desc,
            'offers' => $offers,
        ];

        $parts = [$org, $app];

        $faqPatterns = $a['faq_path_patterns'] ?? ['/help/'];
        $faqPatterns = is_array($faqPatterns) ? $faqPatterns : ['/help/'];
        foreach ($faqPatterns as $pat) {
            if (is_string($pat) && $pat !== '' && str_contains($requestPath, trim($pat, '/'))) {
                $parts[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => [
                        [
                            '@type' => 'Question',
                            'name' => (string) ($a['faq_question'] ?? 'What is ' . $brand . '?'),
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text' => $desc,
                            ],
                        ],
                    ],
                ];
                break;
            }
        }

        $out = '';
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
        foreach ($parts as $block) {
            $json = json_encode($block, $flags);
            if (is_string($json)) {
                $out .= '<script type="application/ld+json">' . $json . "</script>\n";
            }
        }

        return $out;
    }

    private static function recordHallOfFameOnce(Container $container): void
    {
        $flag = BASE_PATH . '/' . self::HOF_ONCE;
        if (is_file($flag)) {
            return;
        }
        @file_put_contents($flag, gmdate('c') . "\n");
        (new EvolutionHallOfFameService($container))->recordMilestone(
            'AEO & GEO Sovereign: The Framework is now AI-Visible.',
            'aeo',
            ['aeo' => true, 'llms' => true]
        );
    }

    private static function computeVisibilityScore(bool $llms, bool $robots, int $nodes): int
    {
        $s = 0;
        if ($llms) {
            $s += 35;
        }
        if ($robots) {
            $s += 25;
        }
        if ($nodes > 50) {
            $s += 25;
        } elseif ($nodes > 0) {
            $s += 15;
        }
        if (is_file(BASE_PATH . '/.ai-context')) {
            $s += 15;
        }

        return min(100, $s);
    }

    private static function buildLlmsShort(
        string $canonicalQ,
        string $brand,
        array $activities,
        string $siteUrl,
        string $wikiExcerpt,
        int $nodeCount,
        int $edgeCount
    ): string {
        $lines = [];
        $lines[] = '# llms.txt — ' . $brand;
        $lines[] = '# Preferred for LLM crawlers (priority: canonical Q first)';
        $lines[] = '';
        $lines[] = '## Canonical question';
        $lines[] = $canonicalQ;
        $lines[] = '';
        $lines[] = '## Direct answer (<=50 words)';
        $direct = self::directAnswer($brand, $activities, $siteUrl);
        $lines[] = $direct;
        $lines[] = '';
        $lines[] = '## Entity';
        $lines[] = '- Brand: ' . $brand;
        $lines[] = '- Primary activities: ' . implode(', ', $activities);
        $lines[] = '- Site: ' . $siteUrl . '/';
        $lines[] = '- Knowledge graph: ' . $nodeCount . ' nodes, ' . $edgeCount . ' edges (see /llms-full.txt)';
        if ($wikiExcerpt !== '') {
            $lines[] = '';
            $lines[] = '## Evolution wiki (excerpt)';
            $lines[] = $wikiExcerpt;
        }
        $lines[] = '';
        $lines[] = '## For crawlers';
        $lines[] = 'Allow: /llms.txt, /llms-full.txt, /robots.txt, /ai-sitemap.xml';

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $nodeSample
     * @param list<array<string, mixed>> $edges
     */
    private static function buildLlmsFull(
        string $llmsShort,
        string $brand,
        array $activities,
        string $siteUrl,
        array $nodeSample,
        array $edges,
        string $wikiExcerpt
    ): string {
        $lines = [];
        $lines[] = $llmsShort;
        $lines[] = '';
        $lines[] = '## Full entity mapping';
        $lines[] = 'Brand "' . $brand . '" is consistently tied to: ' . implode('; ', $activities) . '.';
        $lines[] = 'Public entry: ' . $siteUrl . '/';
        $lines[] = '';
        $lines[] = '## Sample architecture nodes (App\\*)';
        foreach ($nodeSample as $id) {
            $lines[] = '- ' . $id;
        }
        $lines[] = '';
        $lines[] = '## Sample edges (uses)';
        $i = 0;
        foreach ($edges as $e) {
            if ($i++ >= 24) {
                break;
            }
            if (!is_array($e)) {
                continue;
            }
            $from = (string) ($e['from'] ?? '');
            $to = (string) ($e['to'] ?? '');
            if ($from !== '' && $to !== '') {
                $lines[] = '- ' . $from . ' → ' . $to;
            }
        }
        if ($wikiExcerpt !== '') {
            $lines[] = '';
            $lines[] = '## Evolution wiki (longer excerpt)';
            $lines[] = $wikiExcerpt;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $nodeSample
     * @param list<array<string, mixed>> $edges
     * @param array<string, mixed> $kg
     */
    private static function buildAiContextFile(
        string $brand,
        string $canonicalQ,
        string $signature,
        string $siteUrl,
        array $nodeSample,
        array $edges,
        array $kg
    ): string {
        $lines = [];
        $lines[] = '# .ai-context — agent onboarding (compressed)';
        $lines[] = 'generated_at: ' . gmdate('c');
        $lines[] = 'site: ' . $siteUrl;
        $lines[] = '';
        $lines[] = '## Canonical question';
        $lines[] = $canonicalQ;
        $lines[] = '';
        $lines[] = '## Brand entity';
        $lines[] = $brand;
        $lines[] = '';
        $lines[] = '## Topology (sample)';
        $n = 0;
        foreach ($edges as $e) {
            if ($n++ >= 30) {
                break;
            }
            if (!is_array($e)) {
                continue;
            }
            $from = (string) ($e['from'] ?? '');
            $to = (string) ($e['to'] ?? '');
            if ($from !== '' && $to !== '') {
                $lines[] = '- ' . $from . ' uses ' . $to;
            }
        }
        $lines[] = '';
        $lines[] = '## Class index (sample)';
        foreach (array_slice($nodeSample, 0, 60) as $id) {
            $lines[] = '- ' . $id;
        }
        $lines[] = '';
        $lines[] = '## Knowledge graph meta';
        $lines[] = 'nodes: ' . (is_array($kg['nodes'] ?? null) ? count($kg['nodes']) : 0);
        $lines[] = 'edges: ' . (is_array($kg['edges'] ?? null) ? count($kg['edges']) : 0);
        $lines[] = '';
        $lines[] = '## Signature';
        $lines[] = $signature;

        return implode("\n", $lines);
    }

    private static function buildRobotsTxt(string $siteUrl, array $aeo): string
    {
        $lines = [];
        $lines[] = '# Auto-maintained by EvolutionAeoService — allow AI crawlers';
        $lines[] = 'User-agent: *';
        $lines[] = 'Allow: /';
        $lines[] = '';
        $lines[] = 'User-agent: GPTBot';
        $lines[] = 'Allow: /';
        $lines[] = '';
        $lines[] = 'User-agent: Google-Extended';
        $lines[] = 'Allow: /';
        $lines[] = '';
        $lines[] = 'User-agent: PerplexityBot';
        $lines[] = 'Allow: /';
        $lines[] = '';
        $lines[] = 'User-agent: ChatGPT-User';
        $lines[] = 'Allow: /';
        $lines[] = '';
        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $lines[] = 'Host: ' . $host;
        }
        $sitemapBase = rtrim($siteUrl, '/');
        $lines[] = 'Sitemap: ' . $sitemapBase . '/ai-sitemap.xml';
        $extra = $aeo['robots_extra_lines'] ?? [];
        if (is_array($extra) && $extra !== []) {
            $lines[] = '';
            foreach ($extra as $line) {
                if (is_string($line) && $line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $paths
     */
    private static function buildAiSitemapXml(string $siteUrl, array $paths): string
    {
        $base = rtrim($siteUrl, '/');
        $urls = '';
        foreach ($paths as $p) {
            $p = '/' . ltrim((string) $p, '/');
            if ($p === '//') {
                $p = '/';
            }
            $loc = htmlspecialchars($base . $p, ENT_XML1 | ENT_QUOTES);
            $urls .= "  <url><loc>{$loc}</loc><priority>0.8</priority></url>\n";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . $urls
            . "</urlset>\n";
    }

    /**
     * @param list<string> $nodeSample
     * @param list<array<string, mixed>> $edges
     */
    private static function writeCursorRulesDigest(string $brand, array $nodeSample, array $edges, string $canonicalQ): void
    {
        $dir = BASE_PATH . '/.cursor/rules';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $lines = [];
        $lines[] = '---';
        $lines[] = 'description: Auto-generated knowledge graph digest for Cursor agents (AEO sync)';
        $lines[] = 'globs:';
        $lines[] = 'alwaysApply: false';
        $lines[] = '---';
        $lines[] = '# Evolution knowledge graph (synced)';
        $lines[] = '';
        $lines[] = 'Brand entity: **' . $brand . '**';
        $lines[] = 'Canonical question: ' . $canonicalQ;
        $lines[] = '';
        $lines[] = '## Sample edges';
        $n = 0;
        foreach ($edges as $e) {
            if ($n++ >= 40) {
                break;
            }
            if (!is_array($e)) {
                continue;
            }
            $from = (string) ($e['from'] ?? '');
            $to = (string) ($e['to'] ?? '');
            if ($from !== '' && $to !== '') {
                $lines[] = '- `' . $from . '` → `' . $to . '`';
            }
        }
        $lines[] = '';
        $lines[] = '## Sample nodes';
        foreach (array_slice($nodeSample, 0, 80) as $id) {
            $lines[] = '- `' . $id . '`';
        }
        $lines[] = '';
        $lines[] = '_Regenerate with `php ai_bridge.php evolution:aeo-sync`._';
        @file_put_contents($dir . '/evolution-knowledge-graph.mdc', implode("\n", $lines) . "\n");
    }

    private static function readWikiExcerpt(Config $config): string
    {
        $w = $config->get('evolution.evolution_wiki', []);
        $rel = is_array($w) ? trim((string) ($w['markdown_path'] ?? 'docs/EVOLUTION.md')) : 'docs/EVOLUTION.md';
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }
        $path = BASE_PATH . '/' . ltrim($rel, '/');
        if (!is_file($path)) {
            return '';
        }
        $raw = (string) @file_get_contents($path);
        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;

        return mb_substr(trim($raw), 0, 1200);
    }

    /**
     * @param list<string> $activities
     */
    private static function directAnswer(string $brand, array $activities, string $siteUrl): string
    {
        $act = implode(', ', $activities);
        $text = $brand . ' is a web platform focused on ' . $act . '. '
            . 'It provides a secure marketplace experience, admin Evolution tooling, and structured knowledge for AI agents. '
            . 'Official site: ' . $siteUrl . '/ .';
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if (count($words) > 50) {
            $text = implode(' ', array_slice($words, 0, 50)) . '…';
        }

        return $text;
    }
}
