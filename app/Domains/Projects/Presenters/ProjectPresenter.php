<?php

declare(strict_types=1);

namespace App\Domains\Projects\Presenters;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectEnvironmentsQuery;
use App\Models\User;

class ProjectPresenter
{
    public function __construct(
        private readonly ProjectEnvironmentsQuery $environments,
    ) {
    }

    /**
     * The project header every project page receives.
     *
     * @return array<string, mixed>
     */
    public function header(Project $project, User $user): array
    {
        return [
            'id' => $project->id,
            'uuid' => $project->uuid,
            'slug' => $project->slug,
            'name' => $project->name,
            'can_administer' => $user->can('update', $project),
            'environments' => $this->environments->get($project),
        ];
    }
}
