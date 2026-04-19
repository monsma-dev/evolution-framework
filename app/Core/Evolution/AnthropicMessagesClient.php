<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use Throwable;

/**
 * Anthropic Messages API — string or block system (prompt caching for static framework text).
 */
final class AnthropicMessagesClient
{
    /**
     * @param array<string, string> $extraHeaders e.g. anthropic-beta for prompt caching
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly array $extraHeaders = []
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages OpenAI-style user/assistant turns (no system).
     */
    public function complete(string $system, array $messages, string $model, int $maxTokens): string
    {
        $body = [
            'model' => $model,
            'max_tokens' => max(256, min(8192, $maxTokens)),
            'system' => $system,
            'messages' => $this->mapMessages($messages),
        ];

        return $this->postAndExtractText($body);
    }

    /**
     * System prompt as content blocks (use cache_control on stable prefix per Anthropic prompt caching).
     *
     * @param list<array<string, mixed>> $systemBlocks
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function completeWithSystemBlocks(
        array $systemBlocks,
        array $messages,
        string $model,
        int $maxTokens
    ): string {
        if ($systemBlocks === []) {
            return '';
        }
        $body = [
            'model' => $model,
            'max_tokens' => max(256, min(8192, $maxTokens)),
            'system' => $systemBlocks,
            'messages' => $this->mapMessages($messages),
        ];

        return $this->postAndExtractText($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postAndExtractText(array $body): string
    {
        try {
            $headers = array_merge([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ], $this->extraHeaders);
            $res = EvolutionJsonHttp::post('https://api.anthropic.com/v1/messages', $headers, $body, 180);
            $raw = $res['body'];
            $j = json_decode($raw, true);
            if (!is_array($j)) {
                return '';
            }
            $blocks = $j['content'] ?? [];
            if (!is_array($blocks)) {
                return '';
            }
            $text = '';
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $text .= (string)($block['text'] ?? '');
                }
            }

            return trim($text);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    private function mapMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = strtolower(trim((string)($m['role'] ?? '')));
            $content = trim((string)($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'system') {
                continue;
            }
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }

        if ($out === []) {
            $out[] = ['role' => 'user', 'content' => 'Continue.'];
        }

        return $out;
    }
}
