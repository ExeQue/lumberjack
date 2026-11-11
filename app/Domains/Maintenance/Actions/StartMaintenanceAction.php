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
 * The app went into maintenance. A window already open for the environment is kept,
 * with the new message and expected end where the app sent them; otherwise one opens,
 * expected to end when the app said, or after the project's default.
 */
class StartMaintenanceAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
    ) {
    }

    public function execute(Project $project, string $environment, ?string $message, ?CarbonImmutable $expectedEnd, CarbonImmutable $at): MaintenanceWindow
    {
        $window = MaintenanceWindow::query()
            ->whereProject($project)
            ->whereEnvironment($environment)
            ->whereOpen()
            ->latest('starts_at')
            ->first();

        if ($window !== null) {
            $window->message = $message ?? $window->message;

            if ($expectedEnd !== null) {
                $window->expectTo($expectedEnd);
            }

            $window->save();

            return $window;
        }

        $window = MaintenanceWindow::query()->create([
            'project_id' => $project->id,
            'environment' => $environment,
            'source' => MaintenanceSource::Reported,
            'message' => $message,
            'starts_at' => $at,
            'expected_end_at' => $expectedEnd ?? $at->addMinutes($project->maintenance_expected_minutes),
        ]);

        $this->broadcast->execute($project->id, ChangeKind::Health, $project->uuid);

        return $window;
    }
}
