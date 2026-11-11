<?php

declare(strict_types=1);

namespace App\Domains\Retention\Console\Commands;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\PruneApplicationDataAction;
use App\Domains\Retention\Actions\PruneProjectTelemetryAction;
use App\Domains\SourceMaps\Actions\PruneSourceMapsAction;
use Illuminate\Console\Command;

class PruneTelemetryCommand extends Command
{
    protected $name = 'lumberjack:prune';

    protected $description = 'Delete data older than its retention';

    public function handle(PruneProjectTelemetryAction $action, PruneApplicationDataAction $applicationData, PruneSourceMapsAction $sourceMaps): int
    {
        Project::query()->eachById(function (Project $project) use ($action, $sourceMaps): void {
            $deleted = array_sum($action->execute($project));
            $maps = $sourceMaps->execute($project);
            $this->line("{$project->slug}: {$deleted} rows, {$maps} source maps");
        });

        foreach ($applicationData->execute() as $category => $deleted) {
            $this->line("{$category}: {$deleted} rows");
        }

        return self::SUCCESS;
    }
}
