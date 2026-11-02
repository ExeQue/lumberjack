<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Teams\Models\Team;
use App\Models\User;

class SwitchTeamAction
{
    public function execute(User $user, Team $team): bool
    {
        if (! $user->belongsToTeam($team)) {
            return false;
        }

        $user->forceFill(['current_team_id' => $team->id])->save();

        return true;
    }
}
