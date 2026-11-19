<?php

declare(strict_types=1);

namespace App\Domains\Ai\Policies;

use App\Domains\Ai\Models\AiBudget;
use App\Models\User;

/**
 * A budget is the project's: whoever administers the project sets it.
 */
class AiBudgetPolicy
{
    public function update(User $user, AiBudget $budget): bool
    {
        return $user->can('update', $budget->project);
    }

    public function delete(User $user, AiBudget $budget): bool
    {
        return $this->update($user, $budget);
    }
}
