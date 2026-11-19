<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageDaily>
 */
class AiUsageDailyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'day' => today(),
            'calls' => 1,
            'input_tokens' => 1000,
            'output_tokens' => 200,
            'duration_ms_sum' => 1500,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    /** Calls made with this key: its project, fingerprint and provider. */
    public function forKey(AiKey $key): static
    {
        return $this->state(fn () => [
            'project_id' => $key->project_id,
            'fingerprint' => $key->fingerprint,
            'provider' => $key->provider ?? 'anthropic',
            'owner_type' => $key->owner_type ?? '',
            'owner_id' => $key->owner_id ?? '',
        ]);
    }

    /** A tenant's own key, brought to the app: a morph alias or class name, and its key. */
    public function ownedBy(string $type, string|int $id): static
    {
        return $this->state(fn () => ['owner_type' => $type, 'owner_id' => (string) $id]);
    }

    /** Caused by this actor: a morph alias or class name, and its key. */
    public function byActor(string $type, string|int $id): static
    {
        return $this->state(fn () => ['actor_type' => $type, 'actor_id' => (string) $id]);
    }
}
