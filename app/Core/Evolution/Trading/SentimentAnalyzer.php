<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Evolution\EvolutionProviderKeys;
use App\Domain\AI\LlmClient;
use Psr\Container\ContainerInterface;

/**
 * SentimentAnalyzer — Scoort ETH/crypto nieuwskoppen via Anthropic Haiku.
 *
 * Score: -1.0 (extreem Bearish) tot +1.0 (extreem Bullish), 0.0 = neutraal.
 * Cache: storage/evolution/trading/current_sentiment.json (60 min TTL)
 *
 * Fallback: Als Haiku niet beschikbaar is, wordt een keyword-gebaseerde
 * heuristiek gebruikt (geen AI-kosten, lager nauwkeurig maar veilig).
 */
final class SentimentAnalyzer
{
    private const CACHE_FILE = 'storage/evolution/trading/current_sentiment.json';
    private const CACHE_TTL  = 3600; // 60 minuten
    private const MODEL      = 'claude-3-5-haiku-20241022';
    private const MAX_TOKENS = 200;

    private const BEARISH_WORDS = [
        'crash', 'ban', 'hack', 'stolen', 'seizure', 'crackdown', 'lawsuit',
        'fraud', 'scam', 'collapse', 'bankruptcy', 'exploit', 'vulnerable',
        'sell-off', 'dumping', 'bear', 'decline', 'plunge', 'shortage', 'deficit',
    ];

    private const BULLISH_WORDS = [
        'rally', 'surge', 'adoption', 'upgrade', 'institutional', 'etf',
        'record', 'all-time', 'bullish', 'breakout', 'partnership', 'launch',
        'growth', 'demand', 'supply squeeze', 'staking', 'deflationary',
    ];

    private ?ContainerInterface $container;
    private string              $basePath;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null)
    {
        $this->container = $container;
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Geeft het huidige sentiment terug. Gebruikt cache als die nog vers is.
     *
     * @return array{score: float, headline_count: int, cached: bool, source: string, ts: string}
     */
    public function currentSentiment(bool $forceRefresh = false): array
    {
        $cached = $this->loadCache();
        if (!$forceRefresh && $cached !== null) {
            return array_merge($cached, ['cached' => true]);
        }

        $scraper   = new NewsScraperService($this->basePath);
        $headlines = $scraper->fetchHeadlines($forceRefresh);

        if (empty($headlines)) {
            return $this->neutralResult('Geen nieuwskoppen gevonden', 0);
        }

        $result = $this->analyzeWithAi($headlines);
        $this->saveCache($result);

        return array_merge($result, ['cached' => false]);
    }

    /**
     * Geeft alleen de score terug (-1.0 tot +1.0).
     * Meest gebruikte methode voor de TradingValidatorAgent.
     */
    public function getScore(bool $forceRefresh = false): float
    {
        return (float)($this->currentSentiment($forceRefresh)['score'] ?? 0.0);
    }

    // ── Analyse ───────────────────────────────────────────────────────────

    private function analyzeWithAi(array $headlines): array
    {
        if ($this->container === null) {
            return $this->keywordFallback($headlines);
        }

        try {
            /** @var \App\Core\Config $cfg */
            $cfg    = $this->container->get('config');
            $apiKey = EvolutionProviderKeys::anthropic($cfg);
            if ($apiKey === '') {
                return $this->keywordFallback($headlines);
            }

            $llm    = new LlmClient($this->container);
            $titles = array_column($headlines, 'title');
            $list   = implode("\n", array_map(fn($t, $i) => ($i + 1) . '. ' . $t, $titles, array_keys($titles)));

            $system = 'Je bent een financieel sentiment-analist gespecialiseerd in cryptocurrency en Ethereum. '
                    . 'Analyseer nieuwskoppen en geef uitsluitend een JSON-antwoord terug.';

            $prompt = "Analyseer de volgende " . count($titles) . " nieuwskoppen over ETH/Ethereum/Mining:\n\n"
                    . $list . "\n\n"
                    . "Geef je antwoord als JSON:\n"
                    . '{"score": <float van -1.0 tot +1.0>, "reasoning": "<één zin>"}'
                    . "\n\n-1.0 = extreem bearish, 0.0 = neutraal, +1.0 = extreem bullish.";

            $result  = $llm->callModel(self::MODEL, $system, $prompt);
            $content = trim((string)($result['content'] ?? ''));
            $score   = $this->parseScoreFromJson($content);

            return [
                'score'          => $score,
                'headline_count' => count($titles),
                'source'         => 'haiku',
                'cost_eur'       => round((float)($result['cost_eur'] ?? 0.0), 6),
                'ts'             => date('c'),
            ];
        } catch (\Throwable) {
            return $this->keywordFallback($headlines);
        }
    }

    private function keywordFallback(array $headlines): array
    {
        $titles = array_column($headlines, 'title');
        if (empty($titles)) {
            return $this->neutralResult('keyword_fallback', 0);
        }

        $bearish = 0;
        $bullish = 0;

        foreach ($titles as $title) {
            $lower = strtolower($title);
            foreach (self::BEARISH_WORDS as $w) {
                if (str_contains($lower, $w)) {
                    $bearish++;
                    break;
                }
            }
            foreach (self::BULLISH_WORDS as $w) {
                if (str_contains($lower, $w)) {
                    $bullish++;
                    break;
                }
            }
        }

        $total = $bearish + $bullish;
        $score = $total > 0 ? round(($bullish - $bearish) / $total, 2) : 0.0;

        return [
            'score'          => $score,
            'headline_count' => count($titles),
            'source'         => 'keyword_fallback',
            'cost_eur'       => 0.0,
            'ts'             => date('c'),
        ];
    }

    private function parseScoreFromJson(string $content): float
    {
        // Probeer JSON te parsen, eventueel met code-block strippen
        $clean = preg_replace('/^```[a-z]*\n?|\n?```$/m', '', $content);
        $data  = json_decode(trim((string)$clean), true);

        if (is_array($data) && isset($data['score'])) {
            $score = (float)$data['score'];
            return max(-1.0, min(1.0, $score));
        }

        // Fallback: zoek eerste getal in de tekst
        if (preg_match('/-?\d+(?:\.\d+)?/', $content, $m)) {
            $score = (float)$m[0];
            return max(-1.0, min(1.0, $score));
        }

        return 0.0;
    }

    private function neutralResult(string $source, int $count): array
    {
        return [
            'score'          => 0.0,
            'headline_count' => $count,
            'source'         => $source,
            'cost_eur'       => 0.0,
            'cached'         => false,
            'ts'             => date('c'),
        ];
    }

    // ── Cache ─────────────────────────────────────────────────────────────

    private function loadCache(): ?array
    {
        $file = $this->basePath . '/' . self::CACHE_FILE;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || (time() - strtotime((string)($data['ts'] ?? '1970-01-01'))) > self::CACHE_TTL) {
            return null;
        }
        return $data;
    }

    private function saveCache(array $result): void
    {
        $dir = dirname($this->basePath . '/' . self::CACHE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents(
            $this->basePath . '/' . self::CACHE_FILE,
            json_encode($result, JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
