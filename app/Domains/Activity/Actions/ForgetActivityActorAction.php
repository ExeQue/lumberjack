<?php

declare(strict_types=1);

namespace App\Domains\Activity\Actions;

use App\Domains\Activity\Models\Activity;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\ChunkedDeleter;
use Illuminate\Database\Query\Builder;

/**
 * Deletes every event one actor did in a project, such as when its account is deleted.
 * The hourly counts stay: they never knew the actor.
 */
class ForgetActivityActorAction
{
    public function __construct(
        private readonly ChunkedDeleter $deleter,
    ) {
    }

    /**
     * @return int events deleted
     */
    public function execute(Project $project, string $actorType, string $actorId): int
    {
        if ($actorType === '' || $actorId === '') {
            return 0;
        }

        return $this->deleter->delete((new Activity())->getTable(), fn (Builder $q) => $q
            ->where('project_id', $project->id)
            ->where('actor_type', $actorType)
            ->where('actor_id', $actorId));
    }
}
