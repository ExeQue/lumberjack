<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectAgent>
 */
class ProjectAgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'host' => 'web-'.fake()->unique()->numberBetween(1, 99),
            'sdk' => 'lumberjack.php.laravel',
            'sdk_version' => '0.1.0',
            'last_seen_at' => now(),
        ];
    }

    public function offline(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subMinutes(30)]);
    }

    /** The Lumberjack agent of a Laravel app on this host, as its heartbeat describes it. */
    public function onHost(string $host): static
    {
        return $this->state(fn () => [
            'host' => $host,
            'name' => 'lumberjack-agent',
            'sdk' => 'lumberjack.php.laravel',
            'sdk_version' => '1.6.2',
            'agent' => 'lumberjack-agent-php',
            'agent_version' => '1.6.2',
            'runtime' => 'php',
            'runtime_version' => '8.4.14',
            'framework' => 'laravel',
            'framework_version' => '12.38.1',
        ]);
    }
}
