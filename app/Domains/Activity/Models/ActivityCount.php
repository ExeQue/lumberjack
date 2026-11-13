<?php

declare(strict_types=1);

namespace App\Domains\Activity\Models;

use App\Domains\Activity\Eloquent\Builders\ActivityCountBuilder;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityCountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How often one event happened in one hour. Kept longer than the events it counts.
 *
 * @property int $id
 * @property int $project_id
 * @property string $environment empty when the batch had none
 * @property string $group
 * @property string $name
 * @property CarbonImmutable $hour
 * @property int $count
 */
#[Fillable(['project_id', 'environment', 'group', 'name', 'hour', 'count'])]
#[WithoutTimestamps]
#[UseEloquentBuilder(ActivityCountBuilder::class)]
#[UseFactory(ActivityCountFactory::class)]
class ActivityCount extends Model
{
    /** @use HasFactory<ActivityCountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hour' => 'datetime',
            'count' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
