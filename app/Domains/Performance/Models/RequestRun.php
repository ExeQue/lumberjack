<?php

declare(strict_types=1);

namespace App\Domains\Performance\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\RequestRunFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string|null $trace_id
 * @property string|null $environment
 * @property string|null $release
 * @property string|null $host
 * @property string $method
 * @property string|null $route
 * @property string $url
 * @property int $status_code
 * @property int $duration_us
 * @property int|null $memory_peak
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['project_id', 'trace_id', 'environment', 'release', 'host', 'method', 'route', 'url', 'status_code', 'duration_us', 'memory_peak', 'occurred_at'])]
#[WithoutTimestamps]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseFactory(RequestRunFactory::class)]
class RequestRun extends Model
{
    /** @use HasFactory<RequestRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
