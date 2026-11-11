<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Maintenance\Enums\MaintenanceSource;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open window of production, started ten minutes ago and expected to take thirty.
 *
 * @extends Factory<MaintenanceWindow>
 */
class MaintenanceWindowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'source' => MaintenanceSource::Reported,
            'message' => null,
            'starts_at' => now()->subMinutes(10),
            'expected_end_at' => now()->addMinutes(20),
            'ended_at' => null,
        ];
    }

    public function of(Project $project, string $environment = 'production'): static
    {
        return $this->state(fn () => ['project_id' => $project->id, 'environment' => $environment]);
    }

    public function planned(): static
    {
        return $this->state(fn () => ['source' => MaintenanceSource::Planned, 'starts_at' => now()->addHour(), 'expected_end_at' => now()->addHours(2)]);
    }

    /** Past its expected end and still open. */
    public function overrun(): static
    {
        return $this->state(fn () => ['starts_at' => now()->subHour(), 'expected_end_at' => now()->subMinutes(5)]);
    }

    public function ended(): static
    {
        return $this->state(fn () => ['starts_at' => now()->subHour(), 'expected_end_at' => now()->subMinutes(30), 'ended_at' => now()->subMinutes(40)]);
    }
}
