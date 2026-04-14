<?php

declare(strict_types=1);

namespace App\Core\Evolution\GitHub;

/**
 * EvolutionGitHubService — GitHub REST API v3 client.
 *
 * All operations respect rate limits (X-RateLimit-Remaining header).
 * Token resolved from: GITHUB_TOKEN env var → evolution.json github.token
 *
 * Capabilities:
 *   - Repository management (create, get, update)
 *   - File sync (get/create/update via Contents API)
 *   - Issues (list, get, create, label, comment, close)
 *   - Webhooks (create, list, delete)
 *   - Secrets (create/update repo secrets via Sodium encryption)
 *   - Gists (create, update — for public leaderboard)
 */
final class EvolutionGitHubService
{
    private const API_BASE   = 'https://api.github.com';
    private const TIMEOUT    = 15;

    private string $token;
    private string $owner;
    private string $repo;

    private int $rateLimitRemaining = 60;
    private int $rateLimitReset     = 0;

    public function __construct(string $token = '', string $owner = '', string $repo = '')
    {
        $this->token = $token ?: (string)(getenv('GITHUB_TOKEN') ?: $this->configValue('github.token'));
        $this->owner = $owner ?: (string)$this->configValue('github.owner');
        $this->repo  = $repo  ?: (string)$this->configValue('github.repo');
    }

    // ── Repository ───────────────────────────────────────────────────────

    public function getRepo(): ?array
    {
        return $this->get("/repos/{$this->owner}/{$this->repo}");
    }

    public function createRepo(string $name, string $description = '', bool $private = false): ?array
    {
        return $this->post('/user/repos', [
            'name'        => $name,
            'description' => $description,
            'private'     => $private,
            'auto_init'   => true,
        ]);
    }

    public function updateRepoDescription(string $description): ?array
    {
        return $this->patch("/repos/{$this->owner}/{$this->repo}", [
            'description' => $description,
            'has_issues'  => true,
            'has_wiki'    => false,
        ]);
    }

    // ── File Contents ────────────────────────────────────────────────────

    /** Get file metadata + content (base64 encoded). Returns null if not found. */
    public function getFile(string $path, string $branch = 'main'): ?array
    {
        return $this->get("/repos/{$this->owner}/{$this->repo}/contents/{$path}?ref={$branch}");
    }

