<?php

declare(strict_types=1);

namespace App\Domains\Administration\Actions;

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Teams\Actions\CreateTeamAction;
use App\Domains\Teams\Actions\InviteMemberAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A team for someone: a user who exists owns it at once; anyone else is invited
 * to own it, and the team waits without members until they accept.
 */
class CreateTeamForOwnerAction
{
    public function __construct(
        private readonly CreateTeamAction $createTeam,
        private readonly InviteMemberAction $invite,
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    /** @return array{team: Team, invited: bool} */
    public function execute(User $admin, string $name, string $ownerEmail): array
    {
        $ownerEmail = Str::lower(trim($ownerEmail));
        $owner = User::query()->where('email', $ownerEmail)->first();

        $team = $owner !== null
            ? $this->createTeam->execute($owner, $name)
            : DB::transaction(function () use ($admin, $name, $ownerEmail): Team {
                $team = Team::create(['name' => $name]);
                $this->invite->execute($team, $ownerEmail, TeamRole::Owner, $admin, allowOwner: true);

                return $team;
            });

        $this->audit->execute($admin, AdminAction::TeamCreated, $team->name, ['owner' => $ownerEmail, 'invited' => $owner === null]);

        return ['team' => $team, 'invited' => $owner === null];
    }
}
