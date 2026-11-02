<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Enums;

enum RepositoryProvider: string
{
    case GitHub = 'github';

    public function label(): string
    {
        return __('repositories.providers.'.$this->value);
    }
}
