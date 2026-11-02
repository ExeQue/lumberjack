<?php

declare(strict_types=1);

namespace App\Domains\Teams\Exceptions;

use RuntimeException;

final class InvalidInvitationException extends RuntimeException implements TeamExceptionInterface
{
    public static function expiredOrUnknown(): self
    {
        return new self('The invitation is unknown or has expired.');
    }
}
