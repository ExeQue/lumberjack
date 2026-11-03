<?php

declare(strict_types=1);

namespace App\Domains\Screens\Policies;

use App\Domains\Screens\Models\Screen;
use App\Models\User;

/**
 * Every member of the team views and edits its screens, creating and deleting them
 * included: a screen only shows what each member can already see on the project pages.
 */
class ScreenPolicy
{
    public function view(User $user, Screen $screen): bool
    {
        return $user->belongsToTeam($screen->team);
    }

    public function update(User $user, Screen $screen): bool
    {
        return $user->belongsToTeam($screen->team);
    }

    public function delete(User $user, Screen $screen): bool
    {
        return $user->belongsToTeam($screen->team);
    }
}
