<?php

declare(strict_types=1);

namespace App\Core\Evolution\GitHub;

use App\Core\Evolution\Growth\AffiliateService;

/**
 * GitHubAffiliatePublisher — Publishes the affiliate leaderboard to a GitHub Gist.
 *
 * The Gist becomes the "Source of Truth" that developers can view publicly.
 * Gist ID is persisted to evolution.json github.affiliate.leaderboard_gist_id after creation.
 *
 * Output format: Markdown table, suitable for embedding in a README badge.
 */
final class GitHubAffiliatePublisher
{
    private const GIST_FILENAME = 'evolution-affiliate-leaderboard.md';
    private const CONFIG_FILE   = 'storage/evolution/github/gist_config.json';

    private EvolutionGitHubService $github;
    private AffiliateService       $affiliates;
    private string $basePath;

    public function __construct(?EvolutionGitHubService $github = null, ?string $basePath = null)
    {
        $this->github     = $github ?? new EvolutionGitHubService();
        $this->affiliates = new AffiliateService($basePath);
        $this->basePath   = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Publish or update the leaderboard Gist.
     * Creates on first run, updates on subsequent runs.
     * Returns ['ok'=>bool, 'gist_url'=>string, 'gist_id'=>string].
     */
    public function publish(): array
    {
        if (!$this->github->isConfigured()) {
            return ['ok' => false, 'error' => 'GitHub not configured'];
        }

        $content = $this->buildMarkdown();
        $gistId  = $this->loadGistId();

        try {
            if ($gistId !== '') {
                $result = $this->github->updateGist($gistId, self::GIST_FILENAME, $content);
            } else {
                $result = $this->github->createGist(
                    self::GIST_FILENAME,
                    $content,
                    'Evolution AI Framework — Affiliate Sovereign Leaderboard',
                    true
                );
                if (is_array($result) && isset($result['id'])) {
                    $this->saveGistId($result['id']);
                    $gistId = $result['id'];
                }
            }

            return [
                'ok'       => true,
                'gist_id'  => $gistId,
                'gist_url' => $result['html_url'] ?? "https://gist.github.com/{$gistId}",
                'lines'    => substr_count($content, "\n"),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Returns the rendered markdown that would be published. */
    public function preview(): string
    {
        return $this->buildMarkdown();
    }

    /** Returns the Gist URL if already created. */
    public function gistUrl(): string
    {
        $id = $this->loadGistId();
        return $id !== '' ? "https://gist.github.com/{$id}" : '';
    }

    private function buildMarkdown(): string
    {
        $board = $this->affiliates->leaderboard(20);
        $stats = $this->affiliates->stats();

        $lines = [];
        $lines[] = '# 🏆 Evolution AI — Sovereign Affiliate Leaderboard';
        $lines[] = '';
        $lines[] = '> Developers who spread the word about **Evolution Framework** earn Sovereignty Points.';
        $lines[] = '> Points are awarded for referral visits, signups, and activated licenses.';
        $lines[] = '';
        $lines[] = "**Updated:** " . date('Y-m-d H:i') . ' UTC';
        $lines[] = '';
        $lines[] = '## Global Stats';
        $lines[] = '';
        $lines[] = "| Metric | Value |";
        $lines[] = "|---|---|";
        $lines[] = "| Total Affiliates | {$stats['total_affiliates']} |";
        $lines[] = "| Total Visits | {$stats['total_visits']} |";
        $lines[] = "| Signups | {$stats['total_signups']} |";
        $lines[] = "| Licenses Activated | {$stats['total_licenses']} |";
        $lines[] = "| Conversion Rate | {$stats['conversion_rate']}% |";
        $lines[] = '';
        $lines[] = '## Leaderboard';
        $lines[] = '';

        if ($board === []) {
            $lines[] = '*No affiliates yet. Be the first to spread sovereignty.*';
        } else {
            $lines[] = '| # | Name | Points | Visits | Signups | Licenses |';
            $lines[] = '|---|---|---|---|---|---|';
            foreach ($board as $i => $a) {
                $medal = match ($i) {
                    0       => '🥇',
                    1       => '🥈',
                    2       => '🥉',
                    default => (string)($i + 1),
                };
                $lines[] = "| {$medal} | {$a['name']} | **{$a['points']}** | {$a['visits']} | {$a['signups']} | {$a['licenses']} |";
            }
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## Reward Structure';
        $lines[] = '';
        $lines[] = '| Event | Points |';
        $lines[] = '|---|---|';
        $lines[] = '| Referral visit | +1 |';
        $lines[] = '| New signup | +25 |';
        $lines[] = '| License activated | +100 |';
        $lines[] = '';
        $lines[] = '## Join as Affiliate';
        $lines[] = '';
        $lines[] = 'Contact us via the [Evolution AI portal](https://evolution-ai.dev/evolution) to get your referral code.';
        $lines[] = '';
        $lines[] = '*Generated by Evolution AI Framework — Sovereign Growth Engine*';

        return implode("\n", $lines) . "\n";
    }

    private function loadGistId(): string
    {
        // Check storage first
        $file = $this->basePath . '/' . self::CONFIG_FILE;
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && !empty($data['gist_id'])) {
                return (string) $data['gist_id'];
            }
        }
        // Fallback to evolution.json
        if (defined('BASE_PATH')) {
            $cfg = BASE_PATH . '/config/evolution.json';
            if (is_file($cfg)) {
                $data = json_decode((string) file_get_contents($cfg), true);
                return (string)(is_array($data) ? ($data['github']['affiliate']['leaderboard_gist_id'] ?? '') : '');
            }
        }
        return '';
    }

    private function saveGistId(string $id): void
    {
        $file = $this->basePath . '/' . self::CONFIG_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode(['gist_id' => $id, 'created_at' => date('c')], JSON_PRETTY_PRINT), LOCK_EX);
    }
}
