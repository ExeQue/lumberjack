<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Ingest\Data\Envelope;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * A maintenance event from ingest: start opens (or keeps) the environment's window, end
 * closes it. The agent may hold a start back past the end that followed it, so each end's
 * time is remembered for a day, and a start older than it is ignored.
 */
class RecordMaintenanceEventAction
{
    /** The longest an app may say it will take, a week. */
    public const int MAX_EXPECTED_MINUTES = 10_080;

    /** How long an end's time is kept for a start held back behind it. */
    public const int LAST_END_HOURS = 24;

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

        $lastEnd = 'maintenance-last-end:'.$project->id.':'.$environment;

        // An end only closes what started before it, and a start older than the last end was
        // held back behind it: what it opened is over already.
        match ($event['state'] ?? null) {
            'start' => (float) Cache::get($lastEnd, 0) > (float) $at->format('U.u')
                ? null
                : $this->startMaintenanceAction->execute($project, $environment, Envelope::short($event['message'] ?? null, 500), $this->expectedEnd($event, $at), $at),
            'end' => $this->end($project, $environment, $at, $lastEnd),
            default => throw new InvalidArgumentException('A maintenance event is either start or end.'),
        };
    }

    private function end(Project $project, string $environment, CarbonImmutable $at, string $lastEnd): void
    {
        Cache::put($lastEnd, max((float) Cache::get($lastEnd, 0), (float) $at->format('U.u')), now()->addHours(self::LAST_END_HOURS));

        MaintenanceWindow::query()->whereProject($project)->whereEnvironment($environment)->whereOpen($at)->whereNotEnded()->get()
            ->each(fn (MaintenanceWindow $window) => $this->endMaintenanceWindowAction->execute($window, $at));
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
