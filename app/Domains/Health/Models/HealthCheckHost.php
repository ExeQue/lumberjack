<?php

declare(strict_types=1);

namespace App\Domains\Health\Models;

use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Enums\HealthStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one check stands on one host: its own streaks and status, and its latest
 * result. Checks Lumberjack runs have one, with an empty host.
 *
 * @property int $id
 * @property int $health_check_id
 * @property string $host
 * @property HealthStatus|null $status
 * @property int $ok_streak
 * @property int $fail_streak
 * @property HealthResultStatus|null $last_status
 * @property string|null $last_message
 * @property int|null $last_duration_us
 * @property array<string, int|float>|null $last_metrics
 * @property CarbonImmutable|null $last_result_at
 * @property int|null $interval_s
 */
#[Fillable([
    'health_check_id', 'host', 'status', 'ok_streak', 'fail_streak', 'last_status', 'last_message',
    'last_duration_us', 'last_metrics', 'last_result_at', 'interval_s',
])]
class HealthCheckHost extends Model
{
    /** Stale after this long without a result when the usual spacing is not known yet. */
    public const int STALE_AFTER_UNKNOWN_S = 900;

    protected function casts(): array
    {
        return [
            'status' => HealthStatus::class,
            'last_status' => HealthResultStatus::class,
            'last_metrics' => 'array',
            'last_result_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<HealthCheck, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(HealthCheck::class, 'health_check_id');
    }

    /**
     * No result for more than three times its usual spacing.
     */
    public function isStale(?CarbonImmutable $at = null): bool
    {
        $after = $this->interval_s !== null ? 3 * $this->interval_s : self::STALE_AFTER_UNKNOWN_S;

        return $this->last_result_at === null || $this->last_result_at->lt(($at ?? now())->subSeconds($after));
    }
}
