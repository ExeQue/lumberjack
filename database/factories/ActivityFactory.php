<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Activity\Models\Activity;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'group' => Activity::DEFAULT_GROUP,
            'name' => 'order.placed',
            'payload' => ['total' => fake()->numberBetween(100, 5000)],
            'environment' => 'production',
            'occurred_at' => now(),
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

    /** Done by this actor: a morph alias or class name, and its key. */
    public function byActor(string $type, string|int $id): static
    {
        return $this->state(fn () => ['actor_type' => $type, 'actor_id' => (string) $id]);
    }

    /** Done to this subject: a morph alias or class name, and its key. */
    public function onSubject(string $type, string|int $id): static
    {
        return $this->state(fn () => ['subject_type' => $type, 'subject_id' => (string) $id]);
    }
}
