<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;
use App\Core\Evolution\SystemSettingsService;
use GuzzleHttp\Client;
use OpenAI;
use Throwable;

/**
 * PR / media outreach orchestration: draft (polyglot via config), personalization hook (web search),
 * optional Postmark/SendGrid send, budget cap, A/B subject variants metadata.
 *
 * Stays OFF until evolution.outreach.enabled is true.
 */
final class EvolutionOutreachEngine
{
    public const HOF_FLAG = 'storage/evolution/.outreach_first_run_hof_done';

    public const SPEND_LOG = 'storage/evolution/outreach/spend_log.jsonl';

    public function __construct(private readonly Container $container)
    {
    }

    public function isEnabled(): bool
    {
        try {
            $db = $this->container->get('db');
            if (!SystemSettingsService::isLive($db)) {
                return false;
            }
            if (!SystemSettingsService::get($db, SystemSettingsService::KEY_OUTREACH_ENABLED, false)) {
                return false;
            }
        } catch (\Throwable) {
        }

        $o = $this->container->get('config')->get('evolution.outreach', []);

        return is_array($o) && filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array{ok: bool, error?: string, base_message?: string, locales?: array<string, string>, meta?: array<string, mixed>}
     */
    public function draftPressReleaseBundle(): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'outreach disabled'];
        }

        $cfg = $this->container->get('config');
        $o = $cfg->get('evolution.outreach', []);
        if (!is_array($o)) {
            return ['ok' => false, 'error' => 'invalid outreach config'];
        }

        if (!$this->checkBudgetGate(0.0)) {
            return ['ok' => false, 'error' => 'budget_cap_reached'];
        }

        $key = EvolutionProviderKeys::openAi($cfg, true);
        if ($key === '') {
            return ['ok' => false, 'error' => 'missing OpenAI API key'];
        }

        $topic = trim((string) ($o['press_topic'] ?? 'Level 8 Sovereign autonomous PHP framework: Evolution, native bridge roadmap, AI visibility.'));
        $system = 'You write concise B2B press copy. Output JSON only: {"subject":"...","body_plain":"...","body_html":"..."} — factual, no fake quotes.';
        $user = "Draft a short press note (EN) for EU tech/business journalists about: {$topic}. Mention Evolution as an internal codename for the framework; include AI-disclosure friendly closing line.";

        try {
            $client = OpenAI::factory()->withApiKey($key)->make();
            $resp = $client->chat()->create([
                'model' => (string) ($o['draft_model'] ?? 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'max_tokens' => 1200,
            ]);
            $raw = $resp->choices[0]->message->content ?? '';
            $j = json_decode((string) $raw, true);
            if (!is_array($j)) {
                return ['ok' => false, 'error' => 'draft_not_json'];
            }
            $base = (string) ($j['body_plain'] ?? $j['body_html'] ?? '');
            $this->logSpendEstimate((float) ($o['estimated_draft_eur'] ?? 0.02));

            $locales = is_array($o['polyglot_locales'] ?? null) ? array_map('strval', $o['polyglot_locales']) : [];
            $translated = [];
            foreach (array_slice($locales, 0, 15) as $loc) {
                $translated[$loc] = '[translation pipeline: wire TranslationEvolutionService or DeepL] ' . mb_substr($base, 0, 400);
            }

            EvolutionLogger::log('outreach', 'draft_press_release', ['ok' => true]);

            return [
                'ok' => true,
                'base_message' => $base,
                'locales' => $translated,
                'meta' => [
                    'subject' => (string) ($j['subject'] ?? ''),
                    'supreme_synthesis' => 'Use evolution:supreme-synthesis for full multi-agent pass when supreme_synthesis.enabled',
                ],
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Personalization: use web_search snippets to reference a recent headline (Tavily when configured).
     *
     * @return array{ok: bool, intro?: string, error?: string}
     */
    public function buildPersonalizedIntro(string $journalistName, string $outletHint = ''): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'outreach disabled'];
        }

        $q = trim($journalistName . ' ' . $outletHint . ' latest article headline');
        $block = WebSearchAdapter::buildContextBlock($this->container->get('config'), $q);
        $snippet = $block['ok'] ? ($block['block'] ?? '') : '';
        if ($snippet === '') {
            return ['ok' => true, 'intro' => 'We follow your coverage; we would like to share a short technical update relevant to your readers.'];
        }

        $intro = 'I read your recent piece (see search context below) and thought our update on the Evolution framework might align with your interest in AI-assisted infrastructure.' . "\n\n" . mb_substr($snippet, 0, 1200);

        return ['ok' => true, 'intro' => $intro];
    }

    /**
     * @param list<string> $variants
     *
     * @return array{ok: bool, picked?: string, variants?: list<string>}
     */
    public function registerSubjectLineAb(array $variants): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false];
        }

        $cfg = $this->container->get('config');
        $o = $cfg->get('evolution.outreach', []);
        $max = is_array($o) ? (int) ($o['subject_ab_variants_max'] ?? 50) : 50;
        $max = max(1, min(200, $max));
        $variants = array_values(array_filter(array_map('trim', $variants)));
        $variants = array_slice($variants, 0, $max);

        $path = BASE_PATH . '/data/evolution/outreach/subject_ab_variants.json';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($path, json_encode(['ts' => gmdate('c'), 'variants' => $variants], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['ok' => true, 'variants' => $variants, 'picked' => $variants[0] ?? ''];
    }

    /**
     * @param array{to: string, subject: string, html: string, text: string, unsubscribe_url: string} $mail
     *
     * @return array{ok: bool, error?: string, provider_response?: mixed}
     */
    public function sendTransactional(array $mail): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'outreach disabled'];
        }

        $cfg = $this->container->get('config');
        $v = EvolutionOutreachComplianceService::validateOutboundMessage($cfg, [
            'html' => $mail['html'] ?? '',
            'text' => $mail['text'] ?? '',
            'unsubscribe_url' => $mail['unsubscribe_url'] ?? '',
        ]);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => 'compliance: ' . implode(',', $v['violations'])];
        }

        $o = $cfg->get('evolution.outreach', []);
        $provider = strtolower(trim((string) (is_array($o) ? ($o['send_provider'] ?? 'postmark') : 'postmark')));
        $dry = filter_var(is_array($o) ? ($o['dry_run_only'] ?? true) : true, FILTER_VALIDATE_BOOL);
        if ($dry) {
            EvolutionLogger::log('outreach', 'send_skipped_dry_run', ['to' => $mail['to'] ?? '']);

            return ['ok' => true, 'provider_response' => ['dry_run' => true]];
        }

        if ($provider === 'postmark') {
            return $this->sendPostmark($cfg, $mail);
        }
        if ($provider === 'sendgrid') {
            return $this->sendSendGrid($cfg, $mail);
        }

        return ['ok' => false, 'error' => 'unknown send_provider'];
    }

    public function recordFirstCampaignMilestoneIfNeeded(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $cfg = $this->container->get('config');
        $o = $cfg->get('evolution.outreach', []);
        if (!is_array($o) || !filter_var($o['record_hall_of_fame'] ?? true, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $flag = BASE_PATH . '/' . self::HOF_FLAG;
        if (is_file($flag)) {
            return false;
        }
        @file_put_contents($flag, gmdate('c') . "\n");
        (new EvolutionHallOfFameService($this->container))->recordMilestone(
            'First Autonomous European Media Outreach initiated.',
            'outreach',
            ['outreach' => true, 'eu' => true]
        );

        return true;
    }

    /**
     * Rough budget gate: cumulative logged EUR vs max_test_round_eur.
     */
    public function checkBudgetGate(float $incrementEur): bool
    {
        $cfg = $this->container->get('config');
        $o = $cfg->get('evolution.outreach', []);
        if (!is_array($o) || !filter_var($o['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $cap = (float) ($o['max_test_round_eur'] ?? 50.0);
        $spent = $this->sumSpendLog();
        if ($spent + $incrementEur > $cap + 0.0001) {
            EvolutionLogger::log('outreach', 'budget_block', ['spent' => $spent, 'cap' => $cap]);

            return false;
        }

        return true;
    }

    private function logSpendEstimate(float $eur): void
    {
        $path = BASE_PATH . '/' . self::SPEND_LOG;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = json_encode(['ts' => gmdate('c'), 'eur' => $eur, 'kind' => 'estimate'], JSON_UNESCAPED_UNICODE);
        if (is_string($line)) {
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function sumSpendLog(): float
    {
        $path = BASE_PATH . '/' . self::SPEND_LOG;
        if (!is_file($path)) {
            return 0.0;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return 0.0;
        }
        $sum = 0.0;
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $j = json_decode($line, true);
            if (is_array($j)) {
                $sum += (float) ($j['eur'] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * @param array{to: string, subject: string, html: string, text: string, unsubscribe_url: string} $mail
     *
     * @return array{ok: bool, error?: string, provider_response?: mixed}
     */
    private function sendPostmark(Config $cfg, array $mail): array
    {
        $token = trim((string) getenv('POSTMARK_SERVER_TOKEN'));
        if ($token === '') {
            $token = trim((string) $cfg->get('evolution.outreach.postmark_server_token', ''));
        }
        if ($token === '') {
            return ['ok' => false, 'error' => 'missing POSTMARK_SERVER_TOKEN'];
        }

        $from = trim((string) $cfg->get('evolution.outreach.from_email', 'press@example.com'));

        try {
            $http = new Client(['timeout' => 25]);
            $r = $http->post('https://api.postmarkapp.com/email', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Postmark-Server-Token' => $token,
                ],
                'json' => [
                    'From' => $from,
                    'To' => $mail['to'],
                    'Subject' => $mail['subject'],
                    'HtmlBody' => $mail['html'],
                    'TextBody' => $mail['text'],
                    'MessageStream' => 'outbound',
                ],
            ]);
            $this->logSpendEstimate((float) ($cfg->get('evolution.outreach.estimated_send_eur', 0.01)));

            return ['ok' => true, 'provider_response' => ['status' => $r->getStatusCode()]];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array{to: string, subject: string, html: string, text: string, unsubscribe_url: string} $mail
     *
     * @return array{ok: bool, error?: string, provider_response?: mixed}
     */
    private function sendSendGrid(Config $cfg, array $mail): array
    {
        $token = trim((string) getenv('SENDGRID_API_KEY'));
        if ($token === '') {
            $token = trim((string) $cfg->get('evolution.outreach.sendgrid_api_key', ''));
        }
        if ($token === '') {
            return ['ok' => false, 'error' => 'missing SENDGRID_API_KEY'];
        }

        $from = trim((string) $cfg->get('evolution.outreach.from_email', 'press@example.com'));

        try {
            $http = new Client(['timeout' => 25]);
            $r = $http->post('https://api.sendgrid.com/v3/mail/send', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'personalizations' => [['to' => [['email' => $mail['to']]]]],
                    'from' => ['email' => $from],
                    'subject' => $mail['subject'],
                    'content' => [
                        ['type' => 'text/plain', 'value' => $mail['text']],
                        ['type' => 'text/html', 'value' => $mail['html']],
                    ],
                ],
            ]);
            $this->logSpendEstimate((float) ($cfg->get('evolution.outreach.estimated_send_eur', 0.01)));

            return ['ok' => true, 'provider_response' => ['status' => $r->getStatusCode()]];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
