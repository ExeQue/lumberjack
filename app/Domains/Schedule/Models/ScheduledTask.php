<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Models;

use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Eloquent\Builders\ScheduledTaskBuilder;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduledTaskFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $environment
 * @property string $name
 * @property string|null $command
 * @property string|null $description
 * @property string $expression
 * @property string|null $timezone
 * @property ScheduledRunStatus|null $last_status
 * @property CarbonImmutable|null $last_run_at
 * @property int|null $last_duration_us
 * @property CarbonImmutable|null $next_run_at
 * @property CarbonImmutable|null $missed_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id', 'environment', 'name', 'command', 'description', 'expression', 'timezone', 'last_status', 'last_run_at',
    'last_duration_us', 'next_run_at', 'missed_at', 'last_seen_at',
])]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseEloquentBuilder(ScheduledTaskBuilder::class)]
#[UseFactory(ScheduledTaskFactory::class)]
class ScheduledTask extends Model
{
    /** @use HasFactory<ScheduledTaskFactory> */
    use HasFactory;

    /**
     * How late a run may be before it counts as missed.
     */
    public const int GRACE_SECONDS = 300;

    /**
     * How long after its time a run shows as late: the scheduler starts on the minute
     * and reports a moment later, so a few seconds is no delay.
     */
    public const int LATE_AFTER_SECONDS = 60;

    /**
     * A task the scheduler has not reported for this long is taken off the schedule,
     * so a removed task stops being flagged.
     */
    public const int FORGOTTEN_AFTER_HOURS = 24;

    protected function casts(): array
    {
        return [
            'last_status' => ScheduledRunStatus::class,
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'missed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ScheduledTaskRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledTaskRun::class);
    }
}
