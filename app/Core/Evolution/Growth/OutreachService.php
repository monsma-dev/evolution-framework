<?php

declare(strict_types=1);

namespace App\Core\Evolution\Growth;

/**
 * OutreachService — Orchestrator: Scout → Pitch → Guard → Queue.
 *
 * Full pipeline:
 *   1. LeadScout finds new leads
 *   2. LeadPitcher drafts proposals for high-pain leads
 *   3. ReputationGuard filters out spammy drafts
 *   4. Approved proposals wait for human confirmation
 */
final class OutreachService
{
    private LeadScout       $scout;
    private LeadPitcher     $pitcher;
    private ReputationGuard $guard;

    public function __construct(?string $basePath = null)
    {
        $this->scout   = new LeadScout($basePath);
        $this->pitcher = new LeadPitcher($basePath);
        $this->guard   = new ReputationGuard();
    }

    /**
     * Run one full outreach cycle.
     * Returns a summary of what happened.
     */
    public function run(int $maxLeads = 5, int $maxDraft = 10): array
    {
        $log = [];

        // 1. Scout
        $scoutResult = $this->scout->scout($maxLeads);
        $log['scouted'] = $scoutResult;

        // 2. Pitch new leads
        $recentLeads = $this->scout->recentLeads(100);
        $pitchResult = $this->pitcher->draftBatch($recentLeads, $maxDraft);
        $log['drafted'] = $pitchResult['drafted'];

        // 3. Guard: validate drafts
        $drafts   = $this->pitcher->byStatus('draft', 50);
        $approved = $this->guard->filterDrafts($drafts);
        $log['guard_approved'] = count($approved);
        $log['guard_rejected'] = count($drafts) - count($approved);

        // Approve passing proposals automatically
        foreach ($approved as $proposal) {
            $this->pitcher->approve($proposal['id']);
        }

        $log['run_at']   = date('c');
        $log['pipeline'] = 'scout→pitch→guard complete';

        return $log;
    }

    /** Dashboard snapshot. */
    public function dashboardStats(): array
    {
        return [
            'leads'     => $this->scout->count(),
            'proposals' => $this->pitcher->stats(),
            'guard'     => ['min_score' => 60],
        ];
    }

    public function scout(): LeadScout   { return $this->scout; }
    public function pitcher(): LeadPitcher { return $this->pitcher; }
    public function guard(): ReputationGuard { return $this->guard; }
}
