<?php

declare(strict_types=1);

namespace App\Domains\Health\Exceptions;

use RuntimeException;

final class UnresolvableHostException extends RuntimeException implements HealthExceptionInterface
{
    public static function forHost(string $host): self
    {
        return new self("{$host} does not resolve.");
    }
}
