<?php

declare(strict_types=1);

namespace App\Core\Evolution\Systems;

use App\Core\Evolution\EvolutionLogger;
use App\Core\Evolution\Intelligence\Models\TradingPredictor;

/**
 * Credit-cheap agent "spawning": mutate system_prompt copies under storage/evolution/shadow_agents/,
 * score with Strategist ({@see TradingPredictor}) + brevity; promote child if ≥10% better than parent.
 */
final class BreedingGround
{
    private const SHADOW_DIR = 'storage/evolution/shadow_agents';

    private const ROOM_OVERLAY = 'storage/evolution/shadow_agents/room_overlay.json';

    private const STATE_FILE = 'storage/evolution/shadow_agents/breeding_state.json';

    /** @var list<string> */
    private const MUTATION_SUFFIXES = [
        'Ultra-concise: max 80 tokens internal reasoning.',
        'Caveman-only for status lines; zero greetings.',
        'Prefer yield/staking language over day-trade hype.',
        'Mobile UI first; no desktop-only assumptions.',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @return array{ok: bool, role: string, path?: string, fitness?: float, error?: string}
     */
    public function mutateFromProduction(string $role, int $generation = 1): array
    {
        $role = strtolower(trim($role));
        $agentsPath = $this->basePath . '/config/agents.json';
        if (!is_readable($agentsPath)) {
            return ['ok' => false, 'role' => $role, 'error' => 'agents.json not readable'];
        }

        $raw = json_decode((string) file_get_contents($agentsPath), true);
        if (!is_array($raw) || !isset($raw['agents'][$role])) {
            return ['ok' => false, 'role' => $role, 'error' => "Unknown role: {$role}"];
        }

        $agent     = (array) $raw['agents'][$role];
        $parentSp  = (string) ($agent['system_prompt'] ?? '');
        $suffix    = self::MUTATION_SUFFIXES[($generation + crc32($role)) % count(self::MUTATION_SUFFIXES)];
        $childSp   = $parentSp . "\n\n[Breed gen {$generation}] " . $suffix;

        $child        = $agent;
        $child['role'] = $role;
        $child['system_prompt'] = $childSp;

        $this->ensureShadowDir();
        $candidatePath = $this->shadowPath("{$role}_candidate.json");
        $enc           = json_encode($child, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($enc === false || file_put_contents($candidatePath, $enc) === false) {
            return ['ok' => false, 'role' => $role, 'error' => 'Failed to write candidate shadow file'];
        }

        $parentParts = $this->fitnessParts($parentSp);
        $childParts  = $this->fitnessParts($childSp);
        $parentFit   = $parentParts['combined'];
        $childFit    = $childParts['combined'];

        $this->writeState([
            'generation'       => $generation,
            'role'             => $role,
            'parent_fitness'   => $parentFit,
            'child_fitness'    => $childFit,
            'parent_neural'    => $parentParts['neural'],
            'child_neural'     => $childParts['neural'],
            'parent_brevity'   => $parentParts['brevity'],
            'child_brevity'    => $childParts['brevity'],
            'last_mutate_at'   => gmdate('c'),
            'candidate_path'   => $candidatePath,
        ]);

        $this->writeRoomOverlay($role);
        EvolutionLogger::log('breeding', 'mutate', [
            'role' => $role,
            'parent_fitness' => $parentFit,
            'child_fitness'  => $childFit,
            'parent_neural'  => $parentParts['neural'],
            'child_neural'   => $childParts['neural'],
        ]);

        return [
            'ok'      => true,
            'role'    => $role,
            'path'    => $candidatePath,
            'fitness' => $childFit,
        ];
    }

    /**
     * If child fitness ≥ parent × (1 + threshold), promote candidate to shadow champion.
     *
     * @return array{ok: bool, promoted: bool, parent: float, child: float, reason: string}
     */
    public function runSelection(float $threshold = 0.10): array
    {
        $state = $this->readState();
        if ($state === null) {
            return ['ok' => false, 'promoted' => false, 'parent' => 0.0, 'child' => 0.0, 'reason' => 'No breeding state — run mutate first'];
        }

        $role         = (string) ($state['role'] ?? '');
        $parentFit    = (float) ($state['parent_fitness'] ?? 0.0);
        $childFit     = (float) ($state['child_fitness'] ?? 0.0);
        $parentNeural = (float) ($state['parent_neural'] ?? 0.0);
        $childNeural  = (float) ($state['child_neural'] ?? 0.0);
        $gen          = (int) ($state['generation'] ?? 1);

        if ($role === '') {
            return ['ok' => false, 'promoted' => false, 'parent' => $parentFit, 'child' => $childFit, 'reason' => 'Invalid state'];
        }

        $needCombo  = $parentFit * (1.0 + $threshold);
        $needNeural = $parentNeural * (1.0 + $threshold);
        $promoteCombo  = $childFit >= $needCombo;
        $promoteNeural = $childNeural >= $needNeural && $parentNeural > 0.0;

        if (!$promoteCombo && !$promoteNeural) {
            EvolutionLogger::log('breeding', 'select_no_promo', [
                'role' => $role,
                'parent' => $parentFit,
                'child' => $childFit,
                'need_combo' => $needCombo,
                'parent_neural' => $parentNeural,
                'child_neural' => $childNeural,
                'need_neural' => $needNeural,
            ]);

            return [
                'ok'       => true,
                'promoted' => false,
                'parent'   => $parentFit,
                'child'    => $childFit,
                'reason'   => sprintf(
                    'No promotion: combined need %.4f (child %.4f); neural need %.4f (child %.4f vs parent %.4f).',
                    $needCombo,
                    $childFit,
                    $needNeural,
                    $childNeural,
                    $parentNeural
                ),
            ];
        }

        $candidate = $this->shadowPath("{$role}_candidate.json");
        $champion  = $this->shadowPath("{$role}_shadow.json");
        if (!is_readable($candidate)) {
            return ['ok' => false, 'promoted' => false, 'parent' => $parentFit, 'child' => $childFit, 'reason' => 'candidate file missing'];
        }

        if (!copy($candidate, $champion)) {
            return ['ok' => false, 'promoted' => false, 'parent' => $parentFit, 'child' => $childFit, 'reason' => 'copy failed'];
        }

        $this->writeState(array_merge($state, [
            'promoted_at'    => gmdate('c'),
            'promoted_gen'   => $gen,
            'champion_path'  => $champion,
            'promoted_via'   => $promoteNeural && !$promoteCombo ? 'neural' : 'combined',
        ]));

        $this->writeRoomOverlay($role);
        EvolutionLogger::log('breeding', 'promote', ['role' => $role, 'champion' => $champion]);

        return [
            'ok'       => true,
            'promoted' => true,
            'parent'   => $parentFit,
            'child'    => $childFit,
            'reason'   => 'Child promoted to shadow champion (≥10% gain).',
        ];
    }

    /**
     * @return array{state: array<string, mixed>|null, champion_readable: bool, candidate_readable: bool}
     */
    public function status(): array
    {
        $state = $this->readState();
        $role  = (string) ($state['role'] ?? '');
        $champ = $role !== '' && is_readable($this->shadowPath("{$role}_shadow.json"));
        $cand  = $role !== '' && is_readable($this->shadowPath("{$role}_candidate.json"));

        return ['state' => $state, 'champion_readable' => $champ, 'candidate_readable' => $cand];
    }

    /**
     * @return array{combined: float, neural: float, brevity: float}
     */
    private function fitnessParts(string $systemPrompt): array
    {
        $words = str_word_count(strip_tags($systemPrompt));
        $brevity = 1.0 / (1.0 + $words / 40.0);

        $h    = crc32($systemPrompt);
        $hex  = hash('sha256', $systemPrompt);
        $n    = static fn (int $i): int => hexdec(substr($hex, $i * 2, 2));
        $features = [
            2000.0 + ($h % 500000) / 100.0,
            (float) (hexdec(substr($hex, 0, 5))),
            (float) (($n(4) % 79) + 1),
            (($h % 201) - 100) / 100.0,
            ($n(5) % 61 + 20) / 100.0,
            ($n(6) % 61 + 20) / 100.0,
            ($n(7) % 101) / 100.0,
            (float) (($h % 3001) - 1500),
            (($n(8) << 8 | $n(9)) % 6001) / 100.0 - 30.0,
        ];

        $predictor = new TradingPredictor($this->basePath);
        $scores    = $predictor->predictScores($features);
        $mod       = (float) ($scores['modernity_score'] ?? 0.0);
        $trend     = (float) ($scores['trend_prediction'] ?? 0.0);
        $neural    = ($mod + (($trend + 1.0) / 2.0)) / 2.0;

        $combined = round(0.72 * $neural + 0.28 * $brevity, 6);

        return [
            'combined' => $combined,
            'neural'   => round($neural, 6),
            'brevity'  => round($brevity, 6),
        ];
    }

    private function ensureShadowDir(): void
    {
        $dir = $this->basePath . '/' . self::SHADOW_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
    }

    private function shadowPath(string $file): string
    {
        return $this->basePath . '/' . self::SHADOW_DIR . '/' . $file;
    }

    /** @param array<string, mixed> $data */
    private function writeState(array $data): void
    {
        $this->ensureShadowDir();
        $path = $this->basePath . '/' . self::STATE_FILE;
        $enc  = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($enc !== false) {
            file_put_contents($path, $enc);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readState(): ?array
    {
        $path = $this->basePath . '/' . self::STATE_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($path), true);

        return is_array($j) ? $j : null;
    }

    private function writeRoomOverlay(string $role): void
    {
        $this->ensureShadowDir();
        $path = $this->basePath . '/' . self::ROOM_OVERLAY;
        $payload = [
            'active'   => true,
            'role'     => $role,
            'bubble'   => 'Me evolving. Better version loading.',
            'until'    => gmdate('c', time() + 3600),
            'updated'  => gmdate('c'),
        ];
        $enc = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($enc !== false) {
            file_put_contents($path, $enc);
        }
    }
}
