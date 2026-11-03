<?php

declare(strict_types=1);

namespace App\Domains\Screens\Enums;

/** Whether a screen's display stands landscape or portrait; see ScreenAspect. */
enum ScreenOrientation: string
{
    case Landscape = 'landscape';
    case Portrait = 'portrait';

    public function label(): string
    {
        return __('ui/screens.orientation.'.$this->value);
    }
}
