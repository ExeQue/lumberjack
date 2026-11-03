<?php

declare(strict_types=1);

namespace App\Domains\Screens\Exceptions;

use App\Domains\Screens\Models\Screen;
use InvalidArgumentException;

final class OverlappingWidgetsException extends InvalidArgumentException implements ScreenExceptionInterface
{
    public static function on(Screen $screen): self
    {
        return new self("Widgets of screen {$screen->uuid} would cover the same cells.");
    }
}
