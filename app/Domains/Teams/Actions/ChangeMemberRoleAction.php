<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Exceptions\CannotChangeOwnerException;
use App\Domains\Teams\Models\Team;
use App\Models\User;

class ChangeMemberRoleAction
{
    public function execute(Team $team, User $member, TeamRole $role): void
    {
        if ($team->roleOf($member) === TeamRole::Owner || $role === TeamRole::Owner) {
            throw CannotChangeOwnerException::forUser();
        }

        $team->members()->updateExistingPivot($member->id, ['role' => $role->value]);
        $member->unsetRelation('teams');
    }
}
