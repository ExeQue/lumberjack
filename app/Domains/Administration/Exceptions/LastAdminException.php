<?php

declare(strict_types=1);

namespace App\Domains\Administration\Exceptions;

use RuntimeException;

final class LastAdminException extends RuntimeException implements AdministrationExceptionInterface
{
    public static function create(): self
    {
        return new self(__('administration.last_admin'));
    }

    public static function self(): self
    {
        return new self(__('administration.not_yourself'));
    }
}
