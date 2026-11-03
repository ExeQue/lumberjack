<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Performance\Models\RequestCount;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestCount>
 */
class RequestCountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'minute' => now()->startOfMinute(),
            'method' => 'GET',
            'domain' => '',
            'route' => '/dashboard',
            'status_code' => 200,
            'count' => 1,
            'duration_us' => 120_000,
        ];
    }

    /** The requests to this route in one minute, as the SDK counts them. */
    public function forRoute(string $method, string $route, int $status = 200): static
    {
        return $this->state(fn () => ['method' => $method, 'route' => $route, 'status_code' => $status]);
    }

    public function onDomain(string $domain): static
    {
        return $this->state(fn () => ['domain' => $domain]);
    }

    /** This many requests, each taking about this long. */
    public function requests(int $count, int $averageUs): static
    {
        return $this->state(fn () => ['count' => $count, 'duration_us' => $count * $averageUs]);
    }
}
