<?php

declare(strict_types=1);

namespace App\Core\Evolution\LlmClient;

use App\Domain\AI\Contract\LlmCompletionClientInterface;

/**
 * Google Gemini via Generative Language API (REST, geen officiële PHP-SDK vereist).
 *
 * @see https://ai.google.dev/api/rest/v1beta/models/generateContent
 */
final class GeminiClient implements LlmCompletionClientInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * @return array{content:string,model:string,tokens_input:int,tokens_output:int,cost_eur:float,error?:string,provider:string}
     */
    public function complete(string $modelId, string $systemInstruction, string $userText, string $apiKey): array
    {
        $modelId = trim($modelId);
        if ($modelId === '') {
            $modelId = 'gemini-1.5-pro';
        }
        // Allow passing "gemini/gemini-1.5-pro" from LlmClient
        $modelId = preg_replace('#^gemini/#', '', $modelId) ?? $modelId;

        $url = self::API_BASE . '/' . rawurlencode($modelId) . ':generateContent?key=' . rawurlencode($apiKey);

        $body = [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction !== '' ? $systemInstruction : 'You are a helpful assistant.']],
            ],
            'contents'          => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userText]],
                ],
            ],
            'generationConfig'  => [
                'maxOutputTokens' => 2048,
                'temperature'     => 0.2,
            ],
        ];

        $raw  = $this->curlPostJson($url, $body);
        $data = json_decode($raw, true) ?: [];

        if (isset($data['error'])) {
            $msg = (string)($data['error']['message'] ?? json_encode($data['error']));
            return [
                'content'       => '',
                'model'         => $modelId,
                'tokens_input'  => 0,
                'tokens_output' => 0,
                'cost_eur'      => 0.0,
                'error'         => $msg,
                'provider'      => 'google_gemini',
            ];
        }

        $text = '';
        foreach ((array)($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (is_array($part) && isset($part['text'])) {
                $text .= (string)$part['text'];
            }
        }

        $in  = (int)($data['usageMetadata']['promptTokenCount'] ?? 0);
        $out = (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0);

        return [
            'content'       => $text,
            'model'         => $modelId,
            'tokens_input'  => $in,
            'tokens_output' => $out,
            'cost_eur'      => self::estimateCostEur($in, $out),
            'error'         => '',
            'provider'      => 'google_gemini',
        ];
    }

    /**
     * Zelfde completion maar met optionele Google Search grounding (modelafhankelijk).
     *
     * @param array<string, mixed> $extraBody merged in top-level JSON body
     * @return array{content:string,model:string,tokens_input:int,tokens_output:int,cost_eur:float,error?:string,provider:string}
     */
    public function completeWithTools(
        string $modelId,
        string $systemInstruction,
        string $userText,
        string $apiKey,
        array $extraBody = []
    ): array {
        $modelId = trim($modelId);
        if ($modelId === '') {
            $modelId = 'gemini-1.5-pro';
        }
        $modelId = preg_replace('#^gemini/#', '', $modelId) ?? $modelId;

        $url = self::API_BASE . '/' . rawurlencode($modelId) . ':generateContent?key=' . rawurlencode($apiKey);

        $body = array_merge([
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction !== '' ? $systemInstruction : 'You are a helpful assistant.']],
            ],
            'contents'          => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userText]],
                ],
            ],
            'generationConfig'  => [
                'maxOutputTokens' => 2048,
                'temperature'     => 0.2,
            ],
        ], $extraBody);

        $raw  = $this->curlPostJson($url, $body);
        $data = json_decode($raw, true) ?: [];

        if (isset($data['error'])) {
            $msg = (string)($data['error']['message'] ?? json_encode($data['error']));
            return [
                'content'       => '',
                'model'         => $modelId,
                'tokens_input'  => 0,
                'tokens_output' => 0,
                'cost_eur'      => 0.0,
                'error'         => $msg,
                'provider'      => 'google_gemini',
            ];
        }

        $text = '';
        foreach ((array)($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (is_array($part) && isset($part['text'])) {
                $text .= (string)$part['text'];
            }
        }

        $in  = (int)($data['usageMetadata']['promptTokenCount'] ?? 0);
        $out = (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0);

        return [
            'content'       => $text,
            'model'         => $modelId,
            'tokens_input'  => $in,
            'tokens_output' => $out,
            'cost_eur'      => self::estimateCostEur($in, $out),
            'error'         => '',
            'provider'      => 'google_gemini',
        ];
    }

    private function curlPostJson(string $url, array $body): string
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch      = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload !== false ? $payload : '{}',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: Framework-Evolution/1.0',
            ],
            CURLOPT_TIMEOUT        => 120,
        ]);
        $result = (string)curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private static function estimateCostEur(int $in, int $out): float
    {
        // Ruwe USD→EUR schatting (Gemini 1.5 Pro order-of-magnitude; pas aan via ledger)
        $usd = $in * 0.00000125 + $out * 0.000005;
        return round($usd * 0.93, 6);
    }
}
