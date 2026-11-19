<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Ai\Enums\AiCallSource;
use App\Domains\Ai\Enums\AiCallStatus;
use App\Domains\Ai\Enums\AiOperation;
use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCall>
 */
class AiCallFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'provider' => 'anthropic',
            'driver' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'operation' => AiOperation::Text,
            'input_tokens' => fake()->numberBetween(100, 5000),
            'output_tokens' => fake()->numberBetween(50, 1000),
            'duration_ms' => fake()->randomFloat(1, 200, 5000),
            'status' => AiCallStatus::Ok,
            'environment' => 'production',
            'source' => AiCallSource::Manual,
            'occurred_at' => now(),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    /** Made with this key: its project, fingerprint, provider and owner. */
    public function forKey(AiKey $key): static
    {
        return $this->state(fn () => [
            'project_id' => $key->project_id,
            'fingerprint' => $key->fingerprint,
            'provider' => $key->provider ?? 'anthropic',
            'owner_type' => $key->owner_type,
            'owner_id' => $key->owner_id,
        ]);
    }

    /** Caused by this actor: a morph alias or class name, and its key. */
    public function byActor(string $type, string|int $id): static
    {
        return $this->state(fn () => ['actor_type' => $type, 'actor_id' => (string) $id]);
    }

    public function failed(string $error = 'RateLimitedException'): static
    {
        return $this->state(fn () => ['status' => AiCallStatus::Error, 'error' => $error, 'output_tokens' => null]);
    }
}
