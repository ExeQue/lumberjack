<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Ai\Models\AiKey;
use App\Domains\Projects\Models\Project;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiKey>
 */
class AiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            // Stands in for an HMAC of a key's hash; nothing derives it here.
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'provider' => 'anthropic',
            'driver' => 'anthropic',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    public function labeled(string $label): static
    {
        return $this->state(fn () => ['label' => $label]);
    }

    /** A tenant's own key, brought to the app: a morph alias or class name, and its key. */
    public function ownedBy(string $type, string|int $id): static
    {
        return $this->state(fn () => ['owner_type' => $type, 'owner_id' => (string) $id]);
    }

    /** Last used on this day, and not since. */
    public function lastSeen(DateTimeInterface $at): static
    {
        return $this->state(fn () => ['first_seen_at' => $at, 'last_seen_at' => $at]);
    }
}
