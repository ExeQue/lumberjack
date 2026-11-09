<?php

declare(strict_types=1);

namespace App\Domains\Screens\Exceptions;

use App\Domains\Screens\Models\Screen;
use RuntimeException;

final class ScreenConflictException extends RuntimeException implements ScreenExceptionInterface
{
    public static function on(Screen $screen, int $given, int $current): self
    {
        return new self("Screen {$screen->uuid} is at revision {$current}, not {$given}: it changed in the meantime. Read it again and redo the change.");
    }
}
