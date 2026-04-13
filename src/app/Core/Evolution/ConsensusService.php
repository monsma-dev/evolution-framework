<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;
use OpenAI;
use Throwable;

/**
 * Multi-model review: primary (OpenAI) + reviewer (Anthropic Claude or fallback OpenAI).
 */
final class ConsensusService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{
     *   ok: bool,
     *   agree?: bool,
     *   primary_model?: string,
     *   review_model?: string,
     *   primary_note?: string,
     *   review_note?: string,
     *   warning?: string,
     *   error?: string
     * }
     */
    public function reviewPhpPatch(string $phpCode, string $intent): array
    {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        $cs = is_array($evo) ? ($evo['consensus'] ?? []) : [];
        if (is_array($cs) && !filter_var($cs['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'agree' => true, 'primary_note' => 'Consensus disabled', 'review_note' => ''];
        }

        $primaryModel = (string)($cs['php_primary_model'] ?? 'gpt-4o');
        $reviewProvider = strtolower((string)($cs['php_review_provider'] ?? 'anthropic'));
        $reviewModel = (string)($cs['php_review_model'] ?? 'claude-3-5-sonnet-20241022');
        $warn = filter_var($cs['warn_on_divergence'] ?? true, FILTER_VALIDATE_BOOL);

        $apiKey = trim((string)$config->get('ai.openai.api_key', $config->get('assistant.api_key', '')));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Missing OpenAI API key'];
        }

        $prompt = CostGuard::messagePrefix($config)
            . "Intent: {$intent}\n\nReview this PHP (App namespace, strict_types). Reply in one short paragraph: APPROVE or REJECT and why. Reject if the change would needlessly increase AWS/cloud cost (extra S3 scans, unbounded polling, per-row paid API calls, etc.).\n\n```php\n{$phpCode}\n```";

        try {
            $client = OpenAI::factory()->withApiKey($apiKey)->make();
            $p1 = $client->chat()->create([
                'model' => $primaryModel,
                'max_tokens' => 800,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
            $primaryNote = trim((string)($p1->choices[0]->message->content ?? ''));

            $reviewNote = '';
            if ($reviewProvider === 'anthropic') {
                $reviewNote = $this->anthropicReview($config, $reviewModel, $prompt) ?? '';
            }
            if ($reviewNote === '') {
                $fallback = (string)($cs['php_review_fallback_model'] ?? 'gpt-4o-mini');
                $p2 = $client->chat()->create([
                    'model' => $fallback,
                    'max_tokens' => 800,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a strict code reviewer. Be concise. Apply Cost-Guard: reject changes that would waste AWS or vendor spend.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);
                $reviewNote = trim((string)($p2->choices[0]->message->content ?? ''));
                $reviewModel = $fallback . ' (fallback)';
            }

            $agree = $this->bothApprove($primaryNote, $reviewNote);
            $warning = null;
            if (!$agree && $warn) {
                $warning = 'Models disagree — review both notes before applying.';
            }

            EvolutionLogger::log('consensus', 'php_review', ['agree' => $agree]);

            return [
                'ok' => true,
                'agree' => $agree,
                'primary_model' => $primaryModel,
                'review_model' => $reviewModel,
                'primary_note' => $primaryNote,
                'review_note' => $reviewNote,
                'warning' => $warning,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, answer?: string, error?: string}
     */
    public function architectureAsk(string $question): array
    {
        $config = $this->container->get('config');
        $evo = $config->get('evolution', []);
        $cs = is_array($evo) ? ($evo['consensus'] ?? []) : [];
        $model = (string)($cs['architecture_model'] ?? 'o1');
        $apiKey = trim((string)$config->get('ai.openai.api_key', $config->get('assistant.api_key', '')));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Missing OpenAI API key'];
        }

        try {
            $client = OpenAI::factory()->withApiKey($apiKey)->make();
            $archPrompt = CostGuard::messagePrefix($config)
                . "Architecture question (PHP 8.3 MVC, models own SQL). Prefer cost-aware, minimal AWS surface:\n\n{$question}";
            $r = $client->chat()->create([
                'model' => $model,
                'max_tokens' => 2500,
                'messages' => [
                    ['role' => 'user', 'content' => $archPrompt],
                ],
            ]);
            $text = trim((string)($r->choices[0]->message->content ?? ''));

            return ['ok' => true, 'answer' => $text];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Independent GPT-4o + Claude reviews of the same patch (memory / performance focus).
     *
     * @return array{
     *   ok: bool,
     *   agree?: bool,
     *   gpt_note?: string,
     *   claude_note?: string,
     *   warning?: string,
     *   error?: string
     * }
     */
    public function patchSecondOpinion(string $phpCode, string $intent): array
    {
        $config = $this->container->get('config');
        $apiKey = trim((string)$config->get('ai.openai.api_key', $config->get('assistant.api_key', '')));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Missing OpenAI API key'];
        }

        $body = CostGuard::messagePrefix($config)
            . "Intent: {$intent}\n\n"
            . "Review this PHP shadow patch for memory efficiency, allocation patterns, and algorithmic complexity (App namespace, strict_types).\n"
            . "Reply in one short paragraph. End with APPROVE or REJECT. Mention CONCERN if memory or CPU could regress.\n\n```php\n{$phpCode}\n```";

        $gptNote = '';
        $claudeNote = '';

        try {
            $client = OpenAI::factory()->withApiKey($apiKey)->make();
            $g = $client->chat()->create([
                'model' => 'gpt-4o',
                'max_tokens' => 700,
                'messages' => [
                    ['role' => 'user', 'content' => $body],
                ],
            ]);
            $gptNote = trim((string)($g->choices[0]->message->content ?? ''));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'OpenAI: ' . $e->getMessage()];
        }

        $evoCfg = $config->get('evolution', []);
        $cons = is_array($evoCfg) ? ($evoCfg['consensus'] ?? []) : [];
        $reviewModel = is_array($cons) ? (string)($cons['php_review_model'] ?? 'claude-3-5-sonnet-20241022') : 'claude-3-5-sonnet-20241022';

        $claudeNote = $this->anthropicReview($config, $reviewModel, $body) ?? '';
        if ($claudeNote === '') {
            $evo = $config->get('evolution', []);
            $fb = is_array($evo) ? (string)(($evo['consensus'] ?? [])['php_review_fallback_model'] ?? 'gpt-4o-mini') : 'gpt-4o-mini';
            try {
                $client = OpenAI::factory()->withApiKey($apiKey)->make();
                $c2 = $client->chat()->create([
                    'model' => $fb,
                    'max_tokens' => 700,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Second reviewer. Focus on memory and CPU. End with APPROVE or REJECT.'],
                        ['role' => 'user', 'content' => $body],
                    ],
                ]);
                $claudeNote = trim((string)($c2->choices[0]->message->content ?? '')) . ' (fallback model)';
            } catch (Throwable $e) {
                $claudeNote = '(Anthropic unavailable: ' . $e->getMessage() . ')';
            }
        }

        $agree = $this->bothApprove($gptNote, $claudeNote);
        $warning = null;
        if (!$agree) {
            $warning = 'Models disagree — compare notes (e.g. memory efficiency or safety).';
        }

        EvolutionLogger::log('consensus', 'patch_second_opinion', ['agree' => $agree]);

        return [
            'ok' => true,
            'agree' => $agree,
            'gpt_note' => $gptNote,
            'claude_note' => $claudeNote,
            'warning' => $warning,
        ];
    }

    private function bothApprove(string $a, string $b): bool
    {
        $la = strtolower($a);
        $lb = strtolower($b);
        if (str_contains($la, 'reject') || str_contains($lb, 'reject')) {
            return false;
        }

        return str_contains($la, 'approve') && str_contains($lb, 'approve');
    }

    private function anthropicReview(Config $config, string $model, string $prompt): ?string
    {
        $evo = $config->get('evolution', []);
        $anth = is_array($evo) ? ($evo['anthropic'] ?? []) : [];
        $key = trim((string)($anth['api_key'] ?? ''));
        if ($key === '') {
            return null;
        }

        $extra = [];
        $beta = trim((string)($anth['prompt_caching_beta'] ?? ''));
        if ($beta !== '') {
            $extra['anthropic-beta'] = $beta;
        }

        $fw = EvolutionFrameworkContext::load($config, $this->container);
        $base = "You are a concise PHP code reviewer (App\\\\ namespace, strict_types). "
            . "Apply Cost-Guard: reject needless AWS/vendor spend. One short paragraph; end with APPROVE or REJECT.\n";

        $blocks = [];
        if ($fw !== '') {
            $blocks[] = [
                'type' => 'text',
                'text' => $base . "\n--- Framework context (cached prefix) ---\n" . $fw,
                'cache_control' => ['type' => 'ephemeral'],
            ];
        } else {
            $blocks[] = ['type' => 'text', 'text' => $base];
        }

        $client = new AnthropicMessagesClient($key, $extra);
        $text = $client->completeWithSystemBlocks(
            $blocks,
            [['role' => 'user', 'content' => $prompt]],
            $model,
            1024
        );

        return $text !== '' ? $text : null;
    }
}
