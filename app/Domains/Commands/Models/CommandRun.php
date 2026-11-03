<?php

declare(strict_types=1);

namespace App\Domains\Commands\Models;

use App\Domains\Commands\Enums\CommandRunStatus;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\CommandRunFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $occurrence_id
 * @property string $run_uuid
 * @property string|null $trace_id
 * @property string|null $environment
 * @property string|null $host
 * @property string $name
 * @property array<string, mixed>|null $arguments
 * @property array<string, mixed>|null $options
 * @property CommandRunStatus $status
 * @property int|null $exit_code
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_us
 * @property float $sample_rate
 * @property bool $sampled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id', 'occurrence_id', 'run_uuid', 'trace_id', 'environment', 'host', 'name', 'arguments', 'options',
    'status', 'exit_code', 'started_at', 'finished_at', 'duration_us', 'sample_rate', 'sampled',
])]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseFactory(CommandRunFactory::class)]
class CommandRun extends Model
{
    /** @use HasFactory<CommandRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sample_rate' => 'float',
            'sampled' => 'boolean',
            'arguments' => 'array',
            'options' => 'array',
            'status' => CommandRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Occurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(Occurrence::class);
    }
}
