<?php

declare(strict_types=1);

namespace App\Domains\Screens\Enums;

/**
 * The shape of the display a screen is designed for, landscape; ScreenOrientation turns
 * it on its side. It only shapes the designer's grid: the TV fills its real display.
 */
enum ScreenAspect: string
{
    case Wide = '16:9';
    case Tall = '16:10';
    case Classic = '4:3';
    case Ultrawide = '21:9';
    // 3440×1440, a little wider than 21:9.
    case UltrawideQhd = '43:18';
    case SuperUltrawide = '32:9';

    public function label(): string
    {
        return __('ui/screens.aspect.'.$this->value);
    }

    /**
     * Width over height, as it stands.
     *
     * @return array{int, int}
     */
    public function ratio(ScreenOrientation $orientation = ScreenOrientation::Landscape): array
    {
        [$w, $h] = array_map('intval', explode(':', $this->value));

        return $orientation === ScreenOrientation::Portrait ? [$h, $w] : [$w, $h];
    }
}
