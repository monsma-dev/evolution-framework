<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Persists high-value reasoning templates for offline / Ollama-only operation.
 *
 * When cloud APIs fail or eco-mode forces local inference, SovereignAiRouter
 * prepends the newest patterns so Llama-class models retain operational discipline.
 */
final class OfflineReasoningPatternStore
{
    /** @param list<array{title: string, body: string, saved_at: string, source?: string}> $patterns */
    public function __construct(
        private readonly Config $config,
        private array $patterns = []
    ) {
        $this->load();
    }

    public function record(string $title, string $body, string $source = 'manual'): void
    {
        $title = trim($title);
        $body  = trim($body);
        if ($title === '' || $body === '') {
            return;
        }
        $this->patterns[] = [
            'title'    => mb_substr($title, 0, 200),
            'body'     => mb_substr($body, 0, 4000),
            'saved_at' => gmdate('c'),
            'source'   => mb_substr($source, 0, 80),
        ];
        if (count($this->patterns) > 200) {
            $this->patterns = array_slice($this->patterns, -200);
        }
        $this->persist();
    }

    /**
     * Prefix to inject into system prompt when using local Ollama.
     */
    public function buildInjectionPrefix(): string
    {
        $evo = $this->config->get('evolution.offline_reasoning', []);
        if (!is_array($evo) || !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        if (!filter_var($evo['inject_on_ollama'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $max = max(1, min(12, (int)($evo['max_patterns_inject'] ?? 5)));
        $slice = array_slice(array_reverse($this->patterns), 0, $max);
        if ($slice === []) {
            return '';
        }
        $lines = ["--- OFFLINE REASONING PATTERNS (replay strictly) ---"];
        foreach ($slice as $p) {
            $lines[] = '[' . ($p['title'] ?? '') . '] ' . mb_substr((string)($p['body'] ?? ''), 0, 600);
        }
        $lines[] = '--- END OFFLINE PATTERNS ---';

        return implode("\n", $lines) . "\n\n";
    }

    private function load(): void
    {
        $path = $this->resolvePath();
        if (!is_readable($path)) {
            $this->patterns = [];
            return;
        }
        $raw = json_decode((string)file_get_contents($path), true);
        $this->patterns = is_array($raw) && isset($raw['patterns']) && is_array($raw['patterns'])
            ? $raw['patterns']
            : [];
    }

    private function persist(): void
    {
        $path = $this->resolvePath();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            'updated_at' => gmdate('c'),
            'patterns'   => $this->patterns,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function resolvePath(): string
    {
        $evo = $this->config->get('evolution.offline_reasoning', []);
        $rel = is_array($evo) ? trim((string)($evo['patterns_storage'] ?? 'storage/evolution/reasoning_patterns.json')) : 'storage/evolution/reasoning_patterns.json';
        if ($rel === '' || str_contains($rel, '..')) {
            $rel = 'storage/evolution/reasoning_patterns.json';
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);

        return rtrim($base, '/\\') . '/' . str_replace('\\', '/', $rel);
    }
}
