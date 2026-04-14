<?php

declare(strict_types=1);

namespace App\Core\Evolution\Growth;

use App\Core\Config;
use App\Core\Evolution\ComplianceLogger;
use App\Core\Evolution\DeepSeekClient;
use App\Core\Evolution\EvolutionLogger;
use App\Core\Evolution\MarketSignalModel;
use App\Core\Evolution\PiiScanner;
use PDO;

/**
 * Receives raw market data, uses deepseek-chat to score buying intent,
 * and persists only high-intent signals (score > INTENT_THRESHOLD) to market_signals.
 *
 * Usage:
 *   $svc = new SignalDiscoveryService($config, $db);
 *   $result = $svc->processRawSignal([
 *       'source'      => 'reddit',
 *       'niche'       => 'elektronica',
 *       'raw_content' => 'WTB iPhone 15 Pro, budget €900',
 *       'external_id' => 'reddit_t3_abc123',   // optional
 *       'metadata'    => ['url' => '...'],      // optional
 *   ]);
 */
final class SignalDiscoveryService
{
    public const INTENT_THRESHOLD = 0.7;
    private const SCORE_TOKENS    = 256;
    private const SCORE_MODEL     = DeepSeekClient::MODEL_CHAT;

    public function __construct(
        private readonly Config $config,
        private readonly PDO    $db
    ) {
    }

