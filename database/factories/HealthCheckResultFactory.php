<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthCheckResult>
 */
class HealthCheckResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'health_check_id' => HealthCheck::factory(),
            'project_id' => fn (array $attributes): int => (int) HealthCheck::query()->whereKey($attributes['health_check_id'])->value('project_id'),
            'host' => '',
            'status' => HealthResultStatus::Ok,
            'message' => null,
            'metrics' => ['latency_ms' => 4.2],
            'duration_us' => 4_200,
            'checked_at' => now(),
        ];
    }

    /** Of this check, as its project's. */
    public function of(HealthCheck $check, string $host = ''): static
    {
        return $this->state(fn () => ['health_check_id' => $check->id, 'project_id' => $check->project_id, 'host' => $host]);
    }

    public function warning(string $message): static
    {
        return $this->state(fn () => ['status' => HealthResultStatus::Warning, 'message' => $message]);
    }

    public function failed(string $message): static
    {
        return $this->state(fn () => ['status' => HealthResultStatus::Failed, 'message' => $message]);
    }
}
