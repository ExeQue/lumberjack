<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Exceptions\CannotChangeOwnerException;
use App\Domains\Teams\Models\Team;
use App\Models\User;

class RemoveMemberAction
{
    public function execute(Team $team, User $member): void
    {
        if ($team->roleOf($member) === TeamRole::Owner) {
            throw CannotChangeOwnerException::forUser();
        }

        $team->members()->detach($member->id);
        $member->unsetRelation('teams');

        if ($member->current_team_id === $team->id) {
            $member->forceFill(['current_team_id' => $member->teams()->value('teams.id')])->save();
        }
    }
}
