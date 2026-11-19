<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * What a price applies to: every key of a project (or of the team, without one), the keys
 * of one owner, or one key. The narrower wins.
 */
enum AiPriceScope: string
{
    case Project = 'project';
    case Owner = 'owner';
    case Key = 'key';

    public function label(): string
    {
        return __('ui/ai.enums.scope.'.$this->value);
    }
}
