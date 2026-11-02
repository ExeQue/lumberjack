<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Models;

use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Eloquent\Builders\JobRunBuilder;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\JobRunFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $occurrence_id
 * @property string|null $trace_id
 * @property string|null $environment
 * @property string|null $host
 * @property string $job_uuid
 * @property string $name
 * @property string $connection
 * @property string $queue
 * @property int $attempt
 * @property int|null $max_tries
 * @property int|null $timeout
 * @property array<string, mixed>|null $parameters
 * @property JobRunStatus $status
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_us
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id', 'occurrence_id', 'trace_id', 'environment', 'host', 'job_uuid', 'name', 'connection', 'queue',
    'attempt', 'max_tries', 'timeout', 'parameters', 'status', 'queued_at', 'started_at', 'finished_at', 'duration_us',
])]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseEloquentBuilder(JobRunBuilder::class)]
#[UseFactory(JobRunFactory::class)]
class JobRun extends Model
{
    /** @use HasFactory<JobRunFactory> */
    use HasFactory;

    /**
     * Seconds past the job's own timeout before a run counts as stuck.
     */
    public const int STUCK_MARGIN_SECONDS = 120;

    /**
     * Assumed timeout for jobs that do not report one.
     */
    public const int DEFAULT_TIMEOUT_SECONDS = 600;

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'status' => JobRunStatus::class,
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Occurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(Occurrence::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
