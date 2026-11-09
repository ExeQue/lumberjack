<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Enums;

enum CustomAlertLevel: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Critical = 'critical';

    public function color(): int
    {
        return match ($this) {
            self::Info => 0x5B9BD5,
            self::Success => 0x4CB782,
            self::Warning => 0xF0A93B,
            self::Critical => 0xF2705F,
        };
    }

    public function hexColor(): string
    {
        return sprintf('#%06X', $this->color());
    }

    /** "high", "normal" or "low", as push channels rank a message. */
    public function urgency(): string
    {
        return match ($this) {
            self::Critical => 'high',
            self::Success => 'low',
            default => 'normal',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Info => "\u{2139}\u{FE0F}",
            self::Success => "\u{2705}",
            self::Warning => "\u{26A0}\u{FE0F}",
            self::Critical => "\u{1F6A8}",
        };
    }

    /** Named as ntfy's tags turn into an emoji. */
    public function ntfyTag(): string
    {
        return match ($this) {
            self::Info => 'information_source',
            self::Success => 'white_check_mark',
            self::Warning => 'warning',
            self::Critical => 'rotating_light',
        };
    }

    public function teamsColor(): string
    {
        return match ($this) {
            self::Info => 'Accent',
            self::Success => 'Good',
            self::Warning => 'Warning',
            self::Critical => 'Attention',
        };
    }

    /** The mail card's tone. */
    public function tone(): string
    {
        return match ($this) {
            self::Info => 'blue',
            self::Success => 'green',
            self::Warning => 'amber',
            self::Critical => 'red',
        };
    }
}
