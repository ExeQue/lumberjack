<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Models;

use App\Domains\Issues\Models\Occurrence;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduledTaskRunFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $scheduled_task_id
 * @property int|null $occurrence_id
 * @property string $run_uuid
 * @property string|null $trace_id
 * @property string|null $environment
 * @property string|null $host
 * @property ScheduledRunStatus $status
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_us
 * @property float $sample_rate
 * @property bool $sampled
 * @property int|null $exit_code
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id', 'scheduled_task_id', 'occurrence_id', 'run_uuid', 'trace_id', 'environment', 'host',
    'status', 'started_at', 'finished_at', 'duration_us', 'exit_code', 'sample_rate', 'sampled',
])]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseFactory(ScheduledTaskRunFactory::class)]
class ScheduledTaskRun extends Model
{
    /** @use HasFactory<ScheduledTaskRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sample_rate' => 'float',
            'sampled' => 'boolean',
            'status' => ScheduledRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ScheduledTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class, 'scheduled_task_id');
    }

    /** @return BelongsTo<Occurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(Occurrence::class);
    }
}
