<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransferOwnershipAction
{
    public function execute(Team $team, User $currentOwner, User $newOwner): void
    {
        if (! $newOwner->belongsToTeam($team)) {
            return;
        }

        DB::transaction(function () use ($team, $currentOwner, $newOwner): void {
            $team->members()->updateExistingPivot($currentOwner->id, ['role' => TeamRole::Admin->value]);
            $team->members()->updateExistingPivot($newOwner->id, ['role' => TeamRole::Owner->value]);
            $currentOwner->unsetRelation('teams');
            $newOwner->unsetRelation('teams');
        });
    }
}
