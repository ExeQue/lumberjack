<?php

declare(strict_types=1);

namespace App\Domains\Logs\Exceptions;

use LogicException;

final class DefaultChannelLockedException extends LogicException implements LogChannelExceptionInterface
{
    public static function create(): self
    {
        return new self('The default log channel cannot be renamed or deleted.');
    }
}
