<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * SelfRepairService — monitors error logs for 500-level crashes and dispatches
 * an automatic fix attempt via the Architect + AutoApplyService pipeline.
 *
 * Flow:
 *   1. Read last N lines from evolution error log
 *   2. Extract unique errors not seen in the last hour
 *   3. Send to Architect as a "critical_autofix" severity prompt
 *   4. AutoApplyService applies the patch if Police-Agent approves
 *   5. Log result to ComplianceLogger + EvolutionLogger
 */
final class SelfRepairService
{
    private const MAX_LOG_LINES   = 80;
    private const DEDUPE_WINDOW_S = 3600;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, errors_found: int, patch_attempted: bool, result?: array<string,mixed>, error?: string}
     */
    public function runRepairCycle(): array
    {
        $config  = $this->container->get('config');
        $db      = $this->container->get('db');

        $recentErrors = $this->collectRecentErrors($config);

        if ($recentErrors === []) {
            return ['ok' => true, 'errors_found' => 0, 'patch_attempted' => false];
        }

        $errorSummary = implode("\n", array_slice($recentErrors, 0, 20));

        $systemPrompt = <<<PROMPT
You are the Evolution Self-Repair Agent. A 500-level error has been detected on the live server.
Analyze the error log below and produce a minimal patch to fix the root cause.
Return a JSON response in the standard Architect patch format with severity = "critical_autofix".
PROMPT;

        $messages = [
            ['role' => 'system',  'content' => $systemPrompt],
            ['role' => 'user',    'content' => "Error log excerpt (last hour):\n```\n{$errorSummary}\n```\nProvide a fix."],
        ];

        $manager = new SelfHealingManager($this->container);
        $result  = $manager->architectChat($messages, 'core', false, 30, [], false, '', null, 'critical_autofix');

        if (!($result['ok'] ?? false)) {
            EvolutionLogger::log('self_repair', 'architect_failed', ['error' => $result['error'] ?? 'unknown']);
            return ['ok' => false, 'errors_found' => count($recentErrors), 'patch_attempted' => true, 'error' => $result['error'] ?? 'Architect failed'];
        }

        $autoApply = new AutoApplyService($this->container);
        $applied   = $autoApply->processFromChatResult($result, 0);

        ComplianceLogger::log($db, 'SelfRepairService', 'SELF_REPAIR_ATTEMPTED',
            'Errors: ' . count($recentErrors) . ' — patches applied: ' . count($applied));

        EvolutionLogger::log('self_repair', 'cycle_complete', [
            'errors'  => count($recentErrors),
            'applied' => count($applied),
        ]);

        return [
            'ok'              => true,
            'errors_found'    => count($recentErrors),
            'patch_attempted' => true,
            'patches_applied' => count($applied),
            'result'          => $result,
        ];
    }

    /** @return list<string> */
    private function collectRecentErrors(mixed $config): array
    {
        $logDir = BASE_PATH . '/storage/evolution/logs';
        if (!is_dir($logDir)) {
            return [];
        }

        $files = glob($logDir . '/*.log') ?: [];
        rsort($files);

        $lines = [];
        foreach (array_slice($files, 0, 3) as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $fileLines = array_filter(
                explode("\n", $content),
                static fn (string $l) => str_contains($l, 'error') || str_contains($l, 'fatal') || str_contains($l, 'exception') || str_contains($l, '500')
            );
            array_push($lines, ...array_values($fileLines));
        }

        $cutoff = time() - self::DEDUPE_WINDOW_S;
        $recent = array_filter($lines, static function (string $line) use ($cutoff): bool {
            if (preg_match('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $line, $m)) {
                return strtotime($m[0]) >= $cutoff;
            }
            return true;
        });

        return array_values(array_unique(array_slice(array_values($recent), -self::MAX_LOG_LINES)));
    }
}
