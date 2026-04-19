<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Scout "Profitable Mining" — discovers yield/airdrop signals (GitHub + Tavily + light RPC) and stores in VectorMemory.
 */
final class ScoutAlphaHarvestService
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{ok: bool, stored: int, sources: list<string>, hints?: list<string>}
     */
    public function run(): array
    {
        $hints = [];
        $stored = 0;
        $sources = [];
        $baseDir = defined('BASE_PATH') ? (string)BASE_PATH : dirname(__DIR__, 3);
        $ns = 'alpha_yield';
        $mem = new VectorMemoryService($ns, $baseDir . '/storage/evolution/vector_memory');

        $queries = [
            'Base network yield farm staking 2026',
            'Base chain airdrop liquidity mining',
        ];

        $tavily = trim((string)($this->config->get('evolution.web_search.api_key', '')));
        if ($tavily === '') {
            $tavily = trim((string)(getenv('TAVILY_API_KEY') ?: ''));
        }
        if ($tavily !== '') {
            foreach ($queries as $q) {
                $chunk = $this->tavilySearch($tavily, $q . ' site:github.com OR site:base.org');
                if ($chunk !== '') {
                    $ok = $mem->store($chunk, ['source' => 'tavily', 'query' => $q, 'chain' => 'base']);
                    if ($ok) {
                        $stored++;
                        $sources[] = 'tavily';
                    }
                }
            }
        } else {
            $hints[] = 'TAVILY_API_KEY empty — skip web search.';
        }

        $gh = trim((string)(getenv('GITHUB_TOKEN') ?: getenv('GITHUB_ACADEMY_TOKEN') ?: ''));
        if ($gh !== '') {
            $chunk = $this->githubSearchRepositories($gh, 'base+staking+language:solidity');
            if ($chunk !== '') {
                $ok = $mem->store($chunk, ['source' => 'github', 'chain' => 'base']);
                if ($ok) {
                    $stored++;
                    $sources[] = 'github';
                }
            }
        } else {
            $hints[] = 'GITHUB_TOKEN empty — skip GitHub code search.';
        }

        $rpc = trim((string)($this->config->get('evolution.resource_harvester.rpc_base', '')));
        if ($rpc === '') {
            $rpc = 'https://mainnet.base.org';
        }
        $onChain = $this->pingBaseRpc($rpc);
        if ($onChain !== '') {
            $ok = $mem->store($onChain, ['source' => 'onchain_rpc', 'rpc' => $rpc]);
            if ($ok) {
                $stored++;
                $sources[] = 'onchain';
            }
        }

        EvolutionLogger::log('scout', 'alpha_harvest', ['stored' => $stored, 'sources' => array_values(array_unique($sources))]);

        return [
            'ok' => true,
            'stored' => $stored,
            'sources' => array_values(array_unique($sources)),
            'hints' => $hints,
        ];
    }

    private function tavilySearch(string $apiKey, string $query): string
    {
        $payload = json_encode([
            'api_key' => $apiKey,
            'query' => $query,
            'max_results' => 5,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return '';
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents('https://api.tavily.com/search', false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $j = json_decode($raw, true);

        return is_array($j) ? substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 4000) : '';
    }

    private function githubSearchRepositories(string $token, string $q): string
    {
        $url = 'https://api.github.com/search/repositories?q=' . rawurlencode($q) . '&per_page=5';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\nAccept: application/vnd.github+json\r\nUser-Agent: EvolutionScout\r\n",
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        return substr($raw, 0, 4000);
    }

    private function pingBaseRpc(string $rpcUrl): string
    {
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_blockNumber', 'params' => []]);
        if ($payload === false) {
            return '';
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($rpcUrl, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        return 'Base RPC ping: ' . substr($raw, 0, 500);
    }
}
