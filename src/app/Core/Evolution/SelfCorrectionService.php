<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * Self-Correction Loop: when an auto-apply fails (lint error, policy violation,
 * visual regression), sends the failure reason back to the AI model for an
 * immediate corrected attempt. Max 2 retries per change.
 */
final class SelfCorrectionService
{
    private const MAX_RETRIES = 2;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Attempt to get a corrected version from the AI after a failed auto-apply.
     *
     * @param array{type: string, target: string, severity: string, error?: string|null, visual_regression?: bool} $failedEntry
     * @param array<string, mixed> $originalChange the original suggested_change or suggested_frontend item
     * @return array{ok: bool, corrected_result?: array<string, mixed>, retries: int, error?: string}
     */
    public function attemptCorrection(array $failedEntry, array $originalChange, int $actorUserId): array
    {
        $target = (string)($failedEntry['target'] ?? '');
        $type = (string)($failedEntry['type'] ?? 'php');
        $error = (string)($failedEntry['error'] ?? 'unknown error');
        $isVisualRegression = (bool)($failedEntry['visual_regression'] ?? false);

        $history = LearningLoopService::historyForTarget($target, 3);
        $recentFailures = count(array_filter($history, fn(array $h) => !($h['ok'] ?? true)));
        if ($recentFailures >= self::MAX_RETRIES) {
            return ['ok' => false, 'retries' => 0, 'error' => "Max retries ({$recentFailures}) reached for {$target}"];
        }

        $correctionPrompt = $this->buildCorrectionPrompt($type, $target, $error, $isVisualRegression, $originalChange);

        $manager = new SelfHealingManager($this->container);
        $config = $this->container->get('config');
        $health = (new HealthSnapshotService())->snapshot($this->container);
        $result = $manager->architectChat(
            [['role' => 'user', 'content' => $correctionPrompt]],
            'core',
            false,
            1,
            ['user_id' => $actorUserId, 'listing_id' => 0],
            false,
            '',
            $health
        );

        if (!($result['ok'] ?? false)) {
            EvolutionLogger::log('self_correction', 'ai_call_failed', [
                'target' => $target,
                'error' => $result['error'] ?? 'unknown',
            ]);

            return ['ok' => false, 'retries' => 1, 'error' => $result['error'] ?? 'Correction AI call failed'];
        }

        $autoApply = new AutoApplyService($this->container);
        try {
            $applied = $autoApply->processFromChatResult($result, $actorUserId);
        } catch (EvolutionFatalException $fatal) {
            EvolutionLogger::log('self_correction', 'evolution_fatal', ['message' => $fatal->getMessage()]);

            return [
                'ok' => false,
                'retries' => 1,
                'error' => $fatal->getMessage(),
                'snapshot_restore' => $fatal->getSnapshotRestore(),
            ];
        }

        $success = false;
        foreach ($applied as $a) {
            if (($a['target'] ?? '') === $target && ($a['ok'] ?? false)) {
                $success = true;
                break;
            }
        }

        EvolutionLogger::log('self_correction', $success ? 'corrected' : 'correction_failed', [
            'target' => $target,
            'type' => $type,
            'original_error' => $error,
            'applied' => $applied,
        ]);

        return [
            'ok' => $success,
            'corrected_result' => $applied,
            'retries' => 1,
        ];
    }

    private function buildCorrectionPrompt(string $type, string $target, string $error, bool $isVisualRegression, array $originalChange): string
    {
        $parts = [
            'SELF-CORRECTION REQUEST — Your previous auto-apply attempt failed. Generate an immediate corrected version.',
            '',
            'Failed target: ' . $target,
            'Type: ' . $type,
            'Error: ' . $error,
        ];

        if ($isVisualRegression) {
            $parts[] = '';
            $parts[] = 'VISUAL REGRESSION DETECTED: Your CSS/Twig change altered too much of the page layout.';
            $parts[] = 'Fix: Use a more specific CSS selector (class or ID, not tag), reduce scope of change.';
        }

        if ($type === 'php') {
            $fqcn = $target;
            $parts[] = '';
            $parts[] = 'Original FQCN: ' . $fqcn;
            if (isset($originalChange['rationale'])) {
                $parts[] = 'Original rationale: ' . $originalChange['rationale'];
            }
            $parts[] = '';
            $parts[] = 'Instructions: Provide a corrected full_file_php that fixes the error. Keep the same severity. Use the same FQCN.';
        } elseif ($type === 'css') {
            $parts[] = '';
            $parts[] = 'Original CSS was too broad or caused regression.';
            $parts[] = 'Instructions: Provide a corrected append_css with more specific selectors. Keep severity ui_autofix.';
        } elseif ($type === 'twig') {
            $parts[] = '';
            $parts[] = 'Original template: ' . $target;
            $parts[] = 'Instructions: Provide a corrected full_template. Keep severity ui_autofix.';
        }

        $parts[] = '';
        $parts[] = 'Respond with JSON per your system instructions. This is attempt 2 — be more conservative.';

        return implode("\n", $parts);
    }
}
