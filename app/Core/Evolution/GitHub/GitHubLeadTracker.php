<?php

declare(strict_types=1);

namespace App\Core\Evolution\GitHub;

use App\Core\Evolution\Growth\LeadScout;
use App\Core\Evolution\Growth\ReputationGuard;

/**
 * GitHubLeadTracker — Tracks GitHub issues as prospects and posts technical replies.
 *
 * Workflow:
 *   1. LeadScout finds a GitHub issue
 *   2. Tracker labels it with 'evolution-ai-prospect'
 *   3. When a proposal is approved, it posts the message as a GitHub comment
 *   4. Tracks which issues have been commented on to avoid duplicates
 *
 * Comment log: storage/evolution/github/comment_log.jsonl
 * Cooldown enforced: evolution.json github.lead_scout.comment_cooldown_hours (default 72h)
 */
final class GitHubLeadTracker
{
    private const COMMENT_LOG = 'storage/evolution/github/comment_log.jsonl';

    private EvolutionGitHubService $github;
    private ReputationGuard        $guard;
    private string $basePath;
    private string $trackLabel;
    private bool   $autoComment;
    private int    $cooldownHours;

    public function __construct(?EvolutionGitHubService $github = null, ?string $basePath = null)
    {
        $this->github  = $github ?? new EvolutionGitHubService();
        $this->guard   = new ReputationGuard();
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));

        $cfg = $this->loadConfig();
        $this->autoComment   = (bool)($cfg['auto_comment'] ?? false);
        $this->cooldownHours = (int)($cfg['comment_cooldown_hours'] ?? 72);
        $this->trackLabel    = (string)($cfg['track_label'] ?? 'evolution-ai-prospect');
    }

    /**
     * Set up the tracking label in the repo if it doesn't exist.
     */
    public function ensureTrackingLabel(): array
    {
        if (!$this->github->isConfigured()) {
            return ['ok' => false, 'error' => 'GitHub not configured'];
        }
        try {
            $label = $this->github->ensureLabel(
                $this->trackLabel,
                'E8532C',
                'Evolution AI identified this as a potential prospect'
            );
            return ['ok' => true, 'label' => $label['name'] ?? $this->trackLabel];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tag a GitHub issue as a prospect.
     * Lead must have external_id like 'gh_123456' and a URL containing /issues/42.
     */
    public function tagProspect(array $lead): array
    {
        if (!$this->github->isConfigured()) {
            return ['ok' => false, 'error' => 'GitHub not configured'];
        }

        $issueNumber = $this->extractIssueNumber($lead['url'] ?? '');
        if ($issueNumber === 0) {
            return ['ok' => false, 'error' => 'Could not extract issue number from URL'];
        }

        // Only tag issues from the configured repo
        if (!str_contains($lead['url'] ?? '', $this->github->getOwner() . '/' . $this->github->repoName())) {
            return ['ok' => false, 'error' => 'Issue is not from the tracked repository'];
        }

        try {
            $this->github->addIssueLabels($issueNumber, [$this->trackLabel]);
            return ['ok' => true, 'issue' => $issueNumber, 'label' => $this->trackLabel];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Post an approved proposal as a GitHub comment on the source issue.
     * Enforces cooldown and ReputationGuard validation.
     */
    public function postProposalComment(array $proposal): array
    {
        if (!$this->github->isConfigured()) {
            return ['ok' => false, 'error' => 'GitHub not configured'];
        }

        $url = $proposal['lead_url'] ?? '';
        $issueNumber = $this->extractIssueNumber($url);
        if ($issueNumber === 0) {
            return ['ok' => false, 'error' => 'Not a GitHub issue URL'];
        }

        if ($this->alreadyCommented($issueNumber)) {
            return ['ok' => false, 'error' => "Already commented on issue #{$issueNumber} (cooldown active)"];
        }

        // Re-validate with guard
        $guardResult = $this->guard->validate($proposal);
        if (!$guardResult['ok']) {
            return ['ok' => false, 'error' => 'Guard rejected: ' . $guardResult['verdict']];
        }

        try {
            $comment = $this->github->addIssueComment($issueNumber, $proposal['message']);
            $this->logComment($issueNumber, $proposal['id'], $comment['id'] ?? 0);
            return [
                'ok'          => true,
                'issue'       => $issueNumber,
                'comment_id'  => $comment['id'] ?? 0,
                'comment_url' => $comment['html_url'] ?? '',
                'guard_score' => $guardResult['score'],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch issues with the tracking label — active prospects.
     */
    public function listProspects(int $limit = 20): array
    {
        if (!$this->github->isConfigured()) {
            return [];
        }
        try {
            $issues = $this->github->listIssues('open', $limit, $this->trackLabel);
            return array_map(static fn($i) => [
                'number'      => $i['number'],
                'title'       => $i['title'],
                'url'         => $i['html_url'],
                'author'      => $i['user']['login'] ?? 'unknown',
                'author_url'  => 'https://github.com/' . ($i['user']['login'] ?? ''),
                'created_at'  => $i['created_at'],
                'comments'    => $i['comments'],
                'state'       => $i['state'],
            ], $issues);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Returns comment log entries (newest first). */
    public function commentLog(int $limit = 30): array
    {
        $file = $this->basePath . '/' . self::COMMENT_LOG;
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

    private function alreadyCommented(int $issueNumber): bool
    {
        $file = $this->basePath . '/' . self::COMMENT_LOG;
        if (!is_file($file)) {
            return false;
        }
        $cutoff = time() - ($this->cooldownHours * 3600);
        $lines  = array_filter(explode("\n", (string) file_get_contents($file)));
        foreach ($lines as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && ($d['issue_number'] ?? 0) === $issueNumber) {
                $ts = strtotime((string)($d['commented_at'] ?? ''));
                if ($ts !== false && $ts > $cutoff) {
                    return true;
                }
            }
        }
        return false;
    }

    private function logComment(int $issueNumber, string $proposalId, int $commentId): void
    {
        $file = $this->basePath . '/' . self::COMMENT_LOG;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $entry = [
            'issue_number' => $issueNumber,
            'proposal_id'  => $proposalId,
            'comment_id'   => $commentId,
            'commented_at' => date('c'),
        ];
        file_put_contents($file, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function extractIssueNumber(string $url): int
    {
        if (preg_match('|/issues/(\d+)|', $url, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function loadConfig(): array
    {
        if (!defined('BASE_PATH')) {
            return [];
        }
        $file = BASE_PATH . '/config/evolution.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? ($data['github']['lead_scout'] ?? []) : [];
    }
}
