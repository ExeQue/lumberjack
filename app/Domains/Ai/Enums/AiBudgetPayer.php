<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * Whose usage a budget counts by who pays for it: all of it, only what the team pays for
 * (its own keys), or only what customers pay for (the keys they brought).
 */
enum AiBudgetPayer: string
{
    case All = 'all';
    case Us = 'us';
    case Customer = 'customer';

    public function label(): string
    {
        return __('ui/ai.enums.budget_payer.'.$this->value);
    }

    /**
     * What a new budget counts unless told: the team's own spend for the project or a key,
     * everything for a key owner or an actor, who are about one tenant or one person.
     */
    public static function defaultFor(AiBudgetScope $scope): self
    {
        return match ($scope) {
            AiBudgetScope::Project, AiBudgetScope::Key => self::Us,
            AiBudgetScope::Owner, AiBudgetScope::Actor => self::All,
        };
    }
}
