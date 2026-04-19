<?php

declare(strict_types=1);

namespace App\Core\Evolution\Affiliate;

use Psr\Container\ContainerInterface;

/**
 * LeadAnalyzer — Vindt zakelijke leads die hulp nodig hebben met AI/automatisering.
 *
 * Werking:
 *   1. Zoekt via Tavily (bestaande web_search config) naar problemen
 *      op X/Reddit/LinkedIn over 'AI-implementatie', 'automatisering', etc.
 *   2. Gebruikt Haiku om een profiel te maken: probleem, score, oplossing.
 *   3. Slaat leads op in potential_clients tabel.
 *   4. Stuurt Telegram-notificatie bij score >= HIGH_QUALITY_THRESHOLD (8).
 *
 * VEILIGHEID: De agent mag GEEN contact opnemen zonder handmatige
 * Telegram-goedkeuring (Authority Level 1). Status: 'new' → 'telegram_notified'
 * → 'approved' (handmatig) → 'contacted'.
 */
final class LeadAnalyzer
{
    private const HIGH_QUALITY_THRESHOLD = 8;
    private const SEARCH_QUERIES = [
        '"how to implement AI" site:reddit.com OR site:x.com',
        '"AI automation" "need help" OR "looking for" site:reddit.com',
        '"database AI" "integration" problem site:stackoverflow.com',
        '"e-commerce automation" consultant 2025',
        '"AI agent" implementation company solution',
    ];

    private const TELEGRAM_API = 'https://api.telegram.org/bot%s/sendMessage';

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
     * Zoek en analyseer leads.
     *
     * @return array{found: int, scored: int, high_quality: int, cost_eur: float}
     */
    public function analyze(int $maxQueries = 3): array
    {
        $results    = [];
        $totalCost  = 0.0;
        $highQ      = 0;

        foreach (array_slice(self::SEARCH_QUERIES, 0, $maxQueries) as $query) {
            $searchResults = $this->webSearch($query);
            $results       = array_merge($results, $searchResults);
        }

        if (empty($results)) {
            return ['found' => 0, 'scored' => 0, 'high_quality' => 0, 'cost_eur' => 0.0];
        }

        $scored = 0;
        foreach (array_slice($results, 0, 15) as $result) {
            $analysis  = $this->analyzeLeadWithHaiku($result);
            $totalCost += $analysis['cost_eur'] ?? 0.0;

            if (($analysis['score'] ?? 0) >= 4) {
                $scored++;
                $this->saveLead($result, $analysis);

                if (($analysis['score'] ?? 0) >= self::HIGH_QUALITY_THRESHOLD) {
                    $highQ++;
                    $this->sendTelegramAlert($result, $analysis);
                }
            }
        }

        return [
            'found'        => count($results),
            'scored'       => $scored,
            'high_quality' => $highQ,
            'cost_eur'     => round($totalCost, 4),
        ];
    }

