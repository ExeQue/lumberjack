<?php

declare(strict_types=1);

namespace App\Domains\Screens\Enums;

/**
 * How a screen looks: dark, light, or light between two times of day in the screen's
 * time zone and dark otherwise.
 */
enum ScreenTheme: string
{
    case Dark = 'dark';
    case Light = 'light';
    case Schedule = 'schedule';

    public function label(): string
    {
        return __('ui/screens.theme.'.$this->value);
    }
}
