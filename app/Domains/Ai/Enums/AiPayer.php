<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * Who pays for a key's tokens: the app's own team, or the customer who brought the key.
 * A key without one inherits: customer when the key has an owner (a key brought by a
 * tenant), else the project's default.
 */
enum AiPayer: string
{
    case Us = 'us';
    case Customer = 'customer';

    public function label(): string
    {
        return __('ui/ai.enums.payer.'.$this->value);
    }
}