    /**
     * Process a single raw signal payload.
     * Returns ok=true with saved=true/false and the intent_score.
     *
     * @param array{source?: string, niche?: string, raw_content: string, external_id?: string, metadata?: array<string, mixed>} $input
     * @return array{ok: bool, saved?: bool, intent_score?: float, intent_type?: string, signal_id?: int, error?: string}
     */
    public function processRawSignal(array $input): array
    {
        $apiKey = trim((string)$this->config->get('ai.deepseek.api_key', ''));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'DEEPSEEK_API_KEY not configured (ai.deepseek.api_key).'];
        }

        $rawContent = trim((string)($input['raw_content'] ?? ''));
        if ($rawContent === '') {
            return ['ok' => false, 'error' => 'raw_content is required.'];
        }

        // Context-Compressor: strip HTML noise before sending to AI (~60-70% token reduction)
        $rawContent = self::strip_noise($rawContent);

        $source     = mb_substr(trim((string)($input['source'] ?? 'unknown')), 0, 64);
        $niche      = mb_substr(trim((string)($input['niche'] ?? '')), 0, 128);
        $externalId = isset($input['external_id']) ? mb_substr(trim((string)$input['external_id']), 0, 255) : null;
        $metadata   = isset($input['metadata']) && is_array($input['metadata']) ? $input['metadata'] : null;

        // Score intent via deepseek-chat
        $scored = $this->scoreIntent($apiKey, $source, $niche, $rawContent);
        if (!$scored['ok']) {
            return $scored;
        }

        $intentScore = $scored['intent_score'];
        $intentType  = $scored['intent_type'] ?? 'unknown';

        // Update niche from AI if not provided or blank
        if ($niche === '' && isset($scored['niche']) && $scored['niche'] !== '') {
            $niche = mb_substr((string)$scored['niche'], 0, 128);
        }

        if ($intentScore < self::INTENT_THRESHOLD) {
            EvolutionLogger::log('growth', 'signal_discarded', [
                'source'  => $source,
                'score'   => $intentScore,
                'niche'   => $niche,
                'hint'    => mb_substr($rawContent, 0, 80),
            ]);

            return [
                'ok'           => true,
                'saved'        => false,
                'intent_score' => $intentScore,
                'intent_type'  => $intentType,
            ];
        }

        // ── GDPR/AVG Art. 5(1)(c): PII data-minimisation guardrail ──────────────
        $piiResult = PiiScanner::scan($rawContent);
        if ($piiResult['pii_detected']) {
            $rawContent = $piiResult['clean'];          // store anonymised version
            ComplianceLogger::log(
                $this->db,
                'SignalDiscoveryService',
                ComplianceLogger::ACTION_PII_ANONYMIZED,
                'PII detected and redacted from raw_content before storage (source: ' . $source . ')',
                $piiResult['found']
            );
        }

        $meta = $metadata ?? [];
        $meta['intent_type']  = $intentType;
        $meta['external_id']  = $externalId;
        $meta['pii_redacted'] = $piiResult['pii_detected'];

        $model = new MarketSignalModel($this->db, $this->config);
        $id = $model->insert($source, $niche, $rawContent, $intentScore, $meta);

        EvolutionLogger::log('growth', 'signal_saved', [
            'id'     => $id,
            'source' => $source,
            'niche'  => $niche,
            'score'  => $intentScore,
        ]);

        return [
            'ok'           => true,
            'saved'        => true,
            'intent_score' => $intentScore,
            'intent_type'  => $intentType,
            'signal_id'    => $id,
        ];
    }

    /**
     * Batch-process multiple raw signals. Returns a summary.
     *
     * @param list<array{source?: string, niche?: string, raw_content: string, external_id?: string, metadata?: array<string, mixed>}> $inputs
     * @return array{ok: bool, processed: int, saved: int, discarded: int, errors: int}
     */
    public function processBatch(array $inputs): array
    {
        $stats = ['ok' => true, 'processed' => 0, 'saved' => 0, 'discarded' => 0, 'errors' => 0];
        foreach ($inputs as $input) {
            $r = $this->processRawSignal($input);
            $stats['processed']++;
            if (!$r['ok']) {
                $stats['errors']++;
            } elseif ($r['saved'] ?? false) {
                $stats['saved']++;
            } else {
                $stats['discarded']++;
            }
        }

        return $stats;
    }

    // ─── Context-Compressor ──────────────────────────────────────────────────────

    /**
     * Strip HTML/JS/CSS noise from raw web content before sending to AI.
     * Reduces token usage by ~60-70% for HTML pages scraped from the web.
     *
     * Steps:
     *  1. Remove <script> and <style> blocks entirely
     *  2. Strip remaining HTML tags
     *  3. Decode HTML entities
     *  4. Collapse whitespace (tabs, newlines, multiple spaces → single space)
     *  5. Hard-cap at 3000 chars (≈750 tokens) — enough context, never a budget bomb
     */
    public static function strip_noise(string $raw, int $maxChars = 3000): string
    {
        // Remove <script>...</script> blocks
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $raw) ?? $raw;
        // Remove <style>...</style> blocks
        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $clean) ?? $clean;
        // Remove HTML comments
        $clean = preg_replace('/<!--.*?-->/s', ' ', $clean) ?? $clean;
        // Strip remaining tags
        $clean = strip_tags($clean);
        // Decode common HTML entities (&amp; &lt; &gt; &nbsp; etc.)
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse whitespace
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        return mb_substr($clean, 0, $maxChars);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /**
     * @return array{ok: bool, intent_score?: float, intent_type?: string, niche?: string, error?: string}
     */
    private function scoreIntent(string $apiKey, string $source, string $niche, string $rawContent): array
    {
        $system = <<<'SYSTEM'
You are a market intent analyzer for a European secondhand marketplace.
Given a content snippet, rate the buying intent on a scale of 0.0 to 1.0.

Scoring guide:
  1.0 = Explicit "I want to buy X for Y budget" with clear intent
  0.8 = Strong buying signal ("looking for", "WTB", "zoek", "gezocht")
  0.6 = Moderate interest, browsing behavior
  0.3 = Selling intent or irrelevant discussion
  0.0 = Spam, unrelated, or noise

Respond with ONLY a JSON object (no markdown, no explanation):
{"intent_score": 0.0-1.0, "intent_type": "buy|sell|browse|unknown", "niche": "category_slug_or_empty"}
SYSTEM;

        $niicheHint = $niche !== '' ? "\nNiche context: {$niche}" : '';
        $user = "Source: {$source}{$niicheHint}\nContent: " . mb_substr($rawContent, 0, 1000);

        $client = new DeepSeekClient($apiKey);
        $raw = $client->complete(
            $system,
            [['role' => 'user', 'content' => $user]],
            self::SCORE_MODEL,
            self::SCORE_TOKENS,
            true
        );

        if ($raw === '') {
            return ['ok' => false, 'error' => 'DeepSeek scoring returned empty (status: ' . $client->getLastHttpStatus() . ').'];
        }

        $j = $this->decodeJson($raw);
        if ($j === null || !isset($j['intent_score'])) {
            return ['ok' => false, 'error' => 'Invalid scoring JSON: ' . mb_substr($raw, 0, 200)];
        }

        return [
            'ok'           => true,
            'intent_score' => (float)max(0.0, min(1.0, $j['intent_score'])),
            'intent_type'  => (string)($j['intent_type'] ?? 'unknown'),
            'niche'        => (string)($j['niche'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $t = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $t, $m)) {
            $t = trim($m[1]);
        }
        $d = json_decode($t, true);

        return is_array($d) ? $d : null;
    }
}
