<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Injects "Cost-Guard" rules into Architect / vision / consensus prompts to limit AWS/cloud runaway spend.
 */
final class CostGuard
{
    /**
     * Appended to system prompts when evolution.cost_guard.enabled is true (default on).
     */
    public static function promptAppend(Config $config): string
    {
        $evo = $config->get('evolution', []);
        $cg = is_array($evo) ? ($evo['cost_guard'] ?? []) : [];
        if (is_array($cg) && !filter_var($cg['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $base = <<<'TXT'


--- Cost-Guard (cloud spend & AWS safety) ---
You must NOT propose designs or code that unnecessarily increase AWS or vendor bills.
- Avoid: unbounded loops over S3 (ListObjects/scanning huge prefixes), full DynamoDB/S3 table dumps, loading entire SQL tables into memory, disabling caches, or per-request calls to paid APIs (OpenAI embeddings, etc.) for every row.
- Prefer: pagination, batching, idempotent workers, SQS batch send/receive, Lambda reserved concurrency only when justified, RDS indexes and query limits, existing cache layers.
- Do not suggest new always-on compute, cross-region replication, or extra NAT/VPC data paths unless the user explicitly asks for scale-out.
- UI: avoid giant unoptimized images or assets that force huge S3 egress; keep CSS/Twig changes lightweight.
- Never embed or generate AWS access keys; use the project’s JSON config patterns.
- If a change could materially increase cost, call it out in "risks" or the summary and suggest a cheaper alternative.
TXT;

        $extra = '';
        if (is_array($cg)) {
            $e = trim((string)($cg['extra_rules'] ?? ''));
            if ($e !== '') {
                $extra = "\n\nAdditional Cost-Guard rules from config:\n" . $e;
            }
        }

        return $base . $extra;
    }

    /**
     * Prepended to a single user/review message (consensus, architecture ask).
     */
    public static function messagePrefix(Config $config): string
    {
        $block = self::promptAppend($config);

        return $block === '' ? '' : trim($block) . "\n\n";
    }
}
