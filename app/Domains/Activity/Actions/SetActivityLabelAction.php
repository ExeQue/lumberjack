<?php

declare(strict_types=1);

namespace App\Domains\Activity\Actions;

use App\Domains\Activity\Models\ActivityLabel;
use App\Domains\Projects\Models\Project;

/**
 * Names an activity group (no name) or one activity of it; an empty label goes back to what
 * the app records.
 */
class SetActivityLabelAction
{
    public function execute(Project $project, string $group, ?string $name, ?string $label): void
    {
        $key = ['project_id' => $project->id, 'group' => $group, 'name' => $name ?? ''];
        $label = trim((string) $label);

        if ($label === '') {
            ActivityLabel::query()->where($key)->delete();

            return;
        }

        ActivityLabel::query()->updateOrCreate($key, ['label' => $label]);
    }
}
