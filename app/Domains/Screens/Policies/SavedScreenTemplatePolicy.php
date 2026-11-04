<?php

declare(strict_types=1);

namespace App\Domains\Screens\Policies;

use App\Domains\Screens\Models\SavedScreenTemplate;
use App\Domains\Teams\Models\Team;
use App\Models\User;

/**
 * Every member saves screens as templates and makes screens from them, as they make
 * screens; renaming and deleting a template, which the whole team picks from, is for
 * team admins.
 */
class SavedScreenTemplatePolicy
{
    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    public function use(User $user, SavedScreenTemplate $template): bool
    {
        return $user->belongsToTeam($template->team);
    }

    public function update(User $user, SavedScreenTemplate $template): bool
    {
        return $user->canAdministerTeam($template->team);
    }

    public function delete(User $user, SavedScreenTemplate $template): bool
    {
        return $user->canAdministerTeam($template->team);
    }
}
