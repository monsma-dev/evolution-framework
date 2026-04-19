<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * AgentPersonality — Definieert de drie agent-persona's en hun Telegram-stijl.
 *
 * Junior       — Routinematige status-updates, technische feiten, zakelijk kort.
 * Architect    — Strategische analyses, complimenten, thought leadership (Sonnet).
 * RiskManager  — Waarschuwingen, flash-crash alerts, circuit breaker meldingen.
 *
 * Gebruik:
 *   $msg = AgentPersonality::junior()->format("ETH is 5% gedaald.");
 *   $msg = AgentPersonality::architect()->format("De nieuwe InvoicingTool vergroot...");
 *   $msg = AgentPersonality::riskManager()->format("FLASH CRASH gedetecteerd!");
 */
final class AgentPersonality
{
    public const JUNIOR       = 'junior';
    public const ARCHITECT    = 'architect';
    public const RISK_MANAGER = 'risk_manager';

    private string $persona;
    private string $name;
    private string $emoji;
    private string $headerStyle;  // HTML-tag voor header (b / b / b)
    private string $footerNote;

    private function __construct(
        string $persona,
        string $name,
        string $emoji,
        string $headerStyle,
        string $footerNote
    ) {
        $this->persona     = $persona;
        $this->name        = $name;
        $this->emoji       = $emoji;
        $this->headerStyle = $headerStyle;
        $this->footerNote  = $footerNote;
    }

    public static function junior(): self
    {
        return new self(
            self::JUNIOR,
            'Junior Agent',
            '🤖',
            'b',
            'Junior Agent — Evolution Trading System'
        );
    }

    public static function architect(): self
    {
        return new self(
            self::ARCHITECT,
            'Architect',
            '🧠',
            'b',
            'Architect — Sonnet Strategic Intelligence'
        );
    }

    public static function riskManager(): self
    {
        return new self(
            self::RISK_MANAGER,
            'Risk Manager',
            '🛡️',
            'b',
            'Risk Manager — Evolution Safety Layer'
        );
    }

    public static function fromPersona(string $persona): self
    {
        return match ($persona) {
            self::ARCHITECT    => self::architect(),
            self::RISK_MANAGER => self::riskManager(),
            default            => self::junior(),
        };
    }

    /**
     * Formatteer een bericht in de stijl van deze persona.
     * Input is plain text (wordt geëscaped). Gebruik rawFormat() voor HTML-input.
     */
    public function format(string $message, string $subject = ''): string
    {
        $header  = $subject !== ''
            ? "{$this->emoji} <{$this->headerStyle}>{$this->name} — {$subject}</{$this->headerStyle}>"
            : "{$this->emoji} <{$this->headerStyle}>{$this->name}</{$this->headerStyle}>";

        $body   = htmlspecialchars($message, ENT_QUOTES, 'UTF-8', false);
        $footer = '<i>' . date('d-m-Y H:i') . " UTC — {$this->footerNote}</i>";

        return "{$header}\n\n{$body}\n\n{$footer}";
    }

    /**
     * Formatteer met HTML-input (geen extra escaping van de body).
     */
    public function rawFormat(string $htmlMessage, string $subject = ''): string
    {
        $header  = $subject !== ''
            ? "{$this->emoji} <{$this->headerStyle}>{$this->name} — {$subject}</{$this->headerStyle}>"
            : "{$this->emoji} <{$this->headerStyle}>{$this->name}</{$this->headerStyle}>";

        $footer = '<i>' . date('d-m-Y H:i') . " UTC — {$this->footerNote}</i>";

        return "{$header}\n\n{$htmlMessage}\n\n{$footer}";
    }

    /** Persona-bewuste Telegram stijl-kleur voor de Reasoning Feed UI. */
    public function uiColor(): string
    {
        return match ($this->persona) {
            self::ARCHITECT    => 'violet',
            self::RISK_MANAGER => 'red',
            default            => 'blue',
        };
    }

    public function getName(): string  { return $this->name; }
    public function getEmoji(): string { return $this->emoji; }
    public function getPersona(): string { return $this->persona; }
}
