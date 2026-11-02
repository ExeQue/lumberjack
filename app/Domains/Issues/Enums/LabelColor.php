<?php

declare(strict_types=1);

namespace App\Domains\Issues\Enums;

enum LabelColor: string
{
    case Gray = 'gray';
    case Red = 'red';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Green = 'green';
    case Teal = 'teal';
    case Blue = 'blue';
    case Purple = 'purple';

    /**
     * The color a project's n-th label gets, so new labels do not all look alike.
     */
    public static function forIndex(int $index): self
    {
        $colors = array_values(array_filter(self::cases(), fn (self $c): bool => $c !== self::Gray));

        return $colors[$index % count($colors)];
    }
}
