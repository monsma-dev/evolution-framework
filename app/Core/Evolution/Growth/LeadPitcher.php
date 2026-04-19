<?php

declare(strict_types=1);

namespace App\Core\Evolution\Growth;

/**
 * LeadPitcher — Architect drafts technical proposals for leads.
 *
 * For each lead, the Pitcher:
 *   1. Analyses the pain point (cost / rate-limit / vendor-lock)
 *   2. Matches it to a known Evolution solution
 *   3. Drafts a short, non-spammy technical message
 *
 * Proposals stored in storage/evolution/growth/proposals.jsonl
 * Status flow: draft → approved → sent → converted
 */
final class LeadPitcher
{
    private const PROPOSALS_FILE = 'storage/evolution/growth/proposals.jsonl';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Draft a proposal for a lead. Returns the proposal array.
     */
    public function draft(array $lead): array
    {
        $painType = $this->detectPainType($lead);
        $solution = $this->matchSolution($painType, $lead);
        $message  = $this->composeMessage($lead, $painType, $solution);

        $proposal = [
            'id'          => hash('sha256', $lead['external_id'] . $lead['scouted_at']),
            'lead_id'     => $lead['external_id'],
            'lead_title'  => $lead['title'],
            'lead_url'    => $lead['url'],
            'lead_author' => $lead['author'],
            'source'      => $lead['source'],
            'pain_type'   => $painType,
            'solution_id' => $solution['id'],
            'solution'    => $solution['name'],
            'message'     => $message,
            'channel'     => $this->bestChannel($lead),
            'status'      => 'draft',
            'drafted_at'  => date('c'),
            'approved_at' => null,
            'sent_at'     => null,
            'pain_score'  => $lead['pain_score'] ?? 0,
        ];

        $this->saveProposal($proposal);
        return $proposal;
    }

    /**
     * Batch-draft proposals for un-pitched leads.
     * Returns how many were drafted.
     */
    public function draftBatch(array $leads, int $maxNew = 20): array
    {
        $drafted = 0;
        $existing = $this->proposalLeadIds();
        $newProposals = [];

        foreach ($leads as $lead) {
            if ($drafted >= $maxNew) {
                break;
            }
            if (($lead['pitched'] ?? false) || isset($existing[$lead['external_id']])) {
                continue;
            }
            if (($lead['pain_score'] ?? 0) < 20) {
                continue;
            }
            $proposal = $this->draft($lead);
            $newProposals[] = $proposal;
            $drafted++;
        }

        return ['drafted' => $drafted, 'proposals' => $newProposals];
    }

    /** Returns proposals filtered by status. */
    public function byStatus(string $status = 'draft', int $limit = 50): array
    {
        $all = $this->loadAll();
        $filtered = array_filter($all, static fn($p) => ($p['status'] ?? '') === $status);
        return array_slice(array_values($filtered), 0, $limit);
    }

    /** Approve a proposal (set status to approved). */
    public function approve(string $proposalId): bool
    {
        return $this->updateStatus($proposalId, 'approved', 'approved_at');
    }

    /** Mark as sent. */
    public function markSent(string $proposalId): bool
    {
        return $this->updateStatus($proposalId, 'sent', 'sent_at');
    }

    /** Mark as converted (lead visited / signed up). */
    public function markConverted(string $proposalId): bool
    {
        return $this->updateStatus($proposalId, 'converted', 'converted_at');
    }

    /** Stats for dashboard. */
    public function stats(): array
    {
        $all = $this->loadAll();
        $counts = ['draft' => 0, 'approved' => 0, 'sent' => 0, 'converted' => 0];
        foreach ($all as $p) {
            $s = $p['status'] ?? 'draft';
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }
        $sent      = $counts['sent'] + $counts['converted'];
        $converted = $counts['converted'];
        return [
            'total'           => count($all),
            'draft'           => $counts['draft'],
            'approved'        => $counts['approved'],
            'sent'            => $sent,
            'converted'       => $converted,
            'conversion_rate' => $sent > 0 ? round($converted / $sent * 100, 1) : 0.0,
        ];
    }

