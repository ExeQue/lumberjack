<?php

declare(strict_types=1);

namespace App\Domains\Activity\Models;

use App\Domains\Activity\Eloquent\Builders\ActivityBuilder;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened in an app, by its group and name: who did it, what it was
 * done to and what the app said about it.
 *
 * @property int $id
 * @property int $project_id
 * @property string $group
 * @property string $name
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property list<array{type: string, id: string}>|null $subjects
 * @property array<string, mixed>|null $payload
 * @property string|null $trace_id
 * @property string|null $environment
 * @property string|null $release
 * @property string|null $host
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['project_id', 'group', 'name', 'actor_type', 'actor_id', 'subjects', 'payload', 'trace_id', 'environment', 'release', 'host', 'occurred_at'])]
#[WithoutTimestamps]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseEloquentBuilder(ActivityBuilder::class)]
#[UseFactory(ActivityFactory::class)]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /** The group of an event sent without one. */
    public const string DEFAULT_GROUP = 'default';

    protected function casts(): array
    {
        return [
            'subjects' => 'array',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
