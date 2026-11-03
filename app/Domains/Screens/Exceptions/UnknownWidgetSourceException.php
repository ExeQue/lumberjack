<?php

declare(strict_types=1);

namespace App\Domains\Screens\Exceptions;

use App\Domains\Screens\Enums\WidgetType;
use InvalidArgumentException;

final class UnknownWidgetSourceException extends InvalidArgumentException implements ScreenExceptionInterface
{
    public static function for(WidgetType $type, string $source): self
    {
        return new self("A {$type->value} widget has no source \"{$source}\".");
    }
}
