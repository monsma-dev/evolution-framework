<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Evolution\Growth\AffiliateService;
use App\Core\Evolution\Growth\LeadPitcher;
use App\Core\Evolution\Growth\LeadScout;
use App\Core\Evolution\Growth\OutreachService;
use App\Core\Evolution\Growth\ReputationGuard;

/**
 * evolve:lead-scout — Growth & Outreach Pipeline
 *
 * Usage:
 *   php ai_bridge.php evolve:lead-scout run
 *   php ai_bridge.php evolve:lead-scout scout [--max=5]
 *   php ai_bridge.php evolve:lead-scout leads [--limit=20]
 *   php ai_bridge.php evolve:lead-scout proposals [--status=draft]
 *   php ai_bridge.php evolve:lead-scout approve <proposal_id>
 *   php ai_bridge.php evolve:lead-scout stats
 *   php ai_bridge.php evolve:lead-scout affiliate:create <name> [email] [channel]
 *   php ai_bridge.php evolve:lead-scout affiliate:list
 */
final class EvolutionLeadScoutCommand
{
    private OutreachService  $outreach;
    private AffiliateService $affiliate;

    public function __construct()
    {
        $this->outreach  = new OutreachService();
        $this->affiliate = new AffiliateService();
    }

    public function execute(array $args): int
    {
        $sub = $args[0] ?? 'stats';

        return match (true) {
            $sub === 'run'              => $this->run($args),
            $sub === 'scout'            => $this->scout($args),
            $sub === 'leads'            => $this->leads($args),
            $sub === 'proposals'        => $this->proposals($args),
            $sub === 'approve'          => $this->approve($args[1] ?? ''),
            $sub === 'stats'            => $this->stats(),
            $sub === 'affiliate:create' => $this->affiliateCreate($args),
            $sub === 'affiliate:list'   => $this->affiliateList(),
            default                     => $this->usage(),
        };
    }

    private function run(array $args): int
    {
        $max = (int) $this->flag($args, 'max', '5');
        echo "Running full outreach pipeline (scout→pitch→guard)...\n\n";

        $result = $this->outreach->run($max);

        echo "Scout  : " . ($result['scouted']['new'] ?? 0) . " new leads found"
            . " (" . ($result['scouted']['leads_found'] ?? 0) . " total from sources)\n";
        echo "Pitch  : " . ($result['drafted'] ?? 0) . " proposals drafted\n";
        echo "Guard  : " . ($result['guard_approved'] ?? 0) . " approved, "
            . ($result['guard_rejected'] ?? 0) . " rejected\n";
        echo "Status : {$result['pipeline']}\n\n";
        echo "View proposals: php ai_bridge.php evolve:lead-scout proposals --status=approved\n";
        return 0;
    }

    private function scout(array $args): int
    {
        $max = (int) $this->flag($args, 'max', '5');
        echo "Scouting GitHub & StackOverflow (max {$max} per keyword)...\n";

        $scout = $this->outreach->scout();
        $result = $scout->scout($max);

        echo "\n✓ Scout complete\n";
        echo "  New leads  : {$result['new']}\n";
        echo "  Total found: {$result['leads_found']}\n";
        echo "  Sources    : " . implode(', ', $result['sources']) . "\n";
        echo "  Scanned at : {$result['scanned_at']}\n\n";
        echo "Next: php ai_bridge.php evolve:lead-scout run\n";
        return 0;
    }

    private function leads(array $args): int
    {
        $limit = (int) $this->flag($args, 'limit', '20');
        $scout = $this->outreach->scout();
        $leads = $scout->recentLeads($limit);

        if ($leads === []) {
            echo "No leads yet. Run: php ai_bridge.php evolve:lead-scout scout\n";
            return 0;
        }

        echo "=== Recent Leads (" . count($leads) . ") ===\n\n";
        foreach ($leads as $lead) {
            $pain = $lead['pain_score'] ?? 0;
            $bar  = str_repeat('█', (int)($pain / 10)) . str_repeat('░', 10 - (int)($pain / 10));
            echo "[{$bar}] {$pain}/100  {$lead['source']}: {$lead['title']}\n";
            echo "  Author  : {$lead['author']}  ({$lead['author_url']})\n";
            echo "  URL     : {$lead['url']}\n";
            echo "  Keyword : {$lead['keyword']}\n\n";
        }
        return 0;
    }

    private function proposals(array $args): int
    {
        $status = $this->flag($args, 'status', 'draft');
        $pitcher = $this->outreach->pitcher();
        $proposals = $pitcher->byStatus($status, 30);

        if ($proposals === []) {
            echo "No {$status} proposals. Run: php ai_bridge.php evolve:lead-scout run\n";
            return 0;
        }

        echo "=== Proposals (status={$status}) ===\n\n";
        foreach ($proposals as $p) {
            echo "ID     : {$p['id']}\n";
            echo "Lead   : {$p['lead_title']}\n";
            echo "Author : {$p['lead_author']}  ({$p['lead_url']})\n";
            echo "Pain   : {$p['pain_score']}/100  Solution: {$p['solution']}\n";
            echo "Channel: {$p['channel']}\n";
            echo "---\n{$p['message']}\n";
            echo str_repeat('─', 60) . "\n\n";
        }
        return 0;
    }

