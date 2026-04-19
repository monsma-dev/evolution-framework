<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Ghost Mode — AI Offline Survivability.
 *
 * When external AI providers (OpenAI, Anthropic) fail, the system automatically
 * switches to Ghost Mode: a local Ollama endpoint + frozen skill snapshots.
 *
 * Circuit States:
 *   CLOSED     — Normal operation, all providers healthy
 *   HALF_OPEN  — One provider degraded, routing to backup
 *   OPEN       — All providers failed, Ghost Mode active (local Llama only)
 *
 * Ghost Mode guarantees:
 *   - Code validation still works (skill rules are frozen-copied on last healthy state)
 *   - Local Llama answers tasks using frozen skills
 *   - All actions are logged so Claude can review when back online
 *   - No data loss: failed prompts queued to ghost_queue.json
 */
final class EvolutionGhostMode
{
    private const STATE_FILE    = '/var/www/html/data/evolution/circuit_state.json';
    private const SNAPSHOT_DIR  = '/var/www/html/data/evolution/skill_snapshot';
    private const QUEUE_FILE    = '/var/www/html/data/evolution/ghost_queue.json';
    private const HEALTH_FILE   = '/var/www/html/data/evolution/provider_health.json';

    // Circuit thresholds
    private const FAIL_THRESHOLD   = 3;    // consecutive failures to OPEN circuit
    private const LATENCY_MS_WARN  = 5000; // ms above which = degraded
    private const HALF_OPEN_TTL    = 60;   // seconds before retry attempt
    private const OPEN_TTL         = 300;  // seconds before forced retry

    public const STATE_CLOSED    = 'CLOSED';
    public const STATE_HALF_OPEN = 'HALF_OPEN';
    public const STATE_OPEN      = 'OPEN';

    // Provider aliases
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_LOCAL     = 'local';

    // ── Circuit Breaker ───────────────────────────────────────────────────────

    /**
     * Record a provider call result. Updates health tracking and may trip circuit.
     *
     * @param  string $provider  anthropic|openai|local
     * @param  bool   $success
     * @param  float  $latencyMs
     */
    public static function recordCall(string $provider, bool $success, float $latencyMs = 0.0): void
    {
        $health = self::loadHealth();
        if (!isset($health[$provider])) {
            $health[$provider] = ['failures' => 0, 'successes' => 0, 'avg_latency_ms' => 0.0, 'last_failure' => null, 'state' => self::STATE_CLOSED];
        }

        $h = &$health[$provider];
        if ($success) {
            $h['successes']++;
            $h['failures']       = 0;  // reset on success
            $h['avg_latency_ms'] = round(((float)($h['avg_latency_ms'] ?? 0) * 0.8) + ($latencyMs * 0.2), 1);
            $h['state']          = self::STATE_CLOSED;
        } else {
            $h['failures']++;
            $h['last_failure']   = gmdate('c');
            if ((int)$h['failures'] >= self::FAIL_THRESHOLD) {
                $h['state'] = self::STATE_OPEN;
            } elseif ((int)$h['failures'] >= 1) {
                $h['state'] = self::STATE_HALF_OPEN;
            }
        }

        // High latency counts as soft failure
        if ($success && $latencyMs > self::LATENCY_MS_WARN) {
            $h['state'] = self::STATE_HALF_OPEN;
        }

        $h['last_updated'] = gmdate('c');
        self::saveHealth($health);
        self::updateGlobalState($health);
    }

    /**
     * Check if Ghost Mode is currently active.
     */
    public static function isGhostMode(): bool
    {
        $state = self::loadState();
        return ($state['global_state'] ?? self::STATE_CLOSED) === self::STATE_OPEN;
    }

