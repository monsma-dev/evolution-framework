<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use OpenAI;
use Throwable;

/**
 * Multi-agent pipeline: Claude design → GPT-4o critique → GPT-4o-mini execution (Architect JSON).
 * Reduces blind spots before code generation.
 */
final class SupremeSynthesisService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, raw_json?: array, reply_text?: string, error?: string, phases?: array<string, mixed>}
     */
    public function run(string $userTask, string $mode = 'core'): array
    {
        $config = $this->container->get('config');
        $ss = $config->get('evolution.supreme_synthesis', []);
        if (!is_array($ss) || !filter_var($ss['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'supreme_synthesis disabled in evolution.json'];
        }

        $designModel = (string) ($ss['design_model'] ?? 'claude-3-5-sonnet-20241022');
        $critiqueModel = (string) ($ss['critique_model'] ?? 'gpt-4o');
        $executeModel = (string) ($ss['execute_model'] ?? 'gpt-4o-mini');
        $strict = filter_var($ss['require_critique_approval'] ?? true, FILTER_VALIDATE_BOOL);

        $kg = (new EvolutionKnowledgeBaseService($this->container))->promptSection((int) ($ss['knowledge_graph_max_chars'] ?? 8000));

        $anthropicKey = EvolutionProviderKeys::anthropic($config);
        if ($anthropicKey === '') {
            return ['ok' => false, 'error' => 'Missing anthropic api_key (evolution / ai / env)'];
        }
        $openaiKey = EvolutionProviderKeys::openAi($config, true);
        if ($openaiKey === '') {
            return ['ok' => false, 'error' => 'Missing openai api_key (ai / evolution / assistant / env)'];
        }

        $designSystem = <<<'SYS'
You are the principal software architect. Output a single JSON object (no markdown):
{"technical_design":"markdown string","architecture_notes":[],"risks":[],"suggested_files":[]}
SYS;

        $designUser = "TASK:\n" . $userTask . "\n\n" . $kg;
        $claude = new AnthropicMessagesClient($anthropicKey);
        try {
            $designJson = $claude->complete($designSystem, [['role' => 'user', 'content' => $designUser]], $designModel, 4096);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Design phase: ' . $e->getMessage()];
        }

        $mm = $config->get('evolution.master_mentor', []);
        $masterOn = is_array($mm) && filter_var($mm['enabled'] ?? false, FILTER_VALIDATE_BOOL);

        if ($masterOn) {
            $masterSvc = new EvolutionMasterOpinionService($this->container);
            $mCrit = $masterSvc->critiqueDesignJson($designJson, $config);
            if (!($mCrit['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => 'Critique phase (Grandmaster): ' . ($mCrit['error'] ?? 'failed'),
                    'phases' => ['design' => $designJson],
                ];
            }
            $critText = (string) ($mCrit['raw'] ?? '');
            $approved = (bool) ($mCrit['approved'] ?? false);
        } else {
            $critiqueSystem = <<<'SYS'
You are a hostile senior security reviewer. Given the architect's JSON design, find edge cases, security issues, and data-integrity risks.
Output a single JSON object:
{"approved":true|false,"blockers":[],"security_notes":[],"required_changes":""}
If there are unresolved HIGH severity issues, set approved to false.
SYS;

            $client = OpenAI::factory()->withApiKey($openaiKey)->make();
            try {
                $crit = $client->chat()->create([
                    'model' => $critiqueModel,
                    'messages' => [
                        ['role' => 'system', 'content' => $critiqueSystem],
                        ['role' => 'user', 'content' => "DESIGN_JSON:\n" . $designJson],
                    ],
                    'max_tokens' => 2048,
                    'response_format' => ['type' => 'json_object'],
                ]);
                $critText = trim((string) ($crit->choices[0]->message->content ?? ''));
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'Critique phase: ' . $e->getMessage(), 'phases' => ['design' => $designJson]];
            }

            $critDecoded = json_decode($critText, true);
            $approved = is_array($critDecoded) && filter_var($critDecoded['approved'] ?? false, FILTER_VALIDATE_BOOL);
        }

        if (!$approved && $strict) {
            return [
                'ok' => false,
                'error' => 'Critique did not approve design. Revise task or disable require_critique_approval.',
                'phases' => [
                    'design' => $designJson,
                    'critique' => $critText,
                ],
            ];
        }

        $arch = new ArchitectChatService($this->container);
        $execHint = "SUPREME_SYNTHESIS_CONTEXT:\nDESIGN:\n" . $designJson . "\nCRITIQUE:\n" . $critText
            . "\n\nExecute the original task using the normal Architect JSON schema. Address critique notes in your implementation.\n"
            . "Execution model hint: configure evolution.architect.tier1_chat_model = " . $executeModel . " for cheapest codegen.\nORIGINAL_TASK:\n" . $userTask;

        $messages = [['role' => 'user', 'content' => $execHint]];

        $result = $arch->complete($messages, $mode, false, 30, null, false, '', null, 'premium');

        return array_merge($result, [
            'phases' => [
                'design' => $designJson,
                'critique' => $critText,
            ],
        ]);
    }
}
