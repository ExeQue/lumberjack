<?php

declare(strict_types=1);

namespace App\Domains\Screens\Enums;

/**
 * Which release a widget's figures cover: every release, the newest seen in the period,
 * or the one before it.
 */
enum ReleaseScope: string
{
    case All = 'all';
    case Current = 'current';
    case Previous = 'previous';
}
