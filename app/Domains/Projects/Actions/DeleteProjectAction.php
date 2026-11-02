<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Projects\Jobs\PurgeProjectData;
use App\Domains\Projects\Models\Project;

class DeleteProjectAction
{
    public function execute(Project $project): void
    {
        $id = $project->id;
        $project->delete();

        PurgeProjectData::dispatch($id);
    }
}
