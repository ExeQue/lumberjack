<?php

declare(strict_types=1);

namespace Tests\Support\Teams;

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;

trait CreatesTeamMembers
{
    /**
     * A user with the given role in a fresh team, plus one project of that team.
     *
     * @return array{user: User, project: Project}
     */
    public function memberWithProject(TeamRole $role = TeamRole::Owner): array
    {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team, $role)->create();

        return ['user' => $user, 'project' => $project];
    }
}
