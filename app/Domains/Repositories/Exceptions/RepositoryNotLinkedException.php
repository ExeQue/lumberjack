<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Exceptions;

use LogicException;

final class RepositoryNotLinkedException extends LogicException implements RepositoryExceptionInterface
{
    public static function forProject(string $project): self
    {
        return new self("Project {$project} has no linked repository.");
    }
}
