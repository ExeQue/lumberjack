<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * What a budget counts: all of a project's AI usage, one key's, one key owner's or one
 * actor's.
 */
enum AiBudgetScope: string
{
    case Project = 'project';
    case Key = 'key';
    case Owner = 'owner';
    case Actor = 'actor';

    public function label(): string
    {
        return __('ui/ai.enums.budget_scope.'.$this->value);
    }
}
