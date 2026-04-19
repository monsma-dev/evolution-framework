<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * SystemModeService — Evolution Heartbeat / Global State.
 *
 * Reads the current swarm mode from evolution.json > system_mode.current
 * and exposes per-mode limits for Hunter, Designer, and Budget.
 *
 * Modes:
 *   action   — All agents on full capacity (DeepSeek R1 + Tavily). Max budget.
 *   study    — Hunter reads tech blogs/AI papers; Designer saves as Draft only.
 *   rest     — All agents on Ollama (local). Minimal activity, no Cloud calls.
 *   vacation — Kill-switch: Hunter stops, Designer stops. Police only monitors.
 */
final class SystemModeService
{
    public const MODE_ACTION   = 'action';
    public const MODE_STUDY    = 'study';
    public const MODE_REST     = 'rest';
    public const MODE_VACATION = 'vacation';

    private const VALID_MODES = [self::MODE_ACTION, self::MODE_STUDY, self::MODE_REST, self::MODE_VACATION];

    private string $mode;
    /** @var array<string, mixed> */
    private array $modeCfg;

    public function __construct(private readonly Config $config)
    {
        $raw = (string)$config->get('evolution.system_mode.current', self::MODE_ACTION);
        $this->mode    = in_array($raw, self::VALID_MODES, true) ? $raw : self::MODE_ACTION;
        $this->modeCfg = (array)($config->get('evolution.system_mode.modes.' . $this->mode) ?? []);
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isAction(): bool   { return $this->mode === self::MODE_ACTION; }
    public function isStudy(): bool    { return $this->mode === self::MODE_STUDY; }
    public function isRest(): bool     { return $this->mode === self::MODE_REST; }
    public function isVacation(): bool { return $this->mode === self::MODE_VACATION; }

    /** Hunter should actively scan for leads. */
    public function isHunterActive(): bool
    {
        return match ($this->mode) {
            self::MODE_ACTION => true,
            self::MODE_STUDY  => true,   // study mode: tech blogs, not sales
            self::MODE_REST,
            self::MODE_VACATION => false,
        };
    }

    /** Scanner interval in seconds. */
    public function getScannerIntervalSeconds(): int
    {
        return (int)($this->modeCfg['scanner_interval_seconds'] ?? match ($this->mode) {
            self::MODE_ACTION   => 1800,
            self::MODE_STUDY    => 7200,
            self::MODE_REST     => 86400,
            self::MODE_VACATION => 0,
        });
    }

    /** Force all AI calls to local Ollama. */
    public function forceLocalOnly(): bool
    {
        return in_array($this->mode, [self::MODE_REST, self::MODE_VACATION], true);
    }

    /** Designer may only save as Draft (no live component push). */
    public function designerDraftOnly(): bool
    {
        return in_array($this->mode, [self::MODE_STUDY, self::MODE_VACATION], true);
    }

    /** Max daily budget in EUR for this mode. */
    public function getMaxDailyBudgetEur(): float
    {
        return (float)($this->modeCfg['max_daily_budget_eur'] ?? match ($this->mode) {
            self::MODE_ACTION   => 20.0,
            self::MODE_STUDY    => 5.0,
            self::MODE_REST     => 1.0,
            self::MODE_VACATION => 0.0,
        });
    }

    /** Human-readable label + icon for UI. */
    public function getLabel(): string
    {
        return match ($this->mode) {
            self::MODE_ACTION   => 'Actie-modus — Volgas op groei',
            self::MODE_STUDY    => 'Studie-modus — Leren & experimenteren',
            self::MODE_REST     => 'Rust-modus — Onderhoud & bezuinigen',
            self::MODE_VACATION => 'Vakantie-modus — Deep Sleep',
        };
    }

    public function getIcon(): string
    {
        return match ($this->mode) {
            self::MODE_ACTION   => '⚡',
            self::MODE_STUDY    => '📚',
            self::MODE_REST     => '🌙',
            self::MODE_VACATION => '🏖️',
        };
    }

    public function getBadgeColor(): string
    {
        return match ($this->mode) {
            self::MODE_ACTION   => 'bg-green-500',
            self::MODE_STUDY    => 'bg-blue-500',
            self::MODE_REST     => 'bg-amber-500',
            self::MODE_VACATION => 'bg-slate-500',
        };
    }

    /**
     * @return array{mode: string, label: string, icon: string, color: string, hunter_active: bool, force_local: bool, draft_only: bool, max_budget_eur: float, scanner_interval: int}
     */
    public function toArray(): array
    {
        return [
            'mode'             => $this->mode,
            'label'            => $this->getLabel(),
            'icon'             => $this->getIcon(),
            'color'            => $this->getBadgeColor(),
            'hunter_active'    => $this->isHunterActive(),
            'force_local'      => $this->forceLocalOnly(),
            'draft_only'       => $this->designerDraftOnly(),
            'max_budget_eur'   => $this->getMaxDailyBudgetEur(),
            'scanner_interval' => $this->getScannerIntervalSeconds(),
        ];
    }
}
