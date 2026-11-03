<?php

declare(strict_types=1);

namespace App\Domains\Retention\Console\Commands;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\PruneAuditLogAction;
use App\Domains\Retention\Actions\PruneProjectTelemetryAction;
use Illuminate\Console\Command;

class PruneTelemetryCommand extends Command
{
    protected $name = 'lumberjack:prune';

    protected $description = 'Delete data older than its retention';

    public function handle(PruneProjectTelemetryAction $action, PruneAuditLogAction $auditLog): int
    {
        Project::query()->eachById(function (Project $project) use ($action): void {
            $deleted = array_sum($action->execute($project));
            $this->line("{$project->slug}: {$deleted} rows");
        });

        $this->line('audit log: '.$auditLog->execute().' rows');

        return self::SUCCESS;
    }
}
