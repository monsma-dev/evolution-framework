<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Budget-Guard: estimated € / token spend, daily caps, cheap-model tiering, per-user token budget.
 * Uses local JSON ledger (storage/evolution is gitignored). Not a billing API — operational guardrail only.
 */
final class AiCreditMonitor
{
    private const LEDGER_VERSION = 1;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array{user_id?: int, listing_id?: int} $ctx
     *
     * @return array{
     *   force_cheap: bool,
     *   reason: string|null,
     *   daily_spend_est_eur: float,
     *   daily_cap_eur: float,
     *   user_tokens_today: int,
     *   listing_tokens_today: int,
     *   warnings: list<string>,
     *   estimated_input_tokens: int,
     *   estimated_turn_eur_max: float
     * }
     */
    public function evaluateBeforeCall(
        string $mode,
        string $preferredModel,
        int $maxOutputTokens,
        string $systemPrompt,
        array $messages,
        array $ctx
    ): array {
        $bg = $this->budgetConfig();
        if ($bg === null) {
            $inTok = $this->estimateInputTokens($systemPrompt, $messages);

            return [
                'force_cheap' => false,
                'reason' => null,
                'daily_spend_est_eur' => 0.0,
                'daily_cap_eur' => 0.0,
                'user_tokens_today' => 0,
                'listing_tokens_today' => 0,
                'warnings' => [],
                'estimated_input_tokens' => $inTok,
                'estimated_turn_eur_max' => 0.0,
            ];
        }

        $inTok = $this->estimateInputTokens($systemPrompt, $messages);
        $eurMax = $this->estimateEurUpperBound($preferredModel, $inTok, $maxOutputTokens, $bg);

        $day = gmdate('Y-m-d');
        $ledger = $this->readLedger();
        $dayRow = $ledger['days'][$day] ?? ['total_eur_est' => 0.0, 'users' => [], 'listings' => []];
        $dailySpend = (float)($dayRow['total_eur_est'] ?? 0.0);

        $uid = (int)($ctx['user_id'] ?? 0);
        $lid = (int)($ctx['listing_id'] ?? 0);
        $userTok = $this->tokensTodayForKey($dayRow, 'u', $uid);
        $listTok = $lid > 0 ? $this->tokensTodayForKey($dayRow, 'l', $lid) : 0;

        $cap = (float)($bg['daily_budget_eur'] ?? 5.0);
        $warnRem = (float)($bg['warn_remaining_eur'] ?? 0.5);
        $perUser = (int)($bg['per_user_daily_token_budget'] ?? 200000);
        $perListing = (int)($bg['per_listing_daily_token_budget'] ?? 50000);

        $warnings = [];
        $forceCheap = false;
        $reason = null;

        $monthCap = (float) ($bg['monthly_budget_eur'] ?? 0.0);
        if ($monthCap > 0.0) {
            $monthSpend = $this->getMonthSpendEstimateEur();
            if ($monthSpend + $eurMax > $monthCap) {
                $forceCheap = true;
                $reason = ($reason !== null && $reason !== '' ? $reason . '; ' : '') . 'monthly_budget_eur';
                $warnings[] = 'Budget-Guard: maandlimiet (€' . number_format($monthCap, 2, '.', '')
                    . ') — geschat verbruikt €' . number_format($monthSpend, 2, '.', '') . '; schakel naar goedkoop model of stop.';
            } elseif ($monthSpend + $eurMax > $monthCap - 2.0) {
                $warnings[] = 'Budget-Guard: je nadert de maandlimiet (geschat resterend deze maand: €'
                    . number_format(max(0.0, $monthCap - $monthSpend - $eurMax), 2, '.', '') . ').';
            }
        }

        if ($cap > 0 && $dailySpend + $eurMax > $cap) {
            $forceCheap = true;
            $reason = 'daily_budget_eur: projected spend would exceed cap';
            $warnings[] = 'Budget-Guard: dagbudget (€' . number_format($cap, 2, '.', '') . ') — schakel over naar goedkoop model.';
        } elseif ($cap > 0 && $dailySpend + $eurMax > $cap - $warnRem) {
            $warnings[] = 'Budget-Guard: je nadert het dagbudget (geschat resterend: €' . number_format(max(0, $cap - $dailySpend - $eurMax), 2, '.', '') . ').';
        }

        if ($perUser > 0 && $uid > 0 && $userTok + $inTok + $maxOutputTokens > $perUser) {
            $forceCheap = true;
            $reason = ($reason ?? '') . '; per_user_daily_token_budget';
            $warnings[] = 'Budget-Guard: gebruikers-tokenbudget bijna bereikt — goedkope modus.';
        }

        if ($perListing > 0 && $lid > 0 && $listTok + $inTok + $maxOutputTokens > $perListing) {
            $forceCheap = true;
            $reason = ($reason ?? '') . '; per_listing_daily_token_budget';
            $warnings[] = 'Budget-Guard: listing-tokenbudget bijna bereikt — goedkope modus.';
        }

        if (!$forceCheap && EvolutionProfitabilityService::shouldForceCheapDueToRoi($this->config)) {
            $forceCheap = true;
            $reason = ($reason !== null && $reason !== '') ? $reason . '; profitability_roi_throttle' : 'profitability_roi_throttle';
            $warnings[] = 'Profitability: ROI onder drempel — geforceerd goedkoop model.';
        }

        return [
            'force_cheap' => $forceCheap,
            'reason' => $reason !== null && $reason !== '' ? trim($reason, '; ') : null,
            'daily_spend_est_eur' => round($dailySpend, 4),
            'daily_cap_eur' => $cap,
            'month_spend_est_eur' => round($this->getMonthSpendEstimateEur(), 4),
            'month_cap_eur' => $monthCap,
            'user_tokens_today' => $userTok,
            'listing_tokens_today' => $listTok,
            'warnings' => $warnings,
            'estimated_input_tokens' => $inTok,
            'estimated_turn_eur_max' => round($eurMax, 4),
        ];
    }

