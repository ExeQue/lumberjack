<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeamAction
{
    public function execute(User $owner, string $name, bool $personal = false): Team
    {
        return DB::transaction(function () use ($owner, $name, $personal): Team {
            $team = Team::create(['name' => $name, 'personal' => $personal]);
            $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

            $owner->unsetRelation('teams');
            $owner->forceFill(['current_team_id' => $team->id])->save();

            return $team;
        });
    }
}
