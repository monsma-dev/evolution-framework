<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use Throwable;

/**
 * Optional live web snippets for Architect (Tavily API or similar). Disabled when no API key.
 */
final class WebSearchAdapter
{
    /**
     * @return array{ok: bool, block: string, error?: string}
     */
    public static function buildContextBlock(Config $config, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['ok' => true, 'block' => ''];
        }

        $evo = $config->get('evolution', []);
        $ws = is_array($evo) ? ($evo['web_search'] ?? []) : [];
        if (!is_array($ws) || !filter_var($ws['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => true, 'block' => ''];
        }

        $maxQ = max(40, min(800, (int)($ws['max_query_chars'] ?? 400)));
        if (function_exists('mb_substr')) {
            $query = mb_substr($query, 0, $maxQ);
        } else {
            $query = substr($query, 0, $maxQ);
        }

        $provider = strtolower(trim((string)($ws['provider'] ?? 'tavily')));
        $apiKey = trim((string)($ws['api_key'] ?? ''));
        if ($apiKey === '') {
            return ['ok' => true, 'block' => '', 'error' => 'web_search_no_api_key'];
        }

        $maxResults = max(1, min(10, (int)($ws['max_results'] ?? 5)));
        $timeout = max(5, min(60, (int)($ws['timeout_seconds'] ?? 20)));

        if ($provider !== 'tavily') {
            return ['ok' => false, 'block' => '', 'error' => 'unsupported_web_search_provider'];
        }

        try {
            $res = EvolutionJsonHttp::post(
                'https://api.tavily.com/search',
                [],
                [
                    'api_key'      => $apiKey,
                    'query'        => $query,
                    'search_depth' => 'basic',
                    'max_results'  => $maxResults,
                ],
                $timeout
            );
            $raw = $res['body'];
            $j = json_decode($raw, true);
            if (!is_array($j)) {
                return ['ok' => false, 'block' => '', 'error' => 'invalid_search_response'];
            }
            $results = $j['results'] ?? [];
            if (!is_array($results) || $results === []) {
                return ['ok' => true, 'block' => "\n\n=== WEB SEARCH (no results) ===\nQuery: {$query}\n"];
            }

            $lines = ["\n\n=== WEB SEARCH SNIPPETS (verify URLs; prefer official docs) ===", 'Query: ' . $query, ''];
            foreach ($results as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = trim((string)($row['title'] ?? ''));
                $url = trim((string)($row['url'] ?? ''));
                $content = trim((string)($row['content'] ?? ''));
                if ($title === '' && $url === '' && $content === '') {
                    continue;
                }
                $lines[] = '- ' . ($title !== '' ? $title : $url);
                if ($url !== '') {
                    $lines[] = '  URL: ' . $url;
                }
                if ($content !== '') {
                    $lines[] = '  ' . preg_replace("/\s+/", ' ', $content);
                }
                $lines[] = '';
            }

            return ['ok' => true, 'block' => implode("\n", $lines) . "\n"];
        } catch (Throwable $e) {
            EvolutionLogger::log('architect', 'web_search_error', ['message' => $e->getMessage()]);

            return ['ok' => false, 'block' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * Best-effort: last user message text from Architect chat payload.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function queryFromMessages(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i] ?? null;
            if (!is_array($m)) {
                continue;
            }
            if (($m['role'] ?? '') === 'user' && is_string($m['content'] ?? null)) {
                return trim($m['content']);
            }
        }

        return '';
    }
}
