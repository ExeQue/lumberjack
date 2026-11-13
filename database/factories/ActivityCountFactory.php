<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Models\ActivityCount;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityCount>
 */
class ActivityCountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'group' => Activity::DEFAULT_GROUP,
            'name' => 'order.placed',
            'hour' => now()->startOfHour(),
            'count' => 1,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    public function named(string $group, string $name): static
    {
        return $this->state(fn () => ['group' => $group, 'name' => $name]);
    }
}