    /**
     * Sum estimated € for UTC calendar month from the local ledger (same keys as daily rows).
     */
    public function getMonthSpendEstimateEur(): float
    {
        $ledger = $this->readLedger();
        $days = $ledger['days'] ?? [];
        if (!is_array($days)) {
            return 0.0;
        }
        $prefix = gmdate('Y-m');
        $sum = 0.0;
        foreach ($days as $dayKey => $row) {
            if (!is_string($dayKey) || !str_starts_with($dayKey, $prefix)) {
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $sum += (float) ($row['total_eur_est'] ?? 0.0);
        }

        return round($sum, 6);
    }

    /**
     * Block expensive consensus / judge calls when monthly cap is exhausted (operational guardrail).
     *
     * @return array{ok: bool, reason?: string, month_spend_eur?: float, month_cap_eur?: float}
     */
    public function assertMonthBudgetHeadroomForPremiumCall(float $estimatedEurForCall): array
    {
        $bg = $this->budgetConfig();
        if ($bg === null) {
            return ['ok' => true];
        }
        $monthCap = (float) ($bg['monthly_budget_eur'] ?? 0.0);
        if ($monthCap <= 0.0) {
            return ['ok' => true];
        }
        $spent = $this->getMonthSpendEstimateEur();
        if ($spent + $estimatedEurForCall > $monthCap) {
            return [
                'ok' => false,
                'reason' => 'monthly_budget_exhausted',
                'month_spend_eur' => $spent,
                'month_cap_eur' => $monthCap,
            ];
        }

        return ['ok' => true, 'month_spend_eur' => $spent, 'month_cap_eur' => $monthCap];
    }

    /**
     * Record estimated spend after a completed call (best-effort).
     *
     * @param array{user_id?: int, listing_id?: int} $ctx
     */
    public function recordEstimatedTurn(
        string $model,
        int $inputTokens,
        int $outputTokensEstimate,
        array $ctx
    ): void {
        $bg = $this->budgetConfig();
        if ($bg === null) {
            return;
        }

        $eur = $this->estimateEurForTokens($model, $inputTokens, $outputTokensEstimate, $bg);
        $day = gmdate('Y-m-d');
        $ledger = $this->readLedger();
        if (!isset($ledger['days']) || !is_array($ledger['days'])) {
            $ledger['days'] = [];
        }
        if (!isset($ledger['days'][$day]) || !is_array($ledger['days'][$day])) {
            $ledger['days'][$day] = ['total_eur_est' => 0.0, 'users' => [], 'listings' => []];
        }
        $row = &$ledger['days'][$day];
        $row['total_eur_est'] = round(((float)($row['total_eur_est'] ?? 0)) + $eur, 6);

        $uid = (int)($ctx['user_id'] ?? 0);
        $lid = (int)($ctx['listing_id'] ?? 0);
        $tokAdd = $inputTokens + $outputTokensEstimate;
        if ($uid > 0) {
            $k = 'u' . $uid;
            $row['users'][$k] = (int)($row['users'][$k] ?? 0) + $tokAdd;
        }
        if ($lid > 0) {
            $k = 'l' . $lid;
            $row['listings'][$k] = (int)($row['listings'][$k] ?? 0) + $tokAdd;
        }

        $row['calls'][] = [
            't' => gmdate('c'),
            'model' => $model,
            'eur_est' => round($eur, 6),
            'in_tok' => $inputTokens,
            'out_tok_est' => $outputTokensEstimate,
        ];

        $ledger['version'] = self::LEDGER_VERSION;
        $this->writeLedger($ledger);
    }

    /**
     * True when this calendar month's estimated spend has reached or exceeded monthly_budget_eur.
     * Used by SovereignAiRouter to switch to eco/Ollama mode for the rest of the month.
     */
    public function isMonthlyBudgetExhausted(): bool
    {
        $bg = $this->budgetConfig();
        if ($bg === null) {
            return false;
        }
        $cap = (float)($bg['monthly_budget_eur'] ?? 0.0);
        if ($cap <= 0.0) {
            return false;
        }
        return $this->getMonthSpendEstimateEur() >= $cap;
    }

    /**
     * Record estimated € cost for an external tool call (Tavily search, SMTP, etc.).
     * Stored per-day under days[date]['tools'][tool_name].
     */
    public function recordToolSpend(string $tool, float $eurCost, int $credits = 0): void
    {
        if ($eurCost <= 0.0 && $credits <= 0) {
            return;
        }
        $day = gmdate('Y-m-d');
        $ledger = $this->readLedger();
        if (!isset($ledger['days']) || !is_array($ledger['days'])) {
            $ledger['days'] = [];
        }
        if (!isset($ledger['days'][$day]) || !is_array($ledger['days'][$day])) {
            $ledger['days'][$day] = ['total_eur_est' => 0.0, 'users' => [], 'listings' => []];
        }
        $row = &$ledger['days'][$day];
        $row['total_eur_est'] = round(((float)($row['total_eur_est'] ?? 0)) + $eurCost, 6);
        if (!isset($row['tools']) || !is_array($row['tools'])) {
            $row['tools'] = [];
        }
        if (!isset($row['tools'][$tool]) || !is_array($row['tools'][$tool])) {
            $row['tools'][$tool] = ['eur_est' => 0.0, 'credits' => 0, 'calls' => 0];
        }
        $row['tools'][$tool]['eur_est']  = round(((float)$row['tools'][$tool]['eur_est']) + $eurCost, 6);
        $row['tools'][$tool]['credits'] += $credits;
        $row['tools'][$tool]['calls']   += 1;

        $ledger['version'] = self::LEDGER_VERSION;
        $this->writeLedger($ledger);
    }

    /**
     * Full month breakdown for MonthlyFinanceReport.
     *
     * @return array{
     *   month: string,
     *   total_eur: float,
     *   monthly_cap_eur: float,
     *   daily_rows: array<string, array{eur: float, calls: int}>,
     *   tools: array<string, array{eur_est: float, credits: int, calls: int}>,
     *   model_breakdown: array<string, float>,
     *   savings_vs_gpt4o_eur: float
     * }
     */
    public function getMonthlyBreakdown(): array
    {
        $bg = $this->budgetConfig();
        $cap = is_array($bg) ? (float)($bg['monthly_budget_eur'] ?? 0.0) : 0.0;

        $ledger = $this->readLedger();
        $days = is_array($ledger['days'] ?? null) ? $ledger['days'] : [];
        $prefix = gmdate('Y-m');

        $totalEur = 0.0;
        $dailyRows = [];
        $tools = [];
        $modelEur = [];

        foreach ($days as $dayKey => $row) {
            if (!is_string($dayKey) || !str_starts_with($dayKey, $prefix) || !is_array($row)) {
                continue;
            }
            $dayEur = (float)($row['total_eur_est'] ?? 0.0);
            $totalEur += $dayEur;
            $calls = is_array($row['calls'] ?? null) ? $row['calls'] : [];
            $dailyRows[$dayKey] = ['eur' => round($dayEur, 6), 'calls' => count($calls)];

            // Tool spend aggregation
            if (is_array($row['tools'] ?? null)) {
                foreach ($row['tools'] as $toolName => $tData) {
                    if (!is_array($tData)) {
                        continue;
                    }
                    if (!isset($tools[$toolName])) {
                        $tools[$toolName] = ['eur_est' => 0.0, 'credits' => 0, 'calls' => 0];
                    }
                    $tools[$toolName]['eur_est']  += (float)($tData['eur_est'] ?? 0.0);
                    $tools[$toolName]['credits']  += (int)($tData['credits'] ?? 0);
                    $tools[$toolName]['calls']    += (int)($tData['calls'] ?? 0);
                }
            }

            // Model breakdown from calls
            foreach ($calls as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $m = (string)($c['model'] ?? 'unknown');
                $modelEur[$m] = round(((float)($modelEur[$m] ?? 0.0)) + (float)($c['eur_est'] ?? 0.0), 6);
            }
        }

        // Savings vs GPT-4o baseline
        $savings = $this->getTokenSavingsVsPremiumBaseline();

        return [
            'month'             => $prefix,
            'total_eur'         => round($totalEur, 4),
            'monthly_cap_eur'   => $cap,
            'daily_rows'        => $dailyRows,
            'tools'             => $tools,
            'model_breakdown'   => $modelEur,
            'savings_vs_gpt4o_eur' => $savings['saved_eur_est_7d'],
        ];
    }

    /**
     * Schatting besparing vs. hypothetisch alle calls met premium baseline (gpt-4o) — zelfde tokens.
     *
     * @return array{saved_eur_est_7d: float, calls_7d: int, baseline_model: string, label: string}
     */
    public function getTokenSavingsVsPremiumBaseline(): array
    {
        $bg = $this->budgetConfig();
        if ($bg === null) {
            return [
                'saved_eur_est_7d' => 0.0,
                'calls_7d' => 0,
                'baseline_model' => 'gpt-4o',
                'label' => 'Budget-Guard uit — geen savings-meting',
            ];
        }
        $baseline = 'gpt-4o';
        $ledger = $this->readLedger();
        $days = $ledger['days'] ?? [];
        if (!is_array($days)) {
            $days = [];
        }
        $saved = 0.0;
        $n = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
            $day = $days[$d] ?? [];
            $calls = $day['calls'] ?? [];
            if (!is_array($calls)) {
                continue;
            }
            foreach ($calls as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $in = (int) ($c['in_tok'] ?? 0);
                $out = (int) ($c['out_tok_est'] ?? 0);
                $actual = (float) ($c['eur_est'] ?? 0);
                if ($in + $out <= 0) {
                    continue;
                }
                $hyp = $this->estimateEurForTokens($baseline, $in, $out, $bg);
                $saved += max(0.0, $hyp - $actual);
                $n++;
            }
        }

        $saved = round($saved, 4);

        return [
            'saved_eur_est_7d' => $saved,
            'calls_7d' => $n,
            'baseline_model' => $baseline,
            'label' => '≈ €' . number_format($saved, 2, '.', '') . ' geschat bespaard vs. ' . $baseline . ' (7d, zelfde tokens)',
        ];
    }

