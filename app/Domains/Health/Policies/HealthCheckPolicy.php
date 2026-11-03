<?php

declare(strict_types=1);

namespace App\Domains\Health\Policies;

use App\Domains\Health\Models\HealthCheck;
use App\Models\User;

class HealthCheckPolicy
{
    public function view(User $user, HealthCheck $check): bool
    {
        return $user->belongsToTeam($check->project->team);
    }

    /**
     * Settings, mute, archive and delete: whoever administers the project.
     */
    public function update(User $user, HealthCheck $check): bool
    {
        return $user->canAdministerTeam($check->project->team);
    }
}
