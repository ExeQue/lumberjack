<?php

declare(strict_types=1);

namespace App\Domains\Health\Models;

use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Enums\HealthStatus;
use Carbon\CarbonImmutable;
use Database\Factories\HealthCheckHostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one check stands on one host: its own streaks and status, and its latest
 * result. Checks Lumberjack runs have one, with an empty host.
 *
 * @property int $id
 * @property int $health_check_id
 * @property string $host
 * @property HealthStatus|null $status
 * @property HealthStatus|null $recovering_from the bad status a recovering host goes back to on a bad result
 * @property int $ok_streak
 * @property int $fail_streak warnings and failures in a row
 * @property int $failed_streak failures in a row
 * @property HealthResultStatus|null $last_status
 * @property string|null $last_message
 * @property int|null $last_duration_us
 * @property array<string, int|float>|null $last_metrics
 * @property CarbonImmutable|null $last_result_at
 * @property int|null $interval_s the usual spacing of results, smoothed
 * @property int|null $interval_minutes how often the app says the check runs here
 */
#[Fillable([
    'health_check_id', 'host', 'status', 'recovering_from', 'ok_streak', 'fail_streak', 'failed_streak', 'last_status', 'last_message',
    'last_duration_us', 'last_metrics', 'last_result_at', 'interval_s', 'interval_minutes',
])]
#[UseFactory(HealthCheckHostFactory::class)]
class HealthCheckHost extends Model
{
    /** @use HasFactory<HealthCheckHostFactory> */
    use HasFactory;

    use Prunable;

    /** A host that stopped reporting (a replaced pod, a retired server) is forgotten after a day. */
    public const int FORGET_AFTER_HOURS = 24;

    /** Stale after this long without a result when the usual spacing is not known yet. */
    public const int STALE_AFTER_UNKNOWN_S = 900;

    /** Stale after this many of the check's own intervals without a result, when the app sends one. */
    public const float STALE_AFTER_INTERVALS = 2.5;

    protected function casts(): array
    {
        return [
            'status' => HealthStatus::class,
            'recovering_from' => HealthStatus::class,
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
     * No result for 2.5 times the interval the app says the check runs on, or, without
     * one, for three times its usual spacing.
     */
    public function isStale(?CarbonImmutable $at = null): bool
    {
        $after = match (true) {
            $this->interval_minutes !== null => (int) (self::STALE_AFTER_INTERVALS * 60 * $this->interval_minutes),
            $this->interval_s !== null => 3 * $this->interval_s,
            default => self::STALE_AFTER_UNKNOWN_S,
        };

        return $this->last_result_at === null || $this->last_result_at->lt(($at ?? now())->subSeconds($after));
    }

    /**
     * Hosts of reported checks only: a check Lumberjack runs has one host, kept for good.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('host', '!=', '')->where('last_result_at', '<', now()->subHours(self::FORGET_AFTER_HOURS));
    }
}
