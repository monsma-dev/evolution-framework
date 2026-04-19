<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Evolution\Design\DesignAgentService;
use App\Core\Evolution\Design\DesignComponentLibrary;

use App\Core\Config;

/**
 * Toolbox for AI agents: Tavily web search, log reader, and report writer.
 *
 * Provides OpenAI-compatible tool definitions that can be passed to
 * DeepSeekClient::completeWithTools() or any OpenAI-compat client.
 *
 * Available tools:
 *   search_web       — Tavily Search API (max 10 results)
 *   read_log         — Read last N lines from a storage/logs/*.log file
 *   write_report     — Write a markdown report to storage/app/reports/
 */
final class EvolutionToolboxService
{
    private const TAVILY_URL  = 'https://api.tavily.com/search';
    private const REPORTS_DIR = 'storage/app/reports';
    private const LOG_DIR     = 'storage/logs';
    private const MAX_LOG_LINES = 200;

    public function __construct(private readonly Config $config)
    {
    }

    // ─── Tool definitions (OpenAI-compatible) ────────────────────────────────

    /**
     * Returns the tool schema array to pass to completeWithTools().
     *
     * @return list<array<string, mixed>>
     */
    public function getToolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_web',
                    'description' => 'Search the web for real-time information using Tavily. Use for market research, competitor analysis, and trend discovery.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query'       => ['type' => 'string', 'description' => 'Search query'],
                            'max_results' => ['type' => 'integer', 'description' => 'Number of results (1-10, default 5)'],
                            'search_type' => ['type' => 'string', 'enum' => ['basic', 'advanced'], 'description' => 'basic = fast, advanced = deeper'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'read_log',
                    'description' => 'Read the last N lines from a log file in storage/logs/. Use to inspect evolution_reasoning.log, evolution_strategy.log, or evolution.log.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string', 'description' => 'Log filename (e.g. evolution_reasoning.log)'],
                            'lines'    => ['type' => 'integer', 'description' => 'Number of lines to read from the end (max 200, default 50)'],
                        ],
                        'required' => ['filename'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'save_component',
                    'description' => 'Generate and save a reusable Tailwind CSS UI component to the Evolution component library. Use for any UI, dashboard, form, card, or table design task.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'prompt' => ['type' => 'string', 'description' => 'Natural-language description of the component to generate'],
                            'name'   => ['type' => 'string', 'description' => 'Component filename (snake_case, no extension)'],
                            'type'   => ['type' => 'string', 'enum' => ['card', 'table', 'form', 'chart', 'dashboard', 'modal', 'navbar', 'generic'], 'description' => 'Component category'],
                        ],
                        'required' => ['prompt', 'name'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'write_report',
                    'description' => 'Write a markdown report to storage/app/reports/. Use to persist analysis results, strategy summaries, or audit findings.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'name'    => ['type' => 'string', 'description' => 'Report filename without extension (e.g. niche-analysis-2026-04)'],
                            'content' => ['type' => 'string', 'description' => 'Markdown content for the report'],
                        ],
                        'required' => ['name', 'content'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Dispatch a tool call by name.
     *
     * @param array<string, mixed> $args
     * @return array{ok: bool, result?: mixed, error?: string}
     */
    public function dispatchToolCall(string $toolName, array $args): array
    {
        return match ($toolName) {
            'search_web'     => $this->searchWeb((string)($args['query'] ?? ''), (int)($args['max_results'] ?? 5), (string)($args['search_type'] ?? 'basic')),
            'read_log'       => $this->readLog((string)($args['filename'] ?? ''), (int)($args['lines'] ?? 50)),
            'write_report'   => $this->writeReport((string)($args['name'] ?? ''), (string)($args['content'] ?? '')),
            'save_component' => $this->saveComponent((string)($args['prompt'] ?? ''), (string)($args['name'] ?? ''), (string)($args['type'] ?? 'generic')),
            default          => ['ok' => false, 'error' => "Unknown tool: {$toolName}"],
        };
    }

    // ─── Tool implementations ────────────────────────────────────────────────

    /**
     * Tavily web search.
     *
     * @return array{ok: bool, results?: list<array{title: string, url: string, content: string, score: float}>, error?: string}
     */
    public function searchWeb(string $query, int $maxResults = 5, string $searchType = 'basic'): array
    {
        $apiKey = trim((string)$this->config->get('evolution.web_search.api_key', ''));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Tavily API key not configured (evolution.web_search.api_key / TAVILY_API_KEY).'];
        }

        if (trim($query) === '') {
            return ['ok' => false, 'error' => 'query is required.'];
        }

        $maxResults = max(1, min(10, $maxResults));
        $payload    = json_encode([
            'api_key'      => $apiKey,
            'query'        => mb_substr($query, 0, 400),
            'search_depth' => in_array($searchType, ['basic', 'advanced'], true) ? $searchType : 'basic',
            'max_results'  => $maxResults,
            'include_answer' => true,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return ['ok' => false, 'error' => 'Payload encoding failed.'];
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
                'content'       => $payload,
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
        ]);

        $rawResponse = @file_get_contents(self::TAVILY_URL, false, $ctx);
        if (!is_string($rawResponse)) {
            return ['ok' => false, 'error' => 'Tavily request failed (network error).'];
        }

        $j = json_decode($rawResponse, true);
        if (!is_array($j)) {
            return ['ok' => false, 'error' => 'Invalid Tavily response.'];
        }

        if (isset($j['error'])) {
            return ['ok' => false, 'error' => 'Tavily error: ' . (string)$j['error']];
        }

        $results = [];
        foreach ((array)($j['results'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            // Context-Compressor: strip HTML noise from raw web content (~70% token reduction)
            $rawContent = (string)($r['content'] ?? '');
            $cleanContent = \App\Core\Evolution\Growth\SignalDiscoveryService::strip_noise($rawContent, 800);
            $results[] = [
                'title'   => mb_substr((string)($r['title'] ?? ''), 0, 200),
                'url'     => (string)($r['url'] ?? ''),
                'content' => $cleanContent,
                'score'   => (float)($r['score'] ?? 0),
            ];
        }

        return [
            'ok'      => true,
            'answer'  => mb_substr((string)($j['answer'] ?? ''), 0, 500),
            'results' => $results,
        ];
    }

    /**
     * Read tail of a log file from storage/logs/.
     *
     * @return array{ok: bool, lines?: list<string>, file?: string, error?: string}
     */
    public function readLog(string $filename, int $lines = 50): array
    {
        if (!defined('BASE_PATH')) {
            return ['ok' => false, 'error' => 'BASE_PATH not defined.'];
        }

        // Security: only allow simple filenames, no path traversal
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $filename);
        if ($sanitized === '' || !str_ends_with($sanitized, '.log')) {
            return ['ok' => false, 'error' => 'Invalid log filename. Must end in .log and contain only alphanumeric, dash, underscore, or dot.'];
        }

        $path = BASE_PATH . '/' . self::LOG_DIR . '/' . $sanitized;
        if (!is_file($path)) {
            return ['ok' => false, 'error' => "Log file not found: {$sanitized}"];
        }

        $lines   = max(1, min(self::MAX_LOG_LINES, $lines));
        $content = @file_get_contents($path);
        if ($content === false) {
            return ['ok' => false, 'error' => 'Could not read log file.'];
        }

        $allLines = explode("\n", trim($content));
        $tail     = array_values(array_slice($allLines, -$lines));

        return ['ok' => true, 'file' => $sanitized, 'lines' => $tail];
    }

    /**
     * Write a markdown report to storage/app/reports/.
     *
     * @return array{ok: bool, path?: string, error?: string}
     */
    public function writeReport(string $name, string $content): array
    {
        if (!defined('BASE_PATH')) {
            return ['ok' => false, 'error' => 'BASE_PATH not defined.'];
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
        if ($sanitized === '') {
            return ['ok' => false, 'error' => 'Invalid report name.'];
        }

        $dir = BASE_PATH . '/' . self::REPORTS_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = $sanitized . '_' . date('Ymd_His') . '.md';
        $path     = $dir . '/' . $filename;
        $header   = "# {$sanitized}\n_Generated: " . date('c') . "_\n\n";

        $written = @file_put_contents($path, $header . $content, LOCK_EX);
        if ($written === false) {
            return ['ok' => false, 'error' => 'Could not write report file.'];
        }

        return ['ok' => true, 'path' => self::REPORTS_DIR . '/' . $filename, 'bytes' => $written];
    }

    /**
     * Generate and save a Tailwind CSS UI component to the Evolution component library.
     *
     * @return array{ok: bool, name?: string, saved_to?: string, error?: string}
     */
    public function saveComponent(string $prompt, string $name, string $type = 'generic'): array
    {
        if (trim($prompt) === '') {
            return ['ok' => false, 'error' => 'prompt is required.'];
        }
        if (trim($name) === '') {
            return ['ok' => false, 'error' => 'name is required.'];
        }

        $library = new DesignComponentLibrary();
        $agent   = new DesignAgentService($this->config, $library);
        $result  = $agent->generate($prompt, $name, $type);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Component generation failed'];
        }

        return [
            'ok'       => true,
            'name'     => $result['name'] ?? $name,
            'saved_to' => $result['saved_to'] ?? '',
            'preview'  => '/api/v1/design/preview/' . ($result['name'] ?? $name),
        ];
    }
}
