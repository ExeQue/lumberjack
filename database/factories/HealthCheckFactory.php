<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Health\Enums\HealthCheckSource;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthCheck>
 */
class HealthCheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'source' => HealthCheckSource::Reported,
            'environment' => 'production',
            'slug' => 'database:pgsql',
            'degrade_after' => 2,
            'down_after' => 5,
            'recover_after' => 5,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function monitored(HealthCheckType $type = HealthCheckType::Http, array $config = ['url' => 'https://example.com/up']): self
    {
        return $this->state([
            'source' => HealthCheckSource::Monitored,
            'type' => $type,
            'slug' => null,
            'environment' => '',
            'name' => 'Website',
            'config' => $config,
            'interval_minutes' => 1,
            'ping_token' => $type === HealthCheckType::Heartbeat ? bin2hex(random_bytes(20)) : null,
        ]);
    }
}
