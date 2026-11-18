<?php

declare(strict_types=1);

namespace App\Domains\Projects\Policies;

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $user->belongsToTeam($project->team);
    }

    /**
     * Project administration: settings, keys, channels, retention, repo link, alert rules.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->canAdministerTeam($project->team);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->canAdministerTeam($project->team);
    }

    /**
     * Moving to another team takes administering both.
     */
    public function move(User $user, Project $project, Team $target): bool
    {
        return $target->id !== $project->team_id && $user->canAdministerTeam($project->team) && $user->canAdministerTeam($target);
    }
}