    private function detectPainType(array $lead): string
    {
        $text = strtolower(($lead['title'] ?? '') . ' ' . ($lead['body_snippet'] ?? ''));

        if (str_contains($text, 'rate limit') || str_contains($text, 'quota') || str_contains($text, '429')) {
            return 'rate_limit';
        }
        if (str_contains($text, 'cost') || str_contains($text, 'billing') || str_contains($text, 'expensive') || str_contains($text, '$')) {
            return 'cost';
        }
        if (str_contains($text, 'self-host') || str_contains($text, 'local') || str_contains($text, 'alternative')) {
            return 'vendor_lock';
        }
        if (str_contains($text, 'latency') || str_contains($text, 'slow') || str_contains($text, 'timeout')) {
            return 'latency';
        }
        return 'general';
    }

    private function matchSolution(string $painType, array $lead): array
    {
        $solutions = [
            'rate_limit' => [
                'id'     => 'local_first',
                'name'   => 'Local-First AI (Llama via Evolution)',
                'pitch'  => 'run inference locally — zero rate limits, zero per-token costs',
                'saving' => '~$200–$800/month typical for PHP apps',
            ],
            'cost' => [
                'id'     => 'cost_optimizer',
                'name'   => 'Evolution Economist + Local Llama',
                'pitch'  => 'route cheap tasks to local Llama, expensive tasks to cloud only when needed',
                'saving' => '80–95% cost reduction vs full cloud LLM',
            ],
            'vendor_lock' => [
                'id'     => 'sovereign_stack',
                'name'   => 'Sovereign AI Stack',
                'pitch'  => 'self-hosted framework, your data stays yours, migrate providers in minutes',
                'saving' => 'no per-seat license, no data lock-in',
            ],
            'latency' => [
                'id'     => 'vector_cache',
                'name'   => 'Vector Memory Cache',
                'pitch'  => 'semantic answer cache — identical queries answered in <5ms from local memory',
                'saving' => '10–50x faster for repeated patterns',
            ],
            'general' => [
                'id'     => 'evolution_framework',
                'name'   => 'Evolution AI Framework',
                'pitch'  => 'open-source PHP framework with local LLM, vector memory, and cost controls built-in',
                'saving' => 'full sovereignty over your AI stack',
            ],
        ];

        return $solutions[$painType] ?? $solutions['general'];
    }

    private function composeMessage(array $lead, string $painType, array $solution): string
    {
        $author  = $lead['author'] ?? 'there';
        $title   = $lead['title'] ?? 'your AI project';
        $saving  = $solution['saving'];
        $pitch   = $solution['pitch'];
        $solName = $solution['name'];

        return <<<MSG
Hi {$author},

Saw your issue: "{$title}" — I've been working on exactly this problem.

The short version: {$pitch} ({$saving}).

I built Evolution — an open-source PHP AI framework that handles this without vendor lock-in.
Worth a 5-min look: https://evolution-ai.dev/evolution

No sales pitch — just a technical solution that solved the same headache for me.

—
Built with Evolution AI Framework
MSG;
    }

    private function bestChannel(array $lead): string
    {
        return $lead['source'] === 'github' ? 'github_comment' : 'stackoverflow_answer';
    }

    private function saveProposal(array $proposal): void
    {
        $file = $this->basePath . '/' . self::PROPOSALS_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode($proposal) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function loadAll(): array
    {
        $file = $this->basePath . '/' . self::PROPOSALS_FILE;
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
        return $items;
    }

    private function proposalLeadIds(): array
    {
        $all = $this->loadAll();
        $ids = [];
        foreach ($all as $p) {
            if (isset($p['lead_id'])) {
                $ids[$p['lead_id']] = true;
            }
        }
        return $ids;
    }

    private function updateStatus(string $proposalId, string $status, string $tsField): bool
    {
        $file = $this->basePath . '/' . self::PROPOSALS_FILE;
        if (!is_file($file)) {
            return false;
        }
        $lines   = array_filter(explode("\n", (string) file_get_contents($file)));
        $updated = false;
        $out     = [];
        foreach ($lines as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && ($d['id'] ?? '') === $proposalId) {
                $d['status']   = $status;
                $d[$tsField]   = date('c');
                $updated = true;
            }
            $out[] = json_encode($d);
        }
        if ($updated) {
            file_put_contents($file, implode("\n", $out) . "\n", LOCK_EX);
        }
        return $updated;
    }
}
