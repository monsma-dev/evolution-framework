<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Maps agent_activity_log + evolution.log tail into Virtual Room JSON (positions + states).
 */
final class EvolutionVirtualRoomService
{
    private const GRID = 8;

    /** @var array<string, array{x:int,y:int}> */
    private const SPOTS = [
        'coffee'   => ['x' => 7, 'y' => 1],
        'desk'     => ['x' => 2, 'y' => 4],
        'server'   => ['x' => 1, 'y' => 6],
        'research' => ['x' => 0, 'y' => 2],
        'trading'  => ['x' => 7, 'y' => 6],
        'court'    => ['x' => 4, 'y' => 4],
    ];

    /** @var list<string> */
    private const CORE_ROLES = ['master', 'architect', 'junior', 'validator', 'strategist'];

    /**
     * @param array<int, array<string, mixed>> $latestRows from AgentActivityModel::latestActivityPerRole
     * @param array<int, array<string, mixed>> $bubbleRows from AgentActivityModel::recentBubbleFeed
     * @return array<string, mixed>
     */
    public static function buildPayload(array $latestRows, array $bubbleRows, array $logTailLines): array
    {
        $byRole = [];
        foreach ($latestRows as $row) {
            $r = (string)($row['agent_role'] ?? '');
            if ($r !== '') {
                $byRole[$r] = $row;
            }
        }

        $consensus = self::detectConsensus($latestRows, $bubbleRows);

        $agents = [];
        foreach (self::CORE_ROLES as $role) {
            $row = $byRole[$role] ?? null;
            $state = $row !== null ? self::deriveState($row) : 'idle';
            if ($consensus) {
                $state = 'consensus';
            }
            $spot = self::spotForState($state);
            if ($consensus) {
                $spot = self::courtJitter($role, $spot);
            }
            $msg  = $row !== null ? self::messageFromRow($row) : '';
            $agents[$role] = [
                'role'       => $role,
                'state'      => $state,
                'x'          => $spot['x'],
                'y'          => $spot['y'],
                'spot'       => $spot['name'],
                'bubble'     => $msg !== '' ? CavemanMessageFilter::lite($msg) : '',
                'updated_at' => $row !== null ? (string)($row['created_at'] ?? '') : null,
            ];
        }

        $bubbles = [];
        foreach ($bubbleRows as $row) {
            $txt = (string)($row['bubble_text'] ?? '');
            if ($txt === '') {
                continue;
            }
            $role = (string)($row['agent_role'] ?? '');
            if (!in_array($role, self::CORE_ROLES, true)) {
                continue;
            }
            $bubbles[] = [
                'role'    => $role,
                'text'    => CavemanMessageFilter::lite($txt),
                'id'      => (int)($row['id'] ?? 0),
                'created' => (string)($row['created_at'] ?? ''),
            ];
        }

        $payload = [
            'ok'           => true,
            'grid'         => self::GRID,
            'consensus'    => $consensus,
            'agents'       => $agents,
            'bubble_feed'  => array_slice($bubbles, 0, 6),
            'log_tail'     => $logTailLines,
            'generated_at' => gmdate('c'),
        ];

        self::mergeBreedingOverlay($payload);

        return $payload;
    }

