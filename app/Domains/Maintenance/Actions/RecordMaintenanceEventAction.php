<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Ingest\Data\Envelope;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A maintenance event from ingest: start opens (or keeps) the environment's window, end
 * closes it. An end with no window open does nothing.
 */
class RecordMaintenanceEventAction
{
    /** The longest an app may say it will take, a week. */
    public const int MAX_EXPECTED_MINUTES = 10_080;

    public function __construct(
        private readonly StartMaintenanceAction $startMaintenanceAction,
        private readonly EndMaintenanceWindowAction $endMaintenanceWindowAction,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function execute(Project $project, array $event, Envelope $envelope): void
    {
        $at = Envelope::time($event['timestamp'] ?? null)->min(now());
        $environment = $envelope->environment ?? '';

        match ($event['state'] ?? null) {
            'start' => $this->startMaintenanceAction->execute($project, $environment, Envelope::short($event['message'] ?? null, 500), $this->expectedEnd($event, $at), $at),
            'end' => MaintenanceWindow::query()->where('project_id', $project->id)->where('environment', $environment)->whereOpen()->get()
                ->each(fn (MaintenanceWindow $window) => $this->endMaintenanceWindowAction->execute($window, $at)),
            default => throw new InvalidArgumentException('A maintenance event is either start or end.'),
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function expectedEnd(array $event, CarbonImmutable $at): ?CarbonImmutable
    {
        if (is_string($event['expected_until'] ?? null) && $event['expected_until'] !== '') {
            return Envelope::time($event['expected_until'])->max($at);
        }

        if (is_numeric($event['expected_minutes'] ?? null)) {
            return $at->addMinutes(max(1, min(self::MAX_EXPECTED_MINUTES, (int) $event['expected_minutes'])));
        }

        return null;
    }
}
