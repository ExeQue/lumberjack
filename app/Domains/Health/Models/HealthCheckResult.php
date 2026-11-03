<?php

declare(strict_types=1);

namespace App\Domains\Health\Models;

use App\Domains\Health\Enums\HealthResultStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of a check. High volume: pruned with the project's performance data.
 *
 * @property int $id
 * @property int $health_check_id
 * @property int $project_id
 * @property string $host
 * @property HealthResultStatus $status
 * @property string|null $message
 * @property array<string, int|float>|null $metrics
 * @property int|null $duration_us
 * @property CarbonImmutable $checked_at
 */
#[Fillable(['health_check_id', 'project_id', 'host', 'status', 'message', 'metrics', 'duration_us', 'checked_at'])]
class HealthCheckResult extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => HealthResultStatus::class,
            'metrics' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
