<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthCheckHost>
 */
class HealthCheckHostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'health_check_id' => HealthCheck::factory(),
            'host' => '',
            'status' => HealthStatus::Ok,
            'ok_streak' => 30,
            'fail_streak' => 0,
            'failed_streak' => 0,
            'last_status' => HealthResultStatus::Ok,
            'last_result_at' => now(),
            // Results a minute apart, as RecordHealthResultAction smooths them.
            'interval_s' => 60,
        ];
    }

    /** Failing for this many results in a row. */
    public function down(int $streak = 10, ?string $message = null): static
    {
        return $this->state(fn () => ['status' => HealthStatus::Down, 'ok_streak' => 0, 'fail_streak' => $streak, 'failed_streak' => $streak, 'last_status' => HealthResultStatus::Failed, 'last_message' => $message]);
    }

    /** Warning for this many results in a row. */
    public function degraded(int $streak = 3, ?string $message = null): static
    {
        return $this->state(fn () => ['status' => HealthStatus::Degraded, 'ok_streak' => 0, 'fail_streak' => $streak, 'failed_streak' => 0, 'last_status' => HealthResultStatus::Warning, 'last_message' => $message]);
    }

    /** Fine again for a few results, not yet for recover_after of them. */
    public function recovering(HealthStatus $from = HealthStatus::Down, int $streak = 2): static
    {
        return $this->state(fn () => ['status' => HealthStatus::Recovering, 'recovering_from' => $from, 'ok_streak' => $streak, 'fail_streak' => 0, 'failed_streak' => 0, 'last_status' => HealthResultStatus::Ok]);
    }
}
