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
        $window ??= new MaintenanceWindow(['project_id' => $project->id, 'source' => MaintenanceSource::Planned]);
        $window->fill([
            'environment' => $environment,
            'starts_at' => $startsAt,
            'message' => $message,
        ]);
        $window->expectTo($endsAt);
        $window->save();

        $this->broadcast->execute($project->id, ChangeKind::Health, $project->uuid);

        return $window;
    }
}
