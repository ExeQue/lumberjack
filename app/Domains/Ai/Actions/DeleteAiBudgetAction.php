<?php

declare(strict_types=1);

namespace App\Domains\Ai\Actions;

use App\Domains\Ai\Models\AiBudget;

/**
 * Removes a budget, and with it where its alerts stood.
 */
class DeleteAiBudgetAction
{
    public function execute(AiBudget $budget): void
    {
        $budget->delete();
    }
}