    private function approve(string $proposalId): int
    {
        if ($proposalId === '') {
            echo "Usage: evolve:lead-scout approve <proposal_id>\n";
            return 1;
        }
        $pitcher = $this->outreach->pitcher();
        $guard   = $this->outreach->guard();
        $drafts  = $pitcher->byStatus('draft', 100);
        $target  = null;
        foreach ($drafts as $d) {
            if (str_starts_with($d['id'], $proposalId)) {
                $target = $d;
                break;
            }
        }
        if ($target === null) {
            echo "Proposal not found or not in draft status.\n";
            return 1;
        }
        $check = $guard->validate($target);
        echo "Guard score: {$check['score']}/100\n";
        echo "Verdict: {$check['verdict']}\n";
        if ($check['issues']) {
            foreach ($check['issues'] as $issue) {
                echo "  ⚠ {$issue}\n";
            }
        }
        if (!$check['ok']) {
            echo "\nMessage did not pass guard. Edit manually or it was auto-approved above 60.\n";
        }
        $pitcher->approve($target['id']);
        echo "\n✓ Approved: {$target['id']}\n";
        return 0;
    }

    private function stats(): int
    {
        $stats     = $this->outreach->dashboardStats();
        $affStats  = $this->affiliate->stats();
        $propStats = $stats['proposals'];

        echo "=== Growth & Outreach Stats ===\n\n";
        echo "LEADS\n";
        echo "  Total found    : {$stats['leads']}\n\n";
        echo "PROPOSALS\n";
        echo "  Total          : {$propStats['total']}\n";
        echo "  Draft          : {$propStats['draft']}\n";
        echo "  Approved       : {$propStats['approved']}\n";
        echo "  Sent           : {$propStats['sent']}\n";
        echo "  Converted      : {$propStats['converted']}\n";
        echo "  Conversion rate: {$propStats['conversion_rate']}%\n\n";
        echo "AFFILIATES\n";
        echo "  Total          : {$affStats['total_affiliates']}\n";
        echo "  Total visits   : {$affStats['total_visits']}\n";
        echo "  Signups        : {$affStats['total_signups']}\n";
        echo "  Licenses       : {$affStats['total_licenses']}\n";
        echo "  Conversion     : {$affStats['conversion_rate']}%\n";
        return 0;
    }

    private function affiliateCreate(array $args): int
    {
        $name    = $args[1] ?? '';
        $email   = $args[2] ?? '';
        $channel = $args[3] ?? 'general';
        if ($name === '') {
            echo "Usage: evolve:lead-scout affiliate:create <name> [email] [channel]\n";
            return 1;
        }
        $aff = $this->affiliate->create($name, $email, $channel);
        $url = $this->affiliate->referralUrl($aff['code']);
        echo "Affiliate created!\n";
        echo "  Name   : {$aff['name']}\n";
        echo "  Code   : {$aff['code']}\n";
        echo "  URL    : {$url}\n";
        echo "  Points : 0\n\n";
        echo "Reward structure:\n";
        echo "  Visit   → +1 point\n";
        echo "  Signup  → +25 points\n";
        echo "  License → +100 points\n";
        return 0;
    }

    private function affiliateList(): int
    {
        $board = $this->affiliate->leaderboard(20);
        if ($board === []) {
            echo "No affiliates yet. Use: evolve:lead-scout affiliate:create <name>\n";
            return 0;
        }
        echo str_pad('Code', 18) . str_pad('Name', 20) . str_pad('Points', 10)
            . str_pad('Visits', 10) . str_pad('Signups', 10) . "Licenses\n";
        echo str_repeat('─', 75) . "\n";
        foreach ($board as $a) {
            echo str_pad($a['code'], 18)
                . str_pad($a['name'], 20)
                . str_pad((string)($a['points'] ?? 0), 10)
                . str_pad((string)($a['visits'] ?? 0), 10)
                . str_pad((string)($a['signups'] ?? 0), 10)
                . ($a['licenses'] ?? 0) . "\n";
        }
        return 0;
    }

    private function flag(array $args, string $name, string $default): string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return substr($arg, strlen("--{$name}="));
            }
        }
        return $default;
    }

    private function usage(): int
    {
        echo <<<USAGE
evolve:lead-scout — Growth & Outreach Pipeline

Subcommands:
  run [--max=5]                   Full pipeline: scout+pitch+guard
  scout [--max=5]                 Scout GitHub/StackOverflow for leads
  leads [--limit=20]              Show recent leads with pain scores
  proposals [--status=draft]      Show proposals (draft/approved/sent)
  approve <proposal_id>           Approve a specific proposal
  stats                           Dashboard stats
  affiliate:create <name> [email] Create affiliate with referral code
  affiliate:list                  Show leaderboard

USAGE;
        return 0;
    }
}
