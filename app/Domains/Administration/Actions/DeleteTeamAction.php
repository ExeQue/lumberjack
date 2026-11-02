<?php

declare(strict_types=1);

namespace App\Domains\Administration\Actions;

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Projects\Actions\DeleteProjectAction;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a team with its projects, whose telemetry is purged in the background.
 * Members whose current team it was move to another team of theirs.
 */
class DeleteTeamAction
{
    public function __construct(
        private readonly DeleteProjectAction $deleteProject,
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    public function execute(User $admin, Team $team): void
    {
        foreach ($team->projects()->get() as $project) {
            $this->deleteProject->execute($project);
        }

        $name = $team->name;

        DB::transaction(function () use ($team): void {
            foreach (User::query()->where('current_team_id', $team->id)->get() as $user) {
                $user->forceFill(['current_team_id' => $user->teams()->whereKeyNot($team->id)->value('teams.id')])->save();
            }

            $team->delete();
        });

        $this->audit->execute($admin, AdminAction::TeamDeleted, $name);
    }
}
