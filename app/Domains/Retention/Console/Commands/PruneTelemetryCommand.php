<?php

declare(strict_types=1);

namespace App\Domains\Retention\Console\Commands;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\PruneProjectTelemetryAction;
use Illuminate\Console\Command;

class PruneTelemetryCommand extends Command
{
    protected $name = 'lumberjack:prune';

    protected $description = 'Delete raw telemetry older than each project\'s retention';

    public function handle(PruneProjectTelemetryAction $action): int
    {
        Project::query()->eachById(function (Project $project) use ($action): void {
            $deleted = array_sum($action->execute($project));
            $this->line("{$project->slug}: {$deleted} rows");
        });

        return self::SUCCESS;
    }
}
