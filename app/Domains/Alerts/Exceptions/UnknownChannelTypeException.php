<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Exceptions;

use InvalidArgumentException;

final class UnknownChannelTypeException extends InvalidArgumentException implements AlertExceptionInterface
{
    public static function forType(string $type): self
    {
        return new self("No alert driver is registered for channel type [{$type}].");
    }
}
