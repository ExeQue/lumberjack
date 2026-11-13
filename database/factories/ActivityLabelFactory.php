<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Models\ActivityLabel;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLabel>
 */
class ActivityLabelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'group' => Activity::DEFAULT_GROUP,
            'name' => 'order.placed',
            'label' => 'Order placed',
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    /** Names one activity. */
    public function forActivity(string $group, string $name, string $label): static
    {
        return $this->state(fn () => ['group' => $group, 'name' => $name, 'label' => $label]);
    }

    /** Names a group. */
    public function forGroup(string $group, string $label): static
    {
        return $this->state(fn () => ['group' => $group, 'name' => '', 'label' => $label]);
    }
}
