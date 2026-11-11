<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Maintenance\Enums\MaintenanceSource;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;

/**
 * Plans a window, or changes one: its environment, start, planned end and message.
 * Its planned end is the expected end the overrun rule watches.
 */
class SaveMaintenanceWindowAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
    ) {
    }

    public function execute(Project $project, ?MaintenanceWindow $window, string $environment, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?string $message): MaintenanceWindow
    {
        // One not started yet may still be moved wholly into the past, as a new one may.
        $notStarted = $window === null || $window->starts_at->greaterThan(now());
        $window ??= new MaintenanceWindow(['project_id' => $project->id, 'source' => MaintenanceSource::Planned]);
        $window->fill([
            'environment' => $environment,
            'starts_at' => $startsAt,
            'message' => $message,
        ]);
        $window->expectTo($endsAt);

        // Planned wholly in the past it is a record of maintenance that happened, not an overrun.
        // Not an open one: that is ended by EndMaintenanceWindowAction, which tells who heard of its overrun.
        if ($notStarted && $window->ended_at === null && $endsAt->lessThanOrEqualTo(now())) {
            $window->ended_at = $window->expected_end_at;
        }

        // Moved to start after it ended, it ends where it starts.
        if ($window->ended_at !== null) {
            $window->ended_at = $window->ended_at->max($window->starts_at);
        }

        $window->save();

        $this->broadcast->execute($project->id, ChangeKind::Health, $project->uuid);

        return $window;
    }
}