    /** Haal recente leads op. */
    public function getRecentLeads(int $limit = 10): array
    {
        if ($this->db === null) {
            return [];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT id, name, platform, problem_summary, score, niche, status, telegram_approved, found_at
                 FROM potential_clients
                 ORDER BY score DESC, found_at DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Keur een lead goed voor contact (handmatige actie via dashboard). */
    public function approveLead(int $id): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE potential_clients SET telegram_approved = 1, status = "approved" WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Interne helpers ───────────────────────────────────────────────────

    private function webSearch(string $query): array
    {
        $tavilyKey = $this->getTavilyKey();
        if ($tavilyKey === '') {
            return [];
        }

        $url  = 'https://api.tavily.com/search';
        $body = json_encode([
            'api_key'        => $tavilyKey,
            'query'          => $query,
            'search_depth'   => 'basic',
            'max_results'    => 5,
            'include_answer' => false,
        ]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen((string)$body),
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return [];
        }

        $json = json_decode($raw, true);
        return (array)($json['results'] ?? []);
    }

    /**
     * @return array{score: int, niche: string, problem: string, solution: string, cost_eur: float}
     */
    private function analyzeLeadWithHaiku(array $result): array
    {
        $default = ['score' => 3, 'niche' => 'tech', 'problem' => '', 'solution' => '', 'cost_eur' => 0.0];

        if ($this->container === null) {
            return $default;
        }

        try {
            $llm    = new \App\Domain\AI\LlmClient($this->container);
            $title  = (string)($result['title']   ?? '');
            $snippet= mb_substr((string)($result['content'] ?? $result['snippet'] ?? ''), 0, 500);

            $senderContext = $this->entity->isBedrijf()
                ? 'een AI/automatisering bedrijf genaamd ' . $this->entity->displayName() . ' Solutions'
                : 'een onafhankelijke AI-expert en freelancer';

            $prompt = "Analyseer dit web-resultaat als potentiële B2B lead voor {$senderContext}:\n\n"
                    . "Titel: {$title}\nTekst: {$snippet}\n\n"
                    . "Geef PRECIES:\n"
                    . "SCORE: [1-10, 8+ = hoge kwaliteit zakelijke kans]\n"
                    . "NICHE: [bijv. e-commerce, juridisch, zorg, logistiek]\n"
                    . "PROBLEEM: [max 1 zin: wat is hun AI-behoefte?]\n"
                    . "OPLOSSING: [max 1 zin: hoe kan ons framework dit oplossen?]\n"
                    . "Score 8+ ALLEEN als: duidelijk B2B, actieve zoektocht naar oplossing, budget aanwezig.";

            $result2  = $llm->callModel('claude-3-5-haiku-20241022', 'Je bent een B2B sales intelligence expert.', $prompt);
            $text     = (string)($result2['content'] ?? '');
            $cost     = (float)($result2['cost_eur'] ?? 0.001);

            return [
                'score'    => max(1, min(10, (int)$this->extract($text, 'SCORE', '3'))),
                'niche'    => strtolower(trim($this->extract($text, 'NICHE', 'tech'))),
                'problem'  => $this->extract($text, 'PROBLEEM', ''),
                'solution' => $this->extract($text, 'OPLOSSING', ''),
                'cost_eur' => $cost,
            ];
        } catch (\Throwable) {
            return $default;
        }
    }

    private function saveLead(array $result, array $analysis): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO potential_clients
                 (public_id, name, platform, profile_url, problem_summary, solution_match, score, niche, ai_analysis, found_at)
                 VALUES (:pid, :name, :platform, :url, :problem, :solution, :score, :niche, :ai, NOW())'
            );
            $domain = parse_url((string)($result['url'] ?? ''), PHP_URL_HOST) ?: 'unknown';
            $stmt->execute([
                ':pid'      => bin2hex(random_bytes(13)),
                ':name'     => mb_substr((string)($result['title'] ?? ''), 0, 255),
                ':platform' => $this->detectPlatform($domain),
                ':url'      => mb_substr((string)($result['url'] ?? ''), 0, 1000),
                ':problem'  => mb_substr($analysis['problem'], 0, 1000),
                ':solution' => mb_substr($analysis['solution'], 0, 1000),
                ':score'    => $analysis['score'],
                ':niche'    => mb_substr($analysis['niche'], 0, 100),
                ':ai'       => json_encode($analysis),
            ]);
        } catch (\Throwable) {
        }
    }

    private function sendTelegramAlert(array $result, array $analysis): void
    {
        $token  = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
        $chatId = trim((string)(getenv('TELEGRAM_CHAT_ID') ?: ''));
        if ($token === '' || $chatId === '') {
            return;
        }

        $score    = $analysis['score'];
        $niche    = htmlspecialchars($analysis['niche']);
        $problem  = htmlspecialchars($analysis['problem']);
        $solution = htmlspecialchars($analysis['solution']);
        $url      = htmlspecialchars((string)($result['url'] ?? ''));

        $entityTag = $this->entity->isBedrijf()
            ? '🏢 <b>' . htmlspecialchars($this->entity->displayName()) . ' Solutions</b>'
            : '👤 <b>Onafhankelijke expert</b>';

        $msg = "💼 <b>Nieuwe Zakelijke Kans!</b> ({$entityTag})\n\n"
             . "⭐ Score: <code>{$score}/10</code>\n"
             . "🎯 Niche: <code>{$niche}</code>\n\n"
             . "🔍 <b>Probleem:</b>\n<i>{$problem}</i>\n\n"
             . "✅ <b>Onze oplossing:</b>\n<i>{$solution}</i>\n\n"
             . "🔗 <a href=\"{$url}\">Bekijk bron</a>\n\n"
             . "⚠️ <b>Goedkeuring vereist</b> — Ga naar het dashboard om contact te autoriseren.";

        $body = json_encode([
            'chat_id'    => $chatId,
            'text'       => $msg,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen((string)$body),
            'content'       => $body,
            'timeout'       => 6,
            'ignore_errors' => true,
        ]]);

        @file_get_contents(sprintf(self::TELEGRAM_API, rawurlencode($token)), false, $ctx);
    }

    private function detectPlatform(string $domain): string
    {
        return match (true) {
            str_contains($domain, 'twitter') || str_contains($domain, 'x.com') => 'twitter',
            str_contains($domain, 'linkedin')  => 'linkedin',
            str_contains($domain, 'reddit')    => 'reddit',
            str_contains($domain, 'stackoverflow') => 'stackoverflow',
            default                            => 'web',
        };
    }

    private function extract(string $text, string $key, string $default): string
    {
        if (preg_match('/^' . $key . ':\s*(.+)/mi', $text, $m)) {
            return trim($m[1]);
        }
        return $default;
    }

    private function getTavilyKey(): string
    {
        if ($this->container !== null) {
            try {
                $cfg = $this->container->get('config');
                $key = (string)($cfg->get('evolution.web_search.api_key', '') ?? '');
                if ($key !== '') {
                    return $key;
                }
            } catch (\Throwable) {
            }
        }
        return trim((string)(getenv('TAVILY_API_KEY') ?: ''));
    }
}
