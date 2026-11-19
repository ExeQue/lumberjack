<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

enum AiCallStatus: string
{
    case Ok = 'ok';
    case Error = 'error';

    public function label(): string
    {
        return __('ui/ai.enums.status.'.$this->value);
    }
}
