<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Projects\Actions\DeleteProjectAction;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes someone's account along with the teams only they are in (their personal team,
 * say) and those teams' projects, whose telemetry is purged in the background. A team
 * with other members is left to them; the request checks it has another owner first.
 */
class DeleteAccountAction
{
    public function __construct(
        private readonly DeleteProjectAction $deleteProject,
    ) {
    }

    public function execute(User $user): void
    {
        $alone = $user->teams()->withCount('members')->get()->filter(fn (Team $team): bool => (int) $team->members_count === 1);

        foreach ($alone as $team) {
            foreach ($team->projects()->get() as $project) {
                $this->deleteProject->execute($project);
            }
        }

        DB::transaction(function () use ($alone, $user): void {
            $alone->each(fn (Team $team) => $team->delete());
            $user->delete();
        });
    }
}
