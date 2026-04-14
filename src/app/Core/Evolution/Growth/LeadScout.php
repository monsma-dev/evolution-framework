<?php

declare(strict_types=1);

namespace App\Core\Evolution\Growth;

/**
 * LeadScout — Real-time GitHub & StackOverflow lead finder.
 *
 * Searches for developers struggling with AI cost, rate limits, or vendor lock-in.
 * Stores raw leads in storage/evolution/growth/leads.jsonl.
 *
 * GitHub token (optional but raises rate limit 10→30 req/min):
 *   Set GITHUB_TOKEN env var or evolution.json growth.github_token
 */
final class LeadScout
{
    private const LEADS_FILE    = 'storage/evolution/growth/leads.jsonl';
    private const SEEN_FILE     = 'storage/evolution/growth/seen_ids.json';
    private const GITHUB_SEARCH = 'https://api.github.com/search/issues';
    private const SO_SEARCH     = 'https://api.stackexchange.com/2.3/search/advanced';
    private const TIMEOUT       = 10;

    private const GITHUB_KEYWORDS = [
        'openai cost too high',
        'claude api billing expensive',
        'anthropic rate limit reached',
        'ai api costs out of control',
        'llm cost optimization php',
        'replace openai self-hosted',
        'local llm alternative openai',
        'openai token limit exceeded',
        'ai vendor lock-in',
        'reduce llm api costs',
    ];

    private const SO_TAGS = ['openai-api', 'llm', 'chatgpt-api', 'anthropic'];
    private const SO_INTITLE_KEYWORDS = ['cost', 'expensive', 'rate limit', 'billing', 'self-hosted'];

    private string $basePath;
    private ?string $githubToken;
    private array $seenIds;

