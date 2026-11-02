<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Exceptions;

use LogicException;

final class PublicRepositoryNotAllowedException extends LogicException implements RepositoryExceptionInterface
{
    public static function forRepository(string $repository): self
    {
        return new self("{$repository} is public; creating issues there is not allowed for this project.");
    }
}
