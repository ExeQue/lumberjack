<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * What a budget is counted in: cost in the team's currency, or tokens.
 */
enum AiBudgetBasis: string
{
    case Cost = 'cost';
    case Tokens = 'tokens';

    public function label(): string
    {
        return __('ui/ai.enums.budget_basis.'.$this->value);
    }
}
