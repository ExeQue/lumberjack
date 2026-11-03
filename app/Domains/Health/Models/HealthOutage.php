<?php

declare(strict_types=1);

namespace App\Domains\Health\Models;

use App\Domains\Health\Enums\HealthStatus;
use Carbon\CarbonImmutable;
use Database\Factories\HealthOutageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A stretch of time a check was degraded or down: from the moment it crossed its
 * degrade threshold to the moment it recovered. Kept as long as the errors.
 *
 * @property int $id
 * @property int $health_check_id
 * @property int $project_id
 * @property HealthStatus $status the worst it got
 * @property string|null $first_message
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 */
#[Fillable(['health_check_id', 'project_id', 'status', 'first_message', 'started_at', 'ended_at'])]
#[UseFactory(HealthOutageFactory::class)]
class HealthOutage extends Model
{
    /** @use HasFactory<HealthOutageFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => HealthStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