    public function __construct(?string $basePath = null, ?string $githubToken = null)
    {
        $this->basePath    = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 5));
        $this->githubToken = $githubToken
            ?? getenv('GITHUB_TOKEN')
            ?: $this->configToken();
        $this->seenIds = $this->loadSeenIds();
    }

    /**
     * Run a full scout pass. Returns ['leads_found'=>n, 'new'=>n, 'sources'=>[]].
     */
    public function scout(int $maxPerKeyword = 5): array
    {
        $this->ensureDir();
        $allLeads  = [];
        $newLeads  = 0;

        foreach (self::GITHUB_KEYWORDS as $keyword) {
            $results = $this->searchGitHub($keyword, $maxPerKeyword);
            foreach ($results as $lead) {
                if (!isset($this->seenIds[$lead['external_id']])) {
                    $this->appendLead($lead);
                    $this->seenIds[$lead['external_id']] = date('c');
                    $allLeads[] = $lead;
                    $newLeads++;
                }
            }
            usleep(120_000); // respect 10 req/s GitHub limit
        }

        foreach (self::SO_INTITLE_KEYWORDS as $kw) {
            $results = $this->searchStackOverflow($kw, $maxPerKeyword);
            foreach ($results as $lead) {
                if (!isset($this->seenIds[$lead['external_id']])) {
                    $this->appendLead($lead);
                    $this->seenIds[$lead['external_id']] = date('c');
                    $allLeads[] = $lead;
                    $newLeads++;
                }
            }
            usleep(250_000);
        }

        $this->saveSeenIds();

        return [
            'leads_found' => count($allLeads),
            'new'         => $newLeads,
            'sources'     => ['github', 'stackoverflow'],
            'scanned_at'  => date('c'),
        ];
    }

    /** Returns recent leads (newest first). */
    public function recentLeads(int $limit = 50): array
    {
        $file = $this->basePath . '/' . self::LEADS_FILE;
        if (!is_file($file)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($file)));
        $leads = [];
        foreach ($lines as $line) {
            $d = json_decode($line, true);
            if (is_array($d)) {
                $leads[] = $d;
            }
        }
        return array_slice(array_reverse($leads), 0, $limit);
    }

    /** Returns total lead count. */
    public function count(): int
    {
        $file = $this->basePath . '/' . self::LEADS_FILE;
        if (!is_file($file)) {
            return 0;
        }
        return count(array_filter(explode("\n", (string) file_get_contents($file))));
    }

    private function searchGitHub(string $keyword, int $limit): array
    {
        $url = self::GITHUB_SEARCH . '?' . http_build_query([
            'q'        => $keyword . ' is:issue is:open',
            'sort'     => 'created',
            'order'    => 'desc',
            'per_page' => $limit,
        ]);

        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: Evolution-LeadScout/1.0',
        ];
        if ($this->githubToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->githubToken;
        }

        $body = $this->httpGet($url, $headers);
        if ($body === null) {
            return [];
        }

        $data  = json_decode($body, true);
        $items = $data['items'] ?? [];
        $leads = [];

        foreach ($items as $item) {
            $leads[] = [
                'external_id'  => 'gh_' . ($item['id'] ?? uniqid()),
                'source'       => 'github',
                'title'        => $item['title'] ?? '',
                'url'          => $item['html_url'] ?? '',
                'author'       => $item['user']['login'] ?? 'unknown',
                'author_url'   => 'https://github.com/' . ($item['user']['login'] ?? ''),
                'body_snippet' => substr(strip_tags((string)($item['body'] ?? '')), 0, 300),
                'keyword'      => $keyword,
                'created_at'   => $item['created_at'] ?? date('c'),
                'scouted_at'   => date('c'),
                'pitched'      => false,
                'pain_score'   => $this->painScore($item['title'] ?? '', $item['body'] ?? ''),
            ];
        }

        return $leads;
    }

    private function searchStackOverflow(string $intitle, int $limit): array
    {
        $url = self::SO_SEARCH . '?' . http_build_query([
            'order'    => 'desc',
            'sort'     => 'creation',
            'intitle'  => $intitle,
            'tagged'   => implode(';', self::SO_TAGS),
            'site'     => 'stackoverflow',
            'pagesize' => $limit,
            'filter'   => 'withbody',
        ]);

        $body = $this->httpGet($url, ['Accept-Encoding: gzip']);
        if ($body === null) {
            return [];
        }

        $data  = json_decode($body, true);
        $items = $data['items'] ?? [];
        $leads = [];

        foreach ($items as $item) {
            $leads[] = [
                'external_id'  => 'so_' . ($item['question_id'] ?? uniqid()),
                'source'       => 'stackoverflow',
                'title'        => $item['title'] ?? '',
                'url'          => $item['link'] ?? '',
                'author'       => $item['owner']['display_name'] ?? 'unknown',
                'author_url'   => $item['owner']['link'] ?? '',
                'body_snippet' => substr(strip_tags((string)($item['body'] ?? '')), 0, 300),
                'keyword'      => $intitle,
                'created_at'   => date('c', (int)($item['creation_date'] ?? time())),
                'scouted_at'   => date('c'),
                'pitched'      => false,
                'pain_score'   => $this->painScore($item['title'] ?? '', $item['body'] ?? ''),
            ];
        }

        return $leads;
    }

    /**
     * Pain score 0–100: higher = more likely this dev has a real cost/rate-limit problem.
     */
    private function painScore(string $title, string $body): int
    {
        $text = strtolower($title . ' ' . $body);
        $score = 0;
        $signals = [
            '$'        => 15, 'cost'       => 10, 'expensive' => 12, 'billing'   => 12,
            'rate limit' => 15, 'quota'    => 10, 'too much'  => 8,  'per month' => 10,
            'self-host'  => 18, 'local'    => 8,  'open source' => 8, 'alternative' => 10,
            'vendor lock' => 20, 'migrate' => 8,  'cheaper'   => 12, 'budget'    => 8,
            'php'        => 5,  'api'      => 3,  'openai'    => 5,  'claude'    => 5,
        ];
        foreach ($signals as $signal => $points) {
            if (str_contains($text, $signal)) {
                $score += $points;
            }
        }
        return min(100, $score);
    }

    private function httpGet(string $url, array $headers = []): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($status === 200 && is_string($body)) ? $body : null;
    }

    private function appendLead(array $lead): void
    {
        $file = $this->basePath . '/' . self::LEADS_FILE;
        file_put_contents($file, json_encode($lead) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function loadSeenIds(): array
    {
        $file = $this->basePath . '/' . self::SEEN_FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveSeenIds(): void
    {
        $file = $this->basePath . '/' . self::SEEN_FILE;
        file_put_contents($file, json_encode($this->seenIds, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function ensureDir(): void
    {
        $dir = $this->basePath . '/storage/evolution/growth';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    private function configToken(): string
    {
        if (!defined('BASE_PATH')) {
            return '';
        }
        $cfg = BASE_PATH . '/src/config/evolution.json';
        if (!is_file($cfg)) {
            return '';
        }
        $data = json_decode((string) file_get_contents($cfg), true);
        return trim((string)(is_array($data) ? ($data['growth']['github_token'] ?? '') : ''));
    }
}
