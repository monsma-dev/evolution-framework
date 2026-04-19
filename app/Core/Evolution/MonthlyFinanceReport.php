<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Generates a monthly Markdown finance report from the AiCreditMonitor ledger.
 *
 * Output: storage/app/reports/monthly_finance_report_{YYYY-MM}.md
 * Schedule: call generate() on the 1st of each month (via EvolutionWorker or cron).
 *
 * What it covers:
 *   - Total AI token spend vs. monthly cap (€20)
 *   - DeepSeek model breakdown
 *   - External tool costs (Tavily, SMTP, etc.)
 *   - Estimated savings vs. GPT-4o baseline
 *   - Eco-mode activation status
 *   - Daily spend bar chart (ASCII)
 */
final class MonthlyFinanceReport
{
    private const REPORT_DIR = 'storage/app/reports';

    public function __construct(
        private readonly AiCreditMonitor $monitor,
        private readonly Config $config
    ) {}

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Generate the monthly report for the given month (defaults to previous month on 1st, current otherwise).
     * Returns the absolute path to the written file.
     */
    public function generate(?string $month = null): string
    {
        $month = $month ?? $this->resolveTargetMonth();
        $breakdown = $this->monitor->getMonthlyBreakdown();

        // Override month key if generating for a specific month
        if ($month !== $breakdown['month']) {
            $breakdown['month'] = $month;
        }

        $md = $this->buildMarkdown($breakdown);
        $path = $this->reportPath($month);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($path, $md, LOCK_EX);

        EvolutionLogger::log('finance', 'monthly_report_generated', [
            'month' => $month,
            'path'  => $path,
            'total_eur' => $breakdown['total_eur'],
        ]);

        return $path;
    }

    /**
     * True if today is the 1st of the month (UTC) — used by scheduled workers.
     */
    public static function isReportDay(): bool
    {
        return gmdate('j') === '1';
    }

    /**
     * Returns the report path for a given month (YYYY-MM).
     */
    public function reportPath(string $month): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return $base . '/' . self::REPORT_DIR . '/monthly_finance_report_' . $month . '.md';
    }

    // ─── Markdown builder ────────────────────────────────────────────────────

    /**
     * @param array{month: string, total_eur: float, monthly_cap_eur: float, daily_rows: array, tools: array, model_breakdown: array, savings_vs_gpt4o_eur: float} $b
     */
    private function buildMarkdown(array $b): string
    {
        $month        = $b['month'];
        $total        = $b['total_eur'];
        $cap          = $b['monthly_cap_eur'];
        $remaining    = max(0.0, $cap - $total);
        $usedPct      = $cap > 0.0 ? min(100.0, round($total / $cap * 100, 1)) : 0.0;
        $ecoTriggered = $total >= $cap;
        $savings      = $b['savings_vs_gpt4o_eur'];
        $generated    = gmdate('Y-m-d H:i:s') . ' UTC';

        $lines = [];
        $lines[] = "# 📊 Monthly Finance Report — {$month}";
        $lines[] = '';
        $lines[] = "> Generated: {$generated}  ";
        $lines[] = '> Source: `storage/evolution/ai_credit_ledger.json`';
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## Summary';
        $lines[] = '';
        $lines[] = "| Metric | Value |";
        $lines[] = "|--------|-------|";
        $lines[] = "| Month | {$month} |";
        $lines[] = "| Total AI spend (est.) | **€" . number_format($total, 4) . "** |";
        $lines[] = "| Monthly cap | €" . number_format($cap, 2) . " |";
        $lines[] = "| Remaining | €" . number_format($remaining, 4) . " |";
        $lines[] = "| Used | {$usedPct}% |";
        $lines[] = "| Eco mode triggered | " . ($ecoTriggered ? '⚠️ YES — Ollama fallback active' : '✅ No') . " |";
        $lines[] = "| Saved vs. GPT-4o (7d est.) | **€" . number_format($savings, 4) . "** |";
        $lines[] = '';

        // Budget bar
        $barWidth = 30;
        $filled = (int)round($usedPct / 100 * $barWidth);
        $bar = str_repeat('█', $filled) . str_repeat('░', $barWidth - $filled);
        $lines[] = "**Budget usage:** `[{$bar}]` {$usedPct}%";
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';

        // Model breakdown
        $lines[] = '## AI Model Breakdown';
        $lines[] = '';
        if (empty($b['model_breakdown'])) {
            $lines[] = '_No model calls recorded this month._';
        } else {
            $lines[] = '| Model | Estimated Cost |';
            $lines[] = '|-------|---------------|';
            arsort($b['model_breakdown']);
            foreach ($b['model_breakdown'] as $model => $eur) {
                $lines[] = "| `{$model}` | €" . number_format((float)$eur, 6) . ' |';
            }
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';

        // External tools
        $lines[] = '## External Tools (Tavily, SMTP, etc.)';
        $lines[] = '';
        if (empty($b['tools'])) {
            $lines[] = '_No tool spend recorded this month._';
        } else {
            $lines[] = '| Tool | Cost (est.) | Credits | Calls |';
            $lines[] = '|------|------------|---------|-------|';
            foreach ($b['tools'] as $tool => $t) {
                $eur = number_format((float)($t['eur_est'] ?? 0.0), 6);
                $credits = (int)($t['credits'] ?? 0);
                $calls = (int)($t['calls'] ?? 0);
                $lines[] = "| {$tool} | €{$eur} | {$credits} | {$calls} |";
            }
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';

        // Daily chart
        $lines[] = '## Daily Spend (ASCII chart)';
        $lines[] = '';
        if (empty($b['daily_rows'])) {
            $lines[] = '_No data._';
        } else {
            $maxDay = max(array_column($b['daily_rows'], 'eur'));
            $chartWidth = 20;
            foreach ($b['daily_rows'] as $day => $row) {
                $dayEur = (float)$row['eur'];
                $barLen = $maxDay > 0 ? (int)round($dayEur / $maxDay * $chartWidth) : 0;
                $bar = str_pad(str_repeat('▓', $barLen), $chartWidth);
                $eurStr = number_format($dayEur, 4);
                $calls = (int)$row['calls'];
                $lines[] = "`{$day}` [{$bar}] €{$eurStr} ({$calls} calls)";
            }
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## Notes';
        $lines[] = '';
        $lines[] = '- All costs are **estimates** based on token count × model price; not a billing invoice.';
        $lines[] = '- DeepSeek V3: ~$0.14/1M input tokens | DeepSeek R1: ~$0.55/1M input tokens';
        $lines[] = '- Tavily: 1,000 free credits/month then $0.008/credit';
        $lines[] = '- Eco mode routes all tasks to local Ollama (deepseek-r1:1.5b) when monthly cap is reached.';
        $lines[] = '- Semantic cache hits cost €0.00 and are not reflected in this report.';

        return implode("\n", $lines) . "\n";
    }

    private function resolveTargetMonth(): string
    {
        // On the 1st: generate for the previous month
        if (gmdate('j') === '1') {
            return gmdate('Y-m', strtotime('first day of last month'));
        }
        return gmdate('Y-m');
    }
}
