<?php

declare(strict_types=1);

namespace App\Domains\Issues\Exceptions;

use InvalidArgumentException;

final class AssigneeNotInTeamException extends InvalidArgumentException implements IssueExceptionInterface
{
    public static function create(): self
    {
        return new self('An issue can only be assigned to a member of the project team.');
    }
}
