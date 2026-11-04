<?php

declare(strict_types=1);

namespace App\Domains\Administration\Actions;

use App\Models\User;

/**
 * The first-login onboarding is done, finished or skipped alike, and is not shown again.
 */
class CompleteOnboardingAction
{
    public function execute(User $user): void
    {
        $user->forceFill(['onboarded_at' => now()])->save();
    }
}