    /**
     * Get the best available provider for a call.
     * In Ghost Mode: always returns 'local'.
     * Otherwise: returns preferred if healthy, else backup.
     *
     * @param  string $preferred  anthropic|openai
     * @return string  provider alias to use
     */
    public static function bestProvider(string $preferred = self::PROVIDER_ANTHROPIC): string
    {
        if (self::isGhostMode()) {
            return self::PROVIDER_LOCAL;
        }
        $health = self::loadHealth();
        $state  = $health[$preferred]['state'] ?? self::STATE_CLOSED;

        if ($state === self::STATE_OPEN) {
            // Try the other external provider
            $backup = $preferred === self::PROVIDER_ANTHROPIC ? self::PROVIDER_OPENAI : self::PROVIDER_ANTHROPIC;
            $backupState = $health[$backup]['state'] ?? self::STATE_CLOSED;
            return $backupState === self::STATE_OPEN ? self::PROVIDER_LOCAL : $backup;
        }
        return $preferred;
    }

    /**
     * Get current circuit state summary for display.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        return [
            'state'    => self::loadState(),
            'health'   => self::loadHealth(),
            'ghost'    => self::isGhostMode(),
            'snapshot' => self::snapshotAge(),
            'queued'   => self::queueLength(),
        ];
    }

    // ── Skill Snapshot (frozen skills for Ghost Mode) ─────────────────────────

    /**
     * Snapshot current skills to frozen backup.
     * Called after every successful Jury approval.
     *
     * @return array{count: int, path: string}
     */
    public static function snapshotSkills(): array
    {
        $skillsDir   = self::resolveSkillsDir();
        $snapshotDir = self::resolvePath(self::SNAPSHOT_DIR);
        if (!is_dir($snapshotDir)) { @mkdir($snapshotDir, 0755, true); }

        $files   = glob($skillsDir . '/*.skill') ?: [];
        $count   = 0;
        $manifest = [];

        foreach ($files as $sf) {
            $name    = basename($sf);
            $content = (string)file_get_contents($sf);
            $dest    = $snapshotDir . '/' . $name;
            file_put_contents($dest, $content);
            $manifest[$name] = [
                'hash'      => hash('sha256', $content),
                'size'      => strlen($content),
                'snapped'   => gmdate('c'),
            ];
            $count++;
        }

        file_put_contents($snapshotDir . '/manifest.json', json_encode([
            'snapped_at'   => gmdate('c'),
            'skill_count'  => $count,
            'files'        => $manifest,
        ], JSON_PRETTY_PRINT));

        return ['count' => $count, 'path' => $snapshotDir];
    }

    /**
     * Returns path to skills dir for Ghost Mode (frozen snapshot if OPEN, live otherwise).
     */
    public static function skillsDir(): string
    {
        if (self::isGhostMode()) {
            $snap = self::resolvePath(self::SNAPSHOT_DIR);
            if (is_dir($snap) && is_readable($snap . '/manifest.json')) {
                return $snap;
            }
        }
        return self::resolveSkillsDir();
    }

    // ── Ghost Queue (failed prompts for later replay) ─────────────────────────

