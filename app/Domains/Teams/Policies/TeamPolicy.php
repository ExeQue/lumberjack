<?php

declare(strict_types=1);

namespace App\Domains\Teams\Policies;

use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    public function administer(User $user, Team $team): bool
    {
        return $user->canAdministerTeam($team);
    }

    public function transfer(User $user, Team $team): bool
    {
        return $user->roleIn($team) === TeamRole::Owner;
    }
}
