<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Exceptions;

use RuntimeException;

final class RepositoryRequestFailedException extends RuntimeException implements RepositoryExceptionInterface
{
    public static function status(string $repository, int $status): self
    {
        return new self("The request to {$repository} failed with HTTP {$status}.");
    }
}
