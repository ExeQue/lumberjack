<?php

declare(strict_types=1);

namespace App\Domains\Health\Exceptions;

use RuntimeException;

/**
 * A check pointed at this server or the network behind it.
 */
final class PrivateAddressException extends RuntimeException implements HealthExceptionInterface
{
    public static function forHost(string $host): self
    {
        return new self("{$host} resolves to a private or reserved address; set LUMBERJACK_HEALTH_ALLOW_PRIVATE=true to allow internal checks.");
    }
}
