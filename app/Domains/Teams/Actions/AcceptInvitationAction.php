<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvitationAction
{
    public function __construct(
        private readonly FindInvitationAction $findInvitationAction,
    ) {
    }

    public function execute(string $token, User $user): Team
    {
        $invitation = $this->findInvitationAction->execute($token);

        return DB::transaction(function () use ($invitation, $user): Team {
            $team = $invitation->team;

            // An owner invitation hands the team over: whoever owned it is an admin from now on.
            if ($invitation->role === TeamRole::Owner) {
                $team->members()->wherePivot('role', TeamRole::Owner->value)->updateExistingPivot(
                    $team->members()->wherePivot('role', TeamRole::Owner->value)->pluck('users.id')->all(),
                    ['role' => TeamRole::Admin->value],
                );
            }

            // Membership itself, not belongsToTeam(), which is true for every system administrator.
            if (! $team->members()->whereKey($user->id)->exists()) {
                $team->members()->attach($user, ['role' => $invitation->role->value]);
            } elseif ($invitation->role === TeamRole::Owner) {
                $team->members()->updateExistingPivot($user->id, ['role' => TeamRole::Owner->value]);
            }

            $user->unsetRelation('teams');
            $user->forceFill(['current_team_id' => $team->id])->save();
            $invitation->delete();

            return $team;
        });
    }
}
