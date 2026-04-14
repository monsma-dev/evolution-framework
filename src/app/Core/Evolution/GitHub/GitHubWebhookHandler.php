<?php

declare(strict_types=1);

namespace App\Core\Evolution\GitHub;

/**
 * GitHubWebhookHandler — Processes inbound GitHub webhook events.
 *
 * Supported events:
 *   - issues.opened / issues.reopened    → auto-label if matches lead keywords
 *   - issue_comment.created              → track engagement on prospect issues
 *   - push                               → log sync triggers
 *
 * Webhook log: storage/evolution/github/webhook_log.jsonl
 */
final class GitHubWebhookHandler
{
    private const WEBHOOK_LOG    = 'storage/evolution/github/webhook_log.jsonl';
    private const PROSPECT_LABEL = 'evolution-ai-prospect';

    private const PAIN_KEYWORDS = [
        'openai', 'claude', 'anthropic', 'api cost', 'rate limit',
        'billing', 'expensive', 'self-host', 'local llm', 'vendor lock',
    ];

    private string $basePath;
    private string $webhookSecret;

    public function __construct(?string $basePath = null)
    {
        $this->basePath      = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 5));
        $this->webhookSecret = $this->loadSecret();
    }

    /**
     * Process a raw webhook payload.
     * Returns ['ok'=>bool, 'action'=>string, 'handled'=>bool, 'detail'=>string].
     */
    public function handle(string $rawBody, string $signature, string $eventType): array
    {
        if (!$this->verifySignature($rawBody, $signature)) {
            return ['ok' => false, 'error' => 'Invalid webhook signature'];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'Invalid JSON payload'];
        }

        $this->log($eventType, $payload);

        $result = match ($eventType) {
            'issues'        => $this->handleIssueEvent($payload),
            'issue_comment' => $this->handleCommentEvent($payload),
            'push'          => $this->handlePushEvent($payload),
            'ping'          => ['ok' => true, 'action' => 'pong', 'handled' => true, 'detail' => 'webhook connected'],
            default         => ['ok' => true, 'action' => $eventType, 'handled' => false, 'detail' => 'event type not handled'],
        };

        return array_merge(['ok' => true], $result);
    }

    /** Returns recent webhook events (newest first). */
    public function recentEvents(int $limit = 20): array
    {
        $file = $this->basePath . '/' . self::WEBHOOK_LOG;
        if (!is_file($file)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($file)));
        $items = [];
        foreach ($lines as $line) {
            $d = json_decode($line, true);
            if (is_array($d)) {
                $items[] = $d;
            }
        }
        return array_slice(array_reverse($items), 0, $limit);
    }

    private function handleIssueEvent(array $payload): array
    {
        $action = $payload['action'] ?? '';
        $issue  = $payload['issue'] ?? [];

        if (!in_array($action, ['opened', 'reopened'], true)) {
            return ['action' => "issues.{$action}", 'handled' => false, 'detail' => 'ignored action'];
        }

        $title = strtolower((string)($issue['title'] ?? ''));
        $body  = strtolower((string)($issue['body'] ?? ''));
        $text  = $title . ' ' . $body;

        $matched = [];
        foreach (self::PAIN_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) {
                $matched[] = $kw;
            }
        }

        if (empty($matched)) {
            return ['action' => "issues.{$action}", 'handled' => true, 'detail' => 'no pain keywords matched'];
        }

        // Schedule a lead record to be created from this webhook
        $queueFile = $this->basePath . '/storage/evolution/github/webhook_leads.jsonl';
        $this->ensureDir(dirname($queueFile));
        $entry = [
            'external_id'  => 'gh_' . ($issue['id'] ?? uniqid()),
            'source'       => 'github_webhook',
            'title'        => $issue['title'] ?? '',
            'url'          => $issue['html_url'] ?? '',
            'author'       => $issue['user']['login'] ?? 'unknown',
            'author_url'   => 'https://github.com/' . ($issue['user']['login'] ?? ''),
            'body_snippet' => substr(strip_tags((string)($issue['body'] ?? '')), 0, 300),
            'keyword'      => implode(', ', $matched),
            'created_at'   => $issue['created_at'] ?? date('c'),
            'scouted_at'   => date('c'),
            'pitched'      => false,
            'pain_score'   => count($matched) * 15,
            'via_webhook'  => true,
        ];
        file_put_contents($queueFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

        return [
            'action'  => "issues.{$action}",
            'handled' => true,
            'detail'  => 'prospect queued, matched: ' . implode(', ', $matched),
        ];
    }

    private function handleCommentEvent(array $payload): array
    {
        $action  = $payload['action'] ?? '';
        $comment = $payload['comment'] ?? [];
        $issue   = $payload['issue'] ?? [];
        $labels  = array_column($issue['labels'] ?? [], 'name');

        if ($action !== 'created' || !in_array(self::PROSPECT_LABEL, $labels, true)) {
            return ['action' => "issue_comment.{$action}", 'handled' => false, 'detail' => 'not a prospect issue'];
        }

        // Log engagement on a prospect issue
        $engagementFile = $this->basePath . '/storage/evolution/github/engagement.jsonl';
        $this->ensureDir(dirname($engagementFile));
        $entry = [
            'issue_number' => $issue['number'] ?? 0,
            'issue_title'  => $issue['title'] ?? '',
            'commenter'    => $comment['user']['login'] ?? 'unknown',
            'comment_url'  => $comment['html_url'] ?? '',
            'snippet'      => substr(strip_tags((string)($comment['body'] ?? '')), 0, 150),
            'engaged_at'   => $comment['created_at'] ?? date('c'),
        ];
        file_put_contents($engagementFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

        return [
            'action'  => 'issue_comment.created',
            'handled' => true,
            'detail'  => 'engagement logged for prospect issue #' . ($issue['number'] ?? 0),
        ];
    }

    private function handlePushEvent(array $payload): array
    {
        $ref     = $payload['ref'] ?? '';
        $commits = count($payload['commits'] ?? []);
        return [
            'action'  => 'push',
            'handled' => true,
            'detail'  => "{$commits} commits pushed to {$ref}",
        ];
    }

    private function verifySignature(string $body, string $signature): bool
    {
        if ($this->webhookSecret === '') {
            return true; // no secret configured — allow (not recommended for production)
        }
        $expected = 'sha256=' . hash_hmac('sha256', $body, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }

    private function log(string $eventType, array $payload): void
    {
        $file = $this->basePath . '/' . self::WEBHOOK_LOG;
        $this->ensureDir(dirname($file));
        $entry = [
            'event'      => $eventType,
            'action'     => $payload['action'] ?? null,
            'repository' => $payload['repository']['full_name'] ?? null,
            'sender'     => $payload['sender']['login'] ?? null,
            'received_at'=> date('c'),
        ];
        file_put_contents($file, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    private function loadSecret(): string
    {
        $env = getenv('GITHUB_WEBHOOK_SECRET');
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (!defined('BASE_PATH')) {
            return '';
        }
        $file = BASE_PATH . '/src/config/evolution.json';
        if (!is_file($file)) {
            return '';
        }
        $data = json_decode((string) file_get_contents($file), true);
        return trim((string)(is_array($data) ? ($data['github']['webhook_secret'] ?? '') : ''));
    }
}
