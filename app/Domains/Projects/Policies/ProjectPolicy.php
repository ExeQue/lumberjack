<?php

declare(strict_types=1);

namespace App\Domains\Projects\Policies;

use App\Domains\Projects\Models\Project;
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
}
