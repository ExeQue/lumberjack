<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Alerts\Actions\CreateTeamAlertRulesAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeamAction
{
    public function __construct(
        private readonly CreateTeamAlertRulesAction $alertRules,
    ) {
    }

    public function execute(User $owner, string $name): Team
    {
        return DB::transaction(function () use ($owner, $name): Team {
            $team = Team::create(['name' => $name]);
            $this->alertRules->execute($team);
            $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

            $owner->unsetRelation('teams');
            $owner->forceFill(['current_team_id' => $team->id])->save();

            return $team;
        });
    }
}
