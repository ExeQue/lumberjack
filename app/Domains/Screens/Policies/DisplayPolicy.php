<?php

declare(strict_types=1);

namespace App\Domains\Screens\Policies;

use App\Domains\Screens\Models\Display;
use App\Models\User;

/**
 * Members see the team's displays (the list is the team's); team admins pair, change and remove them, since a
 * display shows the team's data in a room anyone may walk into.
 */
class DisplayPolicy
{
    public function update(User $user, Display $display): bool
    {
        return $user->canAdministerTeam($display->team);
    }

    public function delete(User $user, Display $display): bool
    {
        return $user->canAdministerTeam($display->team);
    }
}
