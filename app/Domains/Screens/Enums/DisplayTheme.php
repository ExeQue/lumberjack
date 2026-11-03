<?php

declare(strict_types=1);

namespace App\Domains\Screens\Enums;

/**
 * How a display draws its screen: as the screen says, or always dark, always light, or
 * by the screen's time of day schedule, for a TV in a bright room showing a dark screen.
 */
enum DisplayTheme: string
{
    case Default = 'default';
    case Dark = 'dark';
    case Light = 'light';
    case Schedule = 'schedule';

    public function label(): string
    {
        return __('ui/screens.display_theme.'.$this->value);
    }

    /** The screen theme it forces, or null to keep the screen's own. */
    public function screenTheme(): ?ScreenTheme
    {
        return match ($this) {
            self::Default => null,
            self::Dark => ScreenTheme::Dark,
            self::Light => ScreenTheme::Light,
            self::Schedule => ScreenTheme::Schedule,
        };
    }
}
