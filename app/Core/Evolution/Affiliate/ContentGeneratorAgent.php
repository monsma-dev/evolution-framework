<?php

declare(strict_types=1);

namespace App\Core\Evolution\Affiliate;

use Psr\Container\ContainerInterface;

/**
 * ContentGeneratorAgent — Genereert SEO-geoptimaliseerde affiliate content met Claude Sonnet.
 *
 * Werking:
 *   1. Neemt een opportunity uit de database.
 *   2. Genereert een professionele review of vergelijking (NL/EN).
 *   3. Optimaliseert voor zoekwoorden en long-tail SEO.
 *   4. Slaat op in affiliate_content tabel als 'draft'.
 *
 * Content types:
 *   - review       : Uitgebreide product/tool review
 *   - comparison   : Tool A vs Tool B
 *   - tutorial     : "Hoe gebruik je X voor Y"
 *   - landing_page : Affiliate landing pagina tekst
 *
 * Kosten: ~€0.01-0.03 per stuk (Claude Sonnet)
 */
final class ContentGeneratorAgent
{
    private const MODEL = 'claude-3-5-sonnet-20241022';

    private ?ContainerInterface   $container;
    private string                $basePath;
    private ?\PDO                 $db;
    private EntitySettingsManager $entity;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null, ?\PDO $db = null)
    {
        $this->container = $container;
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->db        = $db;
        $this->entity    = new EntitySettingsManager($container, $this->basePath);
    }

    /**
     * Genereer content voor een specifieke opportunity.
     *
     * @return array{ok: bool, content_id: ?int, word_count: int, cost_eur: float, summary: string}
     */
    public function generate(int $opportunityId, string $contentType = 'review', string $language = 'nl'): array
    {
        $opportunity = $this->loadOpportunity($opportunityId);
        if ($opportunity === null) {
            return ['ok' => false, 'content_id' => null, 'word_count' => 0, 'cost_eur' => 0.0, 'summary' => 'Opportunity niet gevonden'];
        }

        if ($this->container === null) {
            return ['ok' => false, 'content_id' => null, 'word_count' => 0, 'cost_eur' => 0.0, 'summary' => 'Geen AI container'];
        }

        try {
            $llm    = new \App\Domain\AI\LlmClient($this->container);
            $prompt = $this->buildPrompt($opportunity, $contentType, $language);
            $system = $this->buildSystem($language);

            $result = $llm->callModel(self::MODEL, $system, $prompt);
            $text   = (string)($result['content'] ?? '');
            $cost   = (float)($result['cost_eur'] ?? 0.02);

            $parsed    = $this->parseContent($text);
            $wordCount = str_word_count(strip_tags($parsed['content']));

            $contentId = $this->saveContent($opportunityId, $contentType, $parsed, $wordCount, $cost);
            $this->updateOpportunityStatus($opportunityId, 'content_drafted', $parsed['content']);

            return [
                'ok'         => true,
                'content_id' => $contentId,
                'word_count' => $wordCount,
                'cost_eur'   => round($cost, 4),
                'summary'    => "Content gegenereerd: {$wordCount} woorden, type: {$contentType}",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'content_id' => null, 'word_count' => 0, 'cost_eur' => 0.0, 'summary' => $e->getMessage()];
        }
    }

    /** Haal gegenereerde content drafts op. */
    public function getDrafts(int $limit = 10): array
    {
        if ($this->db === null) {
            return [];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT c.id, c.title, c.content_type, c.word_count, c.status, c.created_at,
                        o.niche, o.potential_value
                 FROM affiliate_content c
                 JOIN affiliate_opportunities o ON c.opportunity_id = o.id
                 ORDER BY c.created_at DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    private function buildSystem(string $language): string
    {
        if ($this->entity->isBedrijf()) {
            $companyName = $this->entity->displayName();
            return $language === 'nl'
                ? "Je bent een senior content strateeg die schrijft namens {$companyName} Solutions. "
                  . 'Je produceert professionele whitepapers, zakelijke analyses en thought leadership content in het Nederlands. '
                  . 'Toon: formeel, gezaghebbend, data-gedreven. Vermijd directe zelfpromotie; overtuig via bewijs en inzicht.'
                : "You are a senior content strategist writing on behalf of {$companyName} Solutions. "
                  . 'You produce professional whitepapers, business analyses, and thought leadership content in English. '
                  . 'Tone: formal, authoritative, data-driven. Avoid direct self-promotion; convince through evidence and insight.';
        }

        return $language === 'nl'
            ? 'Je bent een onafhankelijk expert en recensent. Je schrijft eerlijke, persoonlijke reviews en vergelijkingen in het Nederlands vanuit je eigen ervaring. '
              . 'Toon: informeel, toegankelijk, authentiek. Lezers vertrouwen jou als onafhankelijke stem — geen reclamepraatjes.'
            : 'You are an independent expert and reviewer. You write honest, personal reviews and comparisons in English based on your own experience. '
              . 'Tone: informal, accessible, authentic. Readers trust you as an independent voice — no marketing speak.';
    }

    private function buildPrompt(array $opportunity, string $contentType, string $language): string
    {
        $title   = $opportunity['title'] ?? '';
        $summary = $opportunity['summary'] ?? '';
        $niche   = $opportunity['niche'] ?? 'tech';
        $lang    = $language === 'nl' ? 'Nederlands' : 'English';

        $typeInstructions = $this->entity->isBedrijf()
            ? match ($contentType) {
                'review'      => 'Schrijf een objectieve, zakelijke product/dienst analyse (700-1000 woorden). Verwijs naar ROI en bedrijfsimpact.',
                'comparison'  => 'Schrijf een professioneel vergelijkingsrapport met executieve samenvatting en aanbeveling (600-900 woorden).',
                'tutorial'    => 'Schrijf een gestructureerde implementatiegids met stappen, vereisten en KPIs (600-800 woorden).',
                'landing_page'=> 'Schrijf een zakelijke landingspagina gericht op B2B-beslissers (500-700 woorden).',
                'whitepaper'  => 'Schrijf een executive whitepaper met marktinzichten, case study en strategische aanbevelingen (800-1200 woorden).',
                default       => 'Schrijf een zakelijk, informatief artikel gericht op professionals (600-800 woorden).',
              }
            : match ($contentType) {
                'review'      => 'Schrijf een uitgebreide, eerlijke product review (600-800 woorden).',
                'comparison'  => 'Schrijf een vergelijkingsartikel met 3 alternatieven (500-700 woorden).',
                'tutorial'    => 'Schrijf een stap-voor-stap handleiding (500-700 woorden).',
                'landing_page'=> 'Schrijf overtuigende affiliate landing page tekst (400-600 woorden).',
                default       => 'Schrijf een nuttig, informatief artikel (500-700 woorden).',
              };

        return "Maak SEO-geoptimaliseerde affiliate content in het {$lang}.\n\n"
             . "PRODUCT/TOOL: {$title}\n"
             . "ACHTERGROND: {$summary}\n"
             . "NICHE: {$niche}\n\n"
             . $typeInstructions . "\n\n"
             . "Geef PRECIES in dit formaat:\n"
             . "TITLE: [SEO-optimale H1 titel]\n"
             . "META: [Meta description van 120-155 tekens]\n"
             . "KEYWORDS: [5-8 zoekwoorden, komma-gescheiden]\n"
             . "CONTENT:\n[Volledige artikel tekst met H2/H3 koppen]\n\n"
             . "Eisen: Gebruik echte feiten. Vermijd overdreven claims. Voeg call-to-action toe aan het einde.";
    }

    private function parseContent(string $text): array
    {
        $title    = $this->extractField($text, 'TITLE',    'Untitled');
        $meta     = $this->extractField($text, 'META',     '');
        $keywords = $this->extractField($text, 'KEYWORDS', '');
        $content  = $this->extractBlock($text, 'CONTENT');

        return [
            'title'       => $title,
            'meta'        => $meta,
            'keywords'    => array_map('trim', explode(',', $keywords)),
            'content'     => $content ?: $text,
        ];
    }

    private function saveContent(int $opportunityId, string $type, array $parsed, int $wordCount, float $cost): ?int
    {
        if ($this->db === null) {
            return null;
        }
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO affiliate_content
                 (opportunity_id, content_type, title, meta_description, content, seo_keywords, word_count, ai_model, cost_eur, status)
                 VALUES (:oid, :type, :title, :meta, :content, :kw, :wc, :model, :cost, "draft")'
            );
            $stmt->execute([
                ':oid'     => $opportunityId,
                ':type'    => $type,
                ':title'   => mb_substr($parsed['title'], 0, 500),
                ':meta'    => mb_substr($parsed['meta'],  0, 300),
                ':content' => $parsed['content'],
                ':kw'      => json_encode($parsed['keywords']),
                ':wc'      => $wordCount,
                ':model'   => self::MODEL,
                ':cost'    => $cost,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function updateOpportunityStatus(int $id, string $status, string $content): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE affiliate_opportunities SET status = :s, generated_content = :c WHERE id = :id'
            );
            $stmt->execute([':s' => $status, ':c' => mb_substr($content, 0, 10000), ':id' => $id]);
        } catch (\Throwable) {
        }
    }

    private function loadOpportunity(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        try {
            $stmt = $this->db->prepare('SELECT * FROM affiliate_opportunities WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractField(string $text, string $key, string $default): string
    {
        if (preg_match('/^' . $key . ':\s*(.+)/mi', $text, $m)) {
            return trim($m[1]);
        }
        return $default;
    }

    private function extractBlock(string $text, string $key): string
    {
        if (preg_match('/^' . $key . ':\s*\n([\s\S]+?)(?=\n[A-Z_]+:|$)/m', $text, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}