    /**
     * Samenvatting ledger voor dashboards (EvolutionProfitabilityService e.d.).
     *
     * @return array{today_eur: float, last_7d_eur: float, calls_today: int}
     */
    public function getLedgerSpendSummary(): array
    {
        $ledger = $this->readLedger();
        $days = $ledger['days'] ?? [];
        if (!is_array($days)) {
            $days = [];
        }
        $today = gmdate('Y-m-d');
        $todayEur = (float) (($days[$today]['total_eur_est'] ?? 0));
        $sum7 = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $d = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
            $sum7 += (float) (($days[$d]['total_eur_est'] ?? 0));
        }
        $calls = $days[$today]['calls'] ?? [];
        $callsToday = is_array($calls) ? count($calls) : 0;

        return [
            'today_eur' => round($todayEur, 6),
            'last_7d_eur' => round($sum7, 6),
            'calls_today' => $callsToday,
        ];
    }

    /**
     * Extra prompt lines: semantic cache + Anthropic prompt caching hint.
     */
    public static function promptAppendBudgetHints(Config $config): string
    {
        $evo = $config->get('evolution', []);
        $bg = is_array($evo) ? ($evo['budget_guard'] ?? []) : [];
        if (!is_array($bg) || !filter_var($bg['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }

        $note = trim((string)($bg['semantic_cache_note'] ?? ''));
        $anth = trim((string)($bg['anthropic_prompt_caching_note'] ?? ''));

        $out = "\n\n--- Budget-Guard & cache moat ---\n";
        if ($note !== '') {
            $out .= $note . "\n";
        } else {
            $out .= "Prefer VectorCache / semantic_cache for repeated tax rules and similar prompts before calling paid LLMs.\n";
        }
        if ($anth !== '') {
            $out .= $anth . "\n";
        } else {
            $out .= "For Anthropic: reuse stable system blocks; use vendor Prompt Caching for repeated framework context where supported.\n";
        }

        return $out;
    }

    /**
     * Instructs the model to state live saldo using exact figures (Dutch OK in UI).
     *
     * @param array<string, mixed> $eval Output of evaluateBeforeCall()
     */
    public function formatSaldoInstructionForPrompt(Config $config, array $eval): string
    {
        $bg = $this->budgetConfig();
        if ($bg === null || !filter_var($bg['show_balance_in_chat'] ?? true, FILTER_VALIDATE_BOOL)) {
            return '';
        }
        $f = $this->computeSaldoFields($eval);
        if (($f['daily_cap_eur'] ?? 0) <= 0) {
            return '';
        }

        $cap = number_format((float)$f['daily_cap_eur'], 2, '.', '');
        $spent = number_format((float)$f['spent_today_est_eur'], 2, '.', '');
        $turn = number_format((float)$f['this_turn_max_est_eur'], 4, '.', '');
        $remBefore = $f['remaining_before_eur'];
        $remAfter = $f['remaining_after_turn_eur'];
        $rb = $remBefore !== null ? number_format($remBefore, 2, '.', '') : 'n/a';
        $ra = $remAfter !== null ? number_format($remAfter, 2, '.', '') : 'n/a';

        return <<<TXT


--- Live saldo (geschat, geen factuur) ---
Gebruik EXACT deze cijfers in je antwoord in één korte zin (Nederlands mag):
- Daglimiet: €{$cap}
- Vandaag al verbruikt (geschat): €{$spent}
- Deze chat-call max (upper bound): €{$turn}
- Resterend vóór deze call: €{$rb}
- Resterend na deze call (geschat): €{$ra}
Noem ook dat Specialist-claude (duurder) alleen bij Apply Patch / second opinion wordt gebruikt, niet in deze chat.
TXT;
    }

    /**
     * @param array<string, mixed> $eval
     *
     * @return array{
     *   daily_cap_eur: float,
     *   spent_today_est_eur: float,
     *   this_turn_max_est_eur: float,
     *   remaining_before_eur: float|null,
     *   remaining_after_turn_eur: float|null
     * }
     */
    public function computeSaldoFields(array $eval): array
    {
        $cap = (float)($eval['daily_cap_eur'] ?? 0.0);
        $spent = (float)($eval['daily_spend_est_eur'] ?? 0.0);
        $turn = (float)($eval['estimated_turn_eur_max'] ?? 0.0);
        if ($cap <= 0) {
            return [
                'daily_cap_eur' => 0.0,
                'spent_today_est_eur' => $spent,
                'this_turn_max_est_eur' => $turn,
                'remaining_before_eur' => null,
                'remaining_after_turn_eur' => null,
            ];
        }

        $remBefore = max(0.0, $cap - $spent);
        $remAfter = max(0.0, $cap - $spent - $turn);

        return [
            'daily_cap_eur' => $cap,
            'spent_today_est_eur' => $spent,
            'this_turn_max_est_eur' => $turn,
            'remaining_before_eur' => $remBefore,
            'remaining_after_turn_eur' => $remAfter,
        ];
    }

    /**
     * @param array<string, mixed> $rawJson Architect raw_json decoded
     *
     * @return array<string, mixed>|null
     */
    public function analyzePatchTokenRisk(array $rawJson, Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $bg = is_array($evo) ? ($evo['budget_guard'] ?? []) : [];
        if (!is_array($bg) || !filter_var($bg['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $threshold = max(1000, (int)($bg['patch_token_warning_threshold'] ?? 8000));
        $charsWarn = max(5000, (int)($bg['patch_chars_warn_per_file'] ?? 24000));

        $changes = $rawJson['suggested_changes'] ?? [];
        if (!is_array($changes)) {
            return null;
        }

        $totalChars = 0;
        $maxFile = 0;
        foreach ($changes as $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $php = (string)($ch['full_file_php'] ?? '');
            $len = strlen($php);
            $totalChars += $len;
            if ($len > $maxFile) {
                $maxFile = $len;
            }
        }

        if ($totalChars === 0) {
            return null;
        }

        $estTok = (int)ceil($totalChars / 4);
        $warn = $estTok >= $threshold || $maxFile >= $charsWarn;

        if (!$warn) {
            return null;
        }

        return [
            'index' => 1,
            'severity' => $estTok >= $threshold * 2 ? 'high' : 'medium',
            'estimated_patch_tokens' => $estTok,
            'largest_file_chars' => $maxFile,
            'message' => 'De voorgestelde patch(es) zijn groot (geschat ~' . $estTok
                . ' tokens voor apply/warmup). Overweeg kleinere stappen of een goedkoper model voor iteratie.',
        ];
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function estimateInputTokens(string $systemPrompt, array $messages): int
    {
        $n = strlen($systemPrompt);
        foreach ($messages as $m) {
            if (isset($m['content']) && is_string($m['content'])) {
                $n += strlen($m['content']);
            }
        }

        return max(1, (int)ceil($n / 4));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function budgetConfig(): ?array
    {
        $evo = $this->config->get('evolution', []);
        if (!is_array($evo)) {
            return null;
        }
        $bg = $evo['budget_guard'] ?? [];
        if (!is_array($bg) || !filter_var($bg['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        return $bg;
    }

    /**
     * @param array<string, mixed> $bg
     */
    private function estimateEurUpperBound(string $model, int $inTok, int $maxOut, array $bg): float
    {
        return $this->estimateEurForTokens($model, $inTok, $maxOut, $bg);
    }

    /**
     * @param array<string, mixed> $bg
     */
    private function estimateEurForTokens(string $model, int $inTok, int $outTok, array $bg): float
    {
        $inM = $inTok / 1_000_000;
        $outM = $outTok / 1_000_000;
        $inMap = $bg['input_price_per_million_tokens_eur'] ?? [];
        $outMap = $bg['output_price_per_million_tokens_eur'] ?? [];
        if (!is_array($inMap)) {
            $inMap = [];
        }
        if (!is_array($outMap)) {
            $outMap = [];
        }
        $pin = (float)($inMap[$model] ?? $inMap['default'] ?? 0.5);
        $pout = (float)($outMap[$model] ?? $outMap['default'] ?? 1.5);

        return $inM * $pin + $outM * $pout;
    }

    /**
     * @param array<string, mixed> $dayRow
     */
    private function tokensTodayForKey(array $dayRow, string $prefix, int $id): int
    {
        if ($id < 1) {
            return 0;
        }
        $bucket = $prefix === 'u' ? ($dayRow['users'] ?? []) : ($dayRow['listings'] ?? []);
        if (!is_array($bucket)) {
            return 0;
        }
        $k = $prefix . $id;

        return (int)($bucket[$k] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function readLedger(): array
    {
        $path = $this->ledgerPath();
        if (!is_file($path)) {
            return ['version' => self::LEDGER_VERSION, 'days' => []];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['version' => self::LEDGER_VERSION, 'days' => []];
        }
        $j = json_decode($raw, true);

        return is_array($j) ? $j : ['version' => self::LEDGER_VERSION, 'days' => []];
    }

    /**
     * @param array<string, mixed> $ledger
     */
    private function writeLedger(array $ledger): void
    {
        $path = $this->ledgerPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }
        @file_put_contents($path, $json, LOCK_EX);
    }

    private function ledgerPath(): string
    {
        $bg = $this->budgetConfig();
        $rel = is_array($bg) ? trim((string)($bg['ledger_path'] ?? 'storage/evolution/ai_credit_ledger.json')) : 'storage/evolution/ai_credit_ledger.json';
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);

        return $base . '/' . ltrim($rel, '/');
    }
}
