<?php

declare(strict_types=1);

namespace App\Domains\Teams\Exceptions;

use LogicException;

final class CannotChangeOwnerException extends LogicException implements TeamExceptionInterface
{
    public static function forUser(): self
    {
        return new self('The team owner cannot be removed or demoted; transfer ownership first.');
    }
}
