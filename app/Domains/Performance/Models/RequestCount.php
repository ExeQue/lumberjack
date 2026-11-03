<?php

declare(strict_types=1);

namespace App\Domains\Performance\Models;

use App\Domains\Performance\Eloquent\Builders\RequestCountBuilder;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every request of one minute, method, domain, route and status, sampled or not.
 *
 * @property int $id
 * @property int $project_id
 * @property string $environment empty when the batch had none
 * @property CarbonImmutable $minute
 * @property string $method
 * @property string $route
 * @property string $domain empty when the SDK sent none
 * @property int $status_code
 * @property int $count
 * @property int $duration_us summed over the counted requests
 */
#[Fillable(['project_id', 'environment', 'minute', 'method', 'domain', 'route', 'status_code', 'count', 'duration_us'])]
#[WithoutTimestamps]
#[UseEloquentBuilder(RequestCountBuilder::class)]
class RequestCount extends Model
{
    protected function casts(): array
    {
        return [
            'minute' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
