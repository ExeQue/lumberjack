<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthOutage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthOutage>
 */
class HealthOutageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'health_check_id' => HealthCheck::factory(),
            'project_id' => fn (array $attributes): int => (int) HealthCheck::query()->whereKey($attributes['health_check_id'])->value('project_id'),
            'status' => HealthStatus::Down,
            'first_message' => null,
            'started_at' => now()->subMinutes(30),
            'ended_at' => now()->subMinutes(10),
        ];
    }

    public function of(HealthCheck $check): static
    {
        return $this->state(fn () => ['health_check_id' => $check->id, 'project_id' => $check->project_id]);
    }

    /** Not over yet. */
    public function ongoing(): static
    {
        return $this->state(fn () => ['ended_at' => null]);
    }
}
