<?php

declare(strict_types=1);

namespace App\Domains\Screens\Enums;

/**
 * How a screen shows on a display of another shape than it was designed for: keeping
 * its shape (as large as fits, centered, the rest in the ground color) or filling it.
 */
enum ScreenFit: string
{
    case Keep = 'keep';
    case Fill = 'fill';

    public function label(): string
    {
        return __('ui/screens.fit.'.$this->value);
    }
}
