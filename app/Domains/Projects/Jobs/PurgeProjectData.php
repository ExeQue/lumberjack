<?php

declare(strict_types=1);

namespace App\Domains\Projects\Jobs;

use App\Domains\Shared\Support\ChunkedDeleter;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the high-volume rows of a deleted project. They carry no foreign
 * keys on purpose, so nothing cascades and nothing locks a table for long.
 * Its source map files go too; their rows went with the project.
 */
class PurgeProjectData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public const array TABLES = ['request_runs', 'request_counts', 'query_runs', 'span_runs', 'web_vitals', 'job_runs', 'scheduled_task_runs', 'command_runs', 'log_entries', 'occurrences'];

    public function __construct(public readonly int $projectId)
    {
    }

    public function handle(ChunkedDeleter $deleter): void
    {
        foreach (self::TABLES as $table) {
            $deleter->delete($table, fn ($query) => $query->where('project_id', $this->projectId));
        }

        Storage::deleteDirectory(SourceMap::directory($this->projectId));
    }
}
