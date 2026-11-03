<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $host
 * @property string $connection
 * @property string $queue
 * @property int $size
 * @property int|null $oldest_age_s
 * @property CarbonImmutable $sampled_at
 */
#[Fillable(['project_id', 'host', 'connection', 'queue', 'size', 'oldest_age_s', 'sampled_at'])]
class QueueSample extends Model
{
    use Prunable;

    public $timestamps = false;

    /**
     * As far back as the Jobs page looks.
     */
    public const int KEEP_DAYS = 30;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'oldest_age_s' => 'integer',
            'sampled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('sampled_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
