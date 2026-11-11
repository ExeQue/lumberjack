<?php

declare(strict_types=1);

namespace App\Domains\Projects\Presenters;

use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectEnvironmentsQuery;
use App\Models\User;

class ProjectPresenter
{
    /** As far back as the longest period a page shows. */
    public const int MAINTENANCE_DAYS = 30;

    public const int MAINTENANCE_LIMIT = 50;

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
            // For the bands on every chart of the project, the in maintenance badge and the Health page's list.
            'maintenance' => $project->maintenanceWindows()
                ->where(fn ($q) => $q->whereOverlaps(now()->subDays(self::MAINTENANCE_DAYS))->orWhereNotEnded())
                ->latest('starts_at')->limit(self::MAINTENANCE_LIMIT)->get()
                ->map(fn (MaintenanceWindow $w): array => $w->toSummary())->values()->all(),
        ];
    }
}