    /** Create or update a file. $content is raw string (will be base64 encoded). */
    public function putFile(string $path, string $content, string $message, string $branch = 'main'): ?array
    {
        $existing = null;
        try {
            $existing = $this->getFile($path, $branch);
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), '404')) {
                throw $e;
            }
            // 404 = file doesn't exist yet, we'll create it
        }
        $params = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch'  => $branch,
        ];
        if (is_array($existing) && isset($existing['sha'])) {
            $params['sha'] = $existing['sha'];
        }
        return $this->put("/repos/{$this->owner}/{$this->repo}/contents/{$path}", $params);
    }

    /** Delete a file from the repository. */
    public function deleteFile(string $path, string $sha, string $message, string $branch = 'main'): ?array
    {
        return $this->delete("/repos/{$this->owner}/{$this->repo}/contents/{$path}", [
            'message' => $message,
            'sha'     => $sha,
            'branch'  => $branch,
        ]);
    }

    // ── Issues ───────────────────────────────────────────────────────────

    public function listIssues(string $state = 'open', int $perPage = 30, string $label = ''): array
    {
        $query = "state={$state}&per_page={$perPage}";
        if ($label !== '') {
            $query .= '&labels=' . urlencode($label);
        }
        return $this->get("/repos/{$this->owner}/{$this->repo}/issues?{$query}") ?? [];
    }

    public function getIssue(int $number): ?array
    {
        return $this->get("/repos/{$this->owner}/{$this->repo}/issues/{$number}");
    }

    public function createIssue(string $title, string $body = '', array $labels = []): ?array
    {
        return $this->post("/repos/{$this->owner}/{$this->repo}/issues", [
            'title'  => $title,
            'body'   => $body,
            'labels' => $labels,
        ]);
    }

    public function addIssueComment(int $issueNumber, string $body): ?array
    {
        return $this->post("/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments", [
            'body' => $body,
        ]);
    }

    public function addIssueLabels(int $issueNumber, array $labels): ?array
    {
        return $this->post("/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/labels", [
            'labels' => $labels,
        ]);
    }

    public function closeIssue(int $issueNumber): ?array
    {
        return $this->patch("/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}", [
            'state' => 'closed',
        ]);
    }

    /** Create a label if it doesn't already exist. */
    public function ensureLabel(string $name, string $color = 'E8532C', string $description = ''): ?array
    {
        try {
            $existing = $this->get("/repos/{$this->owner}/{$this->repo}/labels/" . urlencode($name));
            if ($existing !== null && isset($existing['name'])) {
                return $existing;
            }
        } catch (\RuntimeException $e) {
            // 404 = label doesn't exist yet, proceed to create
            if (!str_contains($e->getMessage(), '404')) {
                throw $e;
            }
        }
        return $this->post("/repos/{$this->owner}/{$this->repo}/labels", [
            'name'        => $name,
            'color'       => $color,
            'description' => $description,
        ]);
    }

    // ── Webhooks ─────────────────────────────────────────────────────────

    public function listWebhooks(): array
    {
        return $this->get("/repos/{$this->owner}/{$this->repo}/hooks") ?? [];
    }

    public function createWebhook(string $url, array $events = ['issues', 'issue_comment', 'push'], string $secret = ''): ?array
    {
        $config = ['url' => $url, 'content_type' => 'json', 'insecure_ssl' => '0'];
        if ($secret !== '') {
            $config['secret'] = $secret;
        }
        return $this->post("/repos/{$this->owner}/{$this->repo}/hooks", [
            'name'   => 'web',
            'active' => true,
            'events' => $events,
            'config' => $config,
        ]);
    }

    public function deleteWebhook(int $hookId): bool
    {
        $result = $this->delete("/repos/{$this->owner}/{$this->repo}/hooks/{$hookId}");
        return $result !== null;
    }

    // ── Secrets ──────────────────────────────────────────────────────────

    /** Get repo public key for secret encryption. */
    public function getRepoPublicKey(): ?array
    {
        return $this->get("/repos/{$this->owner}/{$this->repo}/actions/secrets/public-key");
    }

    /**
     * Create or update a repository secret.
     * Requires libsodium (ext-sodium) for encryption.
     */
    public function putSecret(string $secretName, string $value): bool
    {
        $keyData = $this->getRepoPublicKey();
        if (!is_array($keyData)) {
            return false;
        }

        $publicKey = base64_decode($keyData['key']);
        $keyId     = $keyData['key_id'];

        if (!function_exists('sodium_crypto_box_seal')) {
            throw new \RuntimeException('ext-sodium is required for GitHub secret encryption.');
        }

        $encrypted = sodium_crypto_box_seal($value, $publicKey);
        $result = $this->put("/repos/{$this->owner}/{$this->repo}/actions/secrets/{$secretName}", [
            'encrypted_value' => base64_encode($encrypted),
            'key_id'          => $keyId,
        ]);
        return $result !== null;
    }

    // ── Gists (Affiliate Leaderboard) ────────────────────────────────────

    public function createGist(string $filename, string $content, string $description = '', bool $public = true): ?array
    {
        return $this->post('/gists', [
            'description' => $description,
            'public'      => $public,
            'files'       => [$filename => ['content' => $content]],
        ]);
    }

    public function updateGist(string $gistId, string $filename, string $content): ?array
    {
        return $this->patch("/gists/{$gistId}", [
            'files' => [$filename => ['content' => $content]],
        ]);
    }

    // ── Rate Limit ───────────────────────────────────────────────────────

    public function rateLimit(): array
    {
        return $this->get('/rate_limit') ?? [];
    }

    public function rateLimitRemaining(): int
    {
        return $this->rateLimitRemaining;
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->owner !== '' && $this->repo !== '';
    }

    public function getOwner(): string  { return $this->owner; }
    public function repoName(): string  { return $this->repo; }

    // ── HTTP helpers ─────────────────────────────────────────────────────

    private function get(string $path): array|null
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $body = []): array|null
    {
        return $this->request('POST', $path, $body);
    }

    private function put(string $path, array $body = []): array|null
    {
        return $this->request('PUT', $path, $body);
    }

    private function patch(string $path, array $body = []): array|null
    {
        return $this->request('PATCH', $path, $body);
    }

    private function delete(string $path, array $body = []): array|null
    {
        return $this->request('DELETE', $path, $body);
    }

    private function request(string $method, string $path, array $body = []): array|null
    {
        if ($this->rateLimitRemaining <= 1 && time() < $this->rateLimitReset) {
            throw new \RuntimeException(
                'GitHub rate limit exhausted. Resets at ' . date('H:i:s', $this->rateLimitReset)
            );
        }

        $url  = self::API_BASE . $path;
        $json = $body !== [] ? json_encode($body) : null;

        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: Evolution-Framework/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADER         => true,
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = substr($raw, 0, $hSize);
        $responseBody    = substr($raw, $hSize);

        // Parse rate limit headers
        if (preg_match('/X-RateLimit-Remaining:\s*(\d+)/i', $responseHeaders, $m)) {
            $this->rateLimitRemaining = (int) $m[1];
        }
        if (preg_match('/X-RateLimit-Reset:\s*(\d+)/i', $responseHeaders, $m)) {
            $this->rateLimitReset = (int) $m[1];
        }

        if ($status === 204 || $responseBody === '') {
            return [];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return null;
        }

        if ($status >= 400) {
            $message = $decoded['message'] ?? 'Unknown GitHub API error';
            throw new \RuntimeException("GitHub API {$status}: {$message} [{$method} {$path}]");
        }

        return $decoded;
    }

    private function configValue(string $key): mixed
    {
        if (!defined('BASE_PATH')) {
            return '';
        }
        $file = BASE_PATH . '/src/config/evolution.json';
        if (!is_file($file)) {
            return '';
        }
        static $config = null;
        if ($config === null) {
            $data   = json_decode((string) file_get_contents($file), true);
            $config = is_array($data) ? $data : [];
        }
        $parts = explode('.', $key);
        $current = $config;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return '';
            }
            $current = $current[$part];
        }
        return $current;
    }
}
