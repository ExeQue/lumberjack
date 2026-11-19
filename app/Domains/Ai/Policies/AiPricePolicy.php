<?php

declare(strict_types=1);

namespace App\Domains\Ai\Policies;

use App\Domains\Ai\Models\AiPrice;
use App\Domains\Projects\Models\Project;
use App\Models\User;

/**
 * A price is changed from the prices page of a project it applies to, by whoever
 * administers that project: one of the project's own, or one of its team's.
 */
class AiPricePolicy
{
    public function update(User $user, AiPrice $price, Project $project): bool
    {
        return $price->team_id === $project->team_id
            && ($price->project_id === null || $price->project_id === $project->id)
            && $user->can('update', $project);
    }

    public function delete(User $user, AiPrice $price, Project $project): bool
    {
        return $this->update($user, $price, $project);
    }
}
