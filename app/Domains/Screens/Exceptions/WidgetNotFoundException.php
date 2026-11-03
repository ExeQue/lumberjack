<?php

declare(strict_types=1);

namespace App\Domains\Screens\Exceptions;

use App\Domains\Screens\Models\Screen;
use OutOfBoundsException;

final class WidgetNotFoundException extends OutOfBoundsException implements ScreenExceptionInterface
{
    public static function on(Screen $screen, string $widget): self
    {
        return new self("Screen {$screen->uuid} has no widget \"{$widget}\".");
    }
}
