<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * OllamaClient — local sovereign AI inference via Ollama (http://ollama:11434).
 *
 * Used as a CPU-based fallback when the DeepSeek API is unavailable or rate-limited.
 * Compatible with models like deepseek-r1:1.5b and llama3.2:3b which run in RAM
 * on a c7g.xlarge (4 vCPU / 8 GB) without a GPU.
 *
 * Ollama API docs: https://github.com/ollama/ollama/blob/main/docs/api.md
 */
final class OllamaClient
{
    private const DEFAULT_HOST    = 'http://ollama:11434';
    private const DEFAULT_MODEL   = 'deepseek-r1:1.5b';
    private const TIMEOUT         = 120;

    private string $host;
    private string $defaultModel;
    private int $lastHttpStatus = 0;

    public function __construct(string $host = '', string $defaultModel = '')
    {
        $this->host         = rtrim($host !== '' ? $host : self::DEFAULT_HOST, '/');
        $this->defaultModel = $defaultModel !== '' ? $defaultModel : self::DEFAULT_MODEL;
    }

    // ─── Public API ──────────────────────────────────────────────────────────────

    /**
     * Text completion via /api/chat (OpenAI-style messages).
     * Strips <think>...</think> blocks from R1 responses.
     */
    public function complete(string $system, array $messages, ?string $model = null, int $maxTokens = 2048): string
    {
        if (DeepSeekClient::isKilled()) {
            $this->lastHttpStatus = 503;
            return '';
        }

        $model = $model ?? $this->defaultModel;

        $msgs = [];
        if ($system !== '') {
            $msgs[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($messages as $m) {
            if (is_array($m) && isset($m['role'], $m['content'])) {
                $msgs[] = ['role' => (string)$m['role'], 'content' => (string)$m['content']];
            }
        }

        $body = [
            'model'    => $model,
            'messages' => $msgs,
            'stream'   => false,
            'options'  => [
                'num_predict' => max(128, min(4096, $maxTokens)),
                'temperature' => 0.3,
            ],
        ];

        $res = EvolutionJsonHttp::post(
            $this->host . '/api/chat',
            ['Content-Type' => 'application/json'],
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

        $content = trim((string)(($j['message'] ?? [])['content'] ?? ''));

        // Strip <think> blocks (R1 distilled models emit chain-of-thought)
        $stripped = preg_replace('/<think>.*?<\/think>/s', '', $content);
        return trim((string)($stripped ?? $content));
    }

    /**
     * Liveness check — returns true if Ollama is reachable and the default model is loaded.
     */
    public function ping(): bool
    {
        $res = EvolutionJsonHttp::post(
            $this->host . '/api/chat',
            ['Content-Type' => 'application/json'],
            ['model' => $this->defaultModel, 'messages' => [['role' => 'user', 'content' => '1+1=']], 'stream' => false, 'options' => ['num_predict' => 4]],
            15
        );

        $this->lastHttpStatus = $res['status'];
        return $res['ok'] && $res['status'] === 200;
    }

    /**
     * List models available in this Ollama instance.
     * @return list<string>
     */
    public function listModels(): array
    {
        $res = EvolutionJsonHttp::get(
            $this->host . '/api/tags',
            10
        );

        if (!$res['ok']) {
            return [];
        }

        $j = json_decode($res['body'], true);
        if (!is_array($j) || !is_array($j['models'] ?? null)) {
            return [];
        }

        return array_map(
            static fn(array $m): string => (string)($m['name'] ?? ''),
            $j['models']
        );
    }

    public function getLastHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }
}
