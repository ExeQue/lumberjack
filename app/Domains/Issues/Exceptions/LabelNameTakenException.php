<?php

declare(strict_types=1);

namespace App\Domains\Issues\Exceptions;

use RuntimeException;

class LabelNameTakenException extends RuntimeException implements IssueExceptionInterface
{
    public static function named(string $name): self
    {
        return new self(__('issues.label_taken', ['name' => $name]));
    }
}