    /**
     * BreedingGround writes room_overlay.json — show temporary bubble on the target avatar.
     *
     * @param array<string, mixed> $payload
     */
    private static function mergeBreedingOverlay(array &$payload): void
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $path = $base . '/data/evolution/shadow_agents/room_overlay.json';
        if (!is_readable($path)) {
            return;
        }
        $j = json_decode((string) file_get_contents($path), true);
        if (!is_array($j) || empty($j['active'])) {
            return;
        }
        $until = (string) ($j['until'] ?? '');
        if ($until !== '') {
            $t = strtotime($until);
            if ($t !== false && $t < time()) {
                return;
            }
        }
        $role   = strtolower((string) ($j['role'] ?? ''));
        $bubble = trim((string) ($j['bubble'] ?? ''));
        if ($role === '' || $bubble === '') {
            return;
        }
        if (!isset($payload['agents'][$role]) || !is_array($payload['agents'][$role])) {
            return;
        }
        /** @var array<string, mixed> $agent */
        $agent = $payload['agents'][$role];
        $agent['bubble'] = CavemanMessageFilter::lite($bubble);
        $agent['state']  = 'working';
        $payload['agents'][$role] = $agent;
        $payload['breeding'] = [
            'active' => true,
            'role'   => $role,
            'bubble' => $bubble,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $latestRows
     * @param array<int, array<string, mixed>> $bubbleRows
     */
    private static function detectConsensus(array $latestRows, array $bubbleRows): bool
    {
        foreach ($latestRows as $row) {
            $st = (string)($row['subject_type'] ?? '');
            if ($st === 'synergy_dialog' || $st === 'defrag_reflection') {
                return true;
            }
            $hay = mb_strtolower(
                (string)($row['event_type'] ?? '') . ' '
                . (string)($row['input_summary'] ?? '') . ' '
                . (string)($row['output_summary'] ?? '')
            );
            if (str_contains($hay, 'consensus') || str_contains($hay, 'courtroom') || str_contains($hay, 'vector memory')) {
                return true;
            }
        }
        foreach ($bubbleRows as $row) {
            $hay = mb_strtolower((string)($row['bubble_text'] ?? ''));
            if (str_contains($hay, 'consensus') || str_contains($hay, 'synergy')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function deriveState(array $row): string
    {
        $event = mb_strtolower((string)($row['event_type'] ?? ''));
        $subj  = mb_strtolower((string)($row['subject_type'] ?? ''));
        $in    = mb_strtolower((string)($row['input_summary'] ?? ''));
        $out   = mb_strtolower((string)($row['output_summary'] ?? ''));
        $blob  = $event . ' ' . $subj . ' ' . $in . ' ' . $out;

        if ($subj === 'synergy_dialog' || $subj === 'defrag_reflection') {
            return 'consensus';
        }

        if (
            str_contains($blob, 'trade')
            || str_contains($blob, 'kraken')
            || str_contains($blob, 'position')
            || str_contains($blob, 'strategist')
            || str_contains($blob, 'neural')
            || str_contains($blob, 'trading_nn')
        ) {
            return 'trading';
        }
        if (str_contains($blob, 'scout') || str_contains($blob, 'research') || str_contains($blob, 'github')) {
            return 'researching';
        }
        if (
            str_contains($blob, 'patch')
            || str_contains($blob, 'heal')
            || str_contains($blob, 'deploy')
            || str_contains($blob, 'apply')
            || str_contains($blob, 'fix')
            || str_contains($blob, 'compile')
        ) {
            return 'working';
        }

        return 'idle';
    }

    /**
     * @return array{x:int,y:int,name:string}
     */
    private static function spotForState(string $state): array
    {
        return match ($state) {
            'consensus' => [...self::SPOTS['court'], 'name' => 'court'],
            'trading' => [...self::SPOTS['trading'], 'name' => 'trading'],
            'researching' => [...self::SPOTS['research'], 'name' => 'research'],
            'working' => [...self::SPOTS['server'], 'name' => 'server'],
            default => [...self::SPOTS['coffee'], 'name' => 'coffee'],
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function messageFromRow(array $row): string
    {
        $out = trim((string)($row['output_summary'] ?? ''));
        $in  = trim((string)($row['input_summary'] ?? ''));

        return $out !== '' ? $out : $in;
    }

    /**
     * @param array{x:int,y:int,name:string} $spot
     * @return array{x:int,y:int,name:string}
     */
    private static function courtJitter(string $role, array $spot): array
    {
        $d = match ($role) {
            'master' => [0, 0],
            'architect' => [1, 0],
            'junior' => [-1, 0],
            'validator' => [0, 1],
            'strategist' => [1, 1],
            default => [0, 0],
        };
        $x = max(0, min(self::GRID - 1, $spot['x'] + $d[0]));
        $y = max(0, min(self::GRID - 1, $spot['y'] + $d[1]));

        return [...$spot, 'x' => $x, 'y' => $y];
    }

    /**
     * Last non-empty JSON lines from evolution.log (message field), for bubbles.
     *
     * @return list<array{raw:string,cave:string,channel?:string}>
     */
    public static function tailEvolutionLog(int $maxLines = 4): array
    {
        $path = EvolutionLogger::logPath();
        if (!is_readable($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
        $slice = array_slice($lines, -40);
        $out   = [];
        foreach (array_reverse($slice) as $line) {
            $j = json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $msg = trim((string)($j['message'] ?? ''));
            if ($msg === '') {
                continue;
            }
            $ch = (string)($j['channel'] ?? '');
            $out[] = [
                'raw'     => $msg,
                'cave'    => CavemanMessageFilter::lite($msg),
                'channel' => $ch,
            ];
            if (count($out) >= $maxLines) {
                break;
            }
        }

        return $out;
    }
}
