<?php

declare(strict_types=1);

namespace App\Domains\Screens\Enums;

/**
 * How much room a screen leaves: around its grid (margin) or between its widgets (gap).
 * Medium is what every screen had before it could be chosen.
 */
enum ScreenSpacing: string
{
    case None = 'none';
    case Small = 's';
    case Medium = 'm';
    case Large = 'l';
}
