<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * DeepSeek API client (OpenAI-compatible endpoint).
 *
 * Supports:
 *   - deepseek-chat  (V3) — fast general purpose, JSON mode supported
 *   - deepseek-reasoner (R1) — chain-of-thought reasoning, no JSON mode, strips <think> blocks
 *
 * API base: https://api.deepseek.com/v1
 * Auth:     Bearer <DEEPSEEK_API_KEY>
 */
final class DeepSeekClient
{
    public const API_BASE        = 'https://api.deepseek.com/v1';
    public const MODEL_CHAT      = 'deepseek-chat';
    public const MODEL_REASONER  = 'deepseek-reasoner';

    private const TIMEOUT = 180;
    private const STRATEGY_LOG = 'storage/logs/evolution_strategy.log';

    private int    $lastHttpStatus    = 0;
    private string $reasoningLogFile  = self::STRATEGY_LOG;

    public function __construct(private readonly string $apiKey)
    {
    }

    public function getLastHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    /**
     * Override the file that <think> traces are written to.
     * Path is relative to BASE_PATH (e.g. 'storage/logs/evolution_reasoning.log').
     */
    public function setReasoningLogFile(string $relPath): static
    {
        $this->reasoningLogFile = $relPath;

        return $this;
    }

    public static function isKilled(): bool
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return file_exists($base . '/data/app/KILLED');
    }

    /**
     * Chat completion — returns trimmed assistant text.
     *
     * @param array<int, array{role: string, content: string}> $messages OpenAI-style user/assistant turns (no system).
     * @param bool $jsonMode  Request JSON output via response_format (ignored for R1).
     */
    public function complete(
        string $system,
        array $messages,
        string $model,
        int $maxTokens,
        bool $jsonMode = true
    ): string {
        if (self::isKilled()) {
            $this->lastHttpStatus = 503;
            return '';
        }

        $isReasoner = (strtolower(trim($model)) === self::MODEL_REASONER);

        $body = [
            'model'      => $model,
            'max_tokens' => max(256, min(16384, $maxTokens)),
            'messages'   => $this->buildMessages($system, $messages, $isReasoner),
        ];

        // R1 does not support response_format or system role
        if ($jsonMode && !$isReasoner) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $res = EvolutionJsonHttp::post(
            self::API_BASE . '/chat/completions',
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $body,
            self::TIMEOUT
        );

        $this->lastHttpStatus = $res['status'];

        if (!$res['ok'] || $res['body'] === '') {
            return '';
        }

        $j = json_decode($res['body'], true);
        if (!is_array($j)) {
            return '';
        }

        $content = trim((string)(($j['choices'][0]['message']['content'] ?? '')));

        // R1: extract <think>...</think> for logging, then strip from response
        if ($isReasoner && $content !== '') {
            if (preg_match('/<think>(.*?)<\/think>/s', $content, $thinkMatch)) {
                $this->logThinkTrace($thinkMatch[1], $model, $body['messages'] ?? []);
            }
            $stripped = preg_replace('/<think>.*?<\/think>/s', '', $content);
            $content  = trim((string)($stripped ?? $content));
        }

        return $content;
    }

    /**
     * Tool-calling completion (DeepSeek V3 — OpenAI-compatible function calling).
     * R1 does NOT support tool calls; this method enforces MODEL_CHAT.
     *
     * Returns either a text response or a list of tool calls depending on the
     * model's `finish_reason`. Callers should loop: execute tool → append result → call again.
     *
     * @param array<int, array{role: string, content: string}>  $messages
     * @param list<array<string, mixed>>                         $tools      OpenAI-format tool definitions
     * @param string                                             $toolChoice "auto"|"required"|"none" or {"type":"function","function":{"name":"..."}}
     * @return array{
     *   ok: bool,
     *   finish_reason: string,
     *   text: string,
     *   tool_calls: list<array{id: string, type: string, function: array{name: string, arguments: string}}>,
     *   http_status: int,
     *   error?: string
     * }
     */
    public function completeWithTools(
        string $system,
        array  $messages,
        string $model,
        int    $maxTokens,
        array  $tools,
        string $toolChoice = 'auto'
    ): array {
        if (self::isKilled()) {
            $this->lastHttpStatus = 503;
            return ['ok' => false, 'finish_reason' => 'killed', 'text' => '', 'tool_calls' => [], 'http_status' => 503, 'error' => 'Emergency kill switch active'];
        }

        // R1 does not support tool calls
        if ($model === self::MODEL_REASONER) {
            $model = self::MODEL_CHAT;
        }

        $body = [
            'model'       => $model,
            'max_tokens'  => max(256, min(8192, $maxTokens)),
            'messages'    => $this->buildMessages($system, $messages, false),
            'tools'       => $tools,
            'tool_choice' => $toolChoice,
        ];

        $res = EvolutionJsonHttp::post(
            self::API_BASE . '/chat/completions',
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $body,
            self::TIMEOUT
        );

        $this->lastHttpStatus = $res['status'];

        if (!$res['ok'] || $res['body'] === '') {
            return [
                'ok'           => false,
                'finish_reason' => 'error',
                'text'         => '',
                'tool_calls'   => [],
                'http_status'  => $res['status'],
                'error'        => 'HTTP ' . $res['status'],
            ];
        }

        $j = json_decode($res['body'], true);
        if (!is_array($j)) {
            return [
                'ok'           => false,
                'finish_reason' => 'error',
                'text'         => '',
                'tool_calls'   => [],
                'http_status'  => $res['status'],
                'error'        => 'Invalid JSON response',
            ];
        }

        $choice       = is_array($j['choices'][0] ?? null) ? $j['choices'][0] : [];
        $message      = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $finishReason = (string)($choice['finish_reason'] ?? 'stop');
        $text         = trim((string)($message['content'] ?? ''));

        $rawCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        $toolCalls = [];
        foreach ($rawCalls as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $toolCalls[] = [
                'id'       => (string)($tc['id'] ?? ''),
                'type'     => (string)($tc['type'] ?? 'function'),
                'function' => [
                    'name'      => (string)(($tc['function'] ?? [])['name'] ?? ''),
                    'arguments' => (string)(($tc['function'] ?? [])['arguments'] ?? '{}'),
                ],
            ];
        }

        return [
            'ok'           => true,
            'finish_reason' => $finishReason,
            'text'         => $text,
            'tool_calls'   => $toolCalls,
            'http_status'  => $res['status'],
        ];
    }

    /**
     * Lightweight connectivity check — returns HTTP status code.
     */
    public function ping(): int
    {
        $body = [
            'model'      => self::MODEL_CHAT,
            'max_tokens' => 1,
            'messages'   => [['role' => 'user', 'content' => '.']],
        ];

        $res = EvolutionJsonHttp::post(
            self::API_BASE . '/chat/completions',
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $body,
            15
        );

        return $res['status'];
    }

    /**
     * Appends R1 chain-of-thought reasoning to the strategy log.
     *
     * @param array<int, array{role: string, content: string}> $contextMessages
     */
    private function logThinkTrace(string $thinkContent, string $model, array $contextMessages): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }
        $logDir = BASE_PATH . '/data/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logPath = BASE_PATH . '/' . $this->reasoningLogFile;
        $lastUser = '';
        foreach (array_reverse($contextMessages) as $m) {
            if (($m['role'] ?? '') === 'user') {
                $lastUser = mb_substr((string)($m['content'] ?? ''), 0, 200);
                break;
            }
        }
        $entry = json_encode([
            'ts'          => date('c'),
            'model'       => $model,
            'user_hint'   => $lastUser,
            'think_chars' => mb_strlen($thinkContent),
            'think'       => mb_substr($thinkContent, 0, 12000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($entry !== false) {
            @file_put_contents($logPath, $entry . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(string $system, array $messages, bool $isReasoner): array
    {
        $out = [];

        if ($system !== '') {
            if ($isReasoner) {
                // R1: no system role — embed as leading user turn
                $out[] = ['role' => 'user',      'content' => "SYSTEM INSTRUCTIONS:\n" . $system];
                $out[] = ['role' => 'assistant', 'content' => 'Understood. I will follow those instructions.'];
            } else {
                $out[] = ['role' => 'system', 'content' => $system];
            }
        }

        foreach ($messages as $m) {
            $role    = strtolower(trim((string)($m['role'] ?? '')));
            $content = trim((string)($m['content'] ?? ''));

            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $out[] = ['role' => $role, 'content' => $content];
        }

        // Ensure the last message is from user
        if ($out === [] || (end($out)['role'] ?? '') !== 'user') {
            $out[] = ['role' => 'user', 'content' => 'Continue.'];
        }

        return $out;
    }
}