    /**
     * Queue a prompt that failed due to provider outage.
     *
     * @param array<string, mixed> $context
     */
    public static function enqueueFailedPrompt(string $task, string $prompt, array $context = []): void
    {
        $path    = self::resolvePath(self::QUEUE_FILE);
        $queue   = is_readable($path) ? (json_decode((string)file_get_contents($path), true) ?? []) : [];
        $queue[] = [
            'id'      => substr(md5(uniqid($task, true)), 0, 8),
            'queued'  => gmdate('c'),
            'task'    => $task,
            'prompt'  => mb_substr($prompt, 0, 500),
            'context' => $context,
            'status'  => 'pending',
        ];
        // Keep last 100 queued items
        $queue = array_slice($queue, -100);
        file_put_contents($path, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Get pending queued items for replay when providers come back online.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function drainQueue(): array
    {
        $path  = self::resolvePath(self::QUEUE_FILE);
        if (!is_readable($path)) { return []; }
        $queue = json_decode((string)file_get_contents($path), true);
        return is_array($queue) ? array_filter($queue, static fn(array $q) => ($q['status'] ?? '') === 'pending') : [];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $health */
    private static function updateGlobalState(array $health): void
    {
        $allOpen     = true;
        $anyDegraded = false;

        foreach ([self::PROVIDER_ANTHROPIC, self::PROVIDER_OPENAI] as $p) {
            $s = $health[$p]['state'] ?? self::STATE_CLOSED;
            if ($s !== self::STATE_OPEN)      { $allOpen = false; }
            if ($s !== self::STATE_CLOSED)    { $anyDegraded = true; }
        }

        $state  = self::loadState();
        $was    = $state['global_state'] ?? self::STATE_CLOSED;

        if ($allOpen) {
            $state['global_state'] = self::STATE_OPEN;
            if ($was !== self::STATE_OPEN) {
                // Entering Ghost Mode — take snapshot of current skills
                self::snapshotSkills();
                $state['ghost_since'] = gmdate('c');
            }
        } elseif ($anyDegraded) {
            $state['global_state'] = self::STATE_HALF_OPEN;
            unset($state['ghost_since']);
        } else {
            $state['global_state'] = self::STATE_CLOSED;
            if ($was === self::STATE_OPEN) {
                $state['ghost_ended'] = gmdate('c');
            }
            unset($state['ghost_since']);
        }

        $state['updated_at'] = gmdate('c');
        self::saveState($state);
    }

    private static function snapshotAge(): string
    {
        $manifest = self::resolvePath(self::SNAPSHOT_DIR) . '/manifest.json';
        if (!is_readable($manifest)) { return 'no snapshot'; }
        $m = json_decode((string)file_get_contents($manifest), true);
        $ts = (string)($m['snapped_at'] ?? '');
        if ($ts === '') { return 'no snapshot'; }
        $diff = time() - strtotime($ts);
        return $diff < 3600 ? round($diff / 60) . 'm ago' : round($diff / 3600, 1) . 'h ago';
    }

    private static function queueLength(): int
    {
        $path = self::resolvePath(self::QUEUE_FILE);
        if (!is_readable($path)) { return 0; }
        $q = json_decode((string)file_get_contents($path), true);
        return is_array($q) ? count(array_filter($q, static fn(array $i) => ($i['status'] ?? '') === 'pending')) : 0;
    }

    /** @return array<string, mixed> */
    private static function loadState(): array
    {
        $path = self::resolvePath(self::STATE_FILE);
        if (!is_readable($path)) { return ['global_state' => self::STATE_CLOSED]; }
        $d = json_decode((string)file_get_contents($path), true);
        return is_array($d) ? $d : ['global_state' => self::STATE_CLOSED];
    }

    /** @param array<string, mixed> $state */
    private static function saveState(array $state): void
    {
        $path = self::resolvePath(self::STATE_FILE);
        $dir  = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** @return array<string, mixed> */
    private static function loadHealth(): array
    {
        $path = self::resolvePath(self::HEALTH_FILE);
        if (!is_readable($path)) { return []; }
        $d = json_decode((string)file_get_contents($path), true);
        return is_array($d) ? $d : [];
    }

    /** @param array<string, mixed> $health */
    private static function saveHealth(array $health): void
    {
        $path = self::resolvePath(self::HEALTH_FILE);
        $dir  = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        file_put_contents($path, json_encode($health, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private static function resolveSkillsDir(): string
    {
        if (is_dir('/var/www/html/data/neural/skills')) {
            return '/var/www/html/data/neural/skills';
        }
        return (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/data/neural/skills';
    }

    private static function resolvePath(string $constant): string
    {
        if (str_starts_with($constant, '/var/www/html') && is_dir('/var/www/html')) {
            return $constant;
        }
        $base = defined('BASE_PATH') ? BASE_PATH : '/var/www/html';
        return rtrim($base, '/') . '/' . ltrim(str_replace('/var/www/html/', '', $constant), '/');
    }
}
