<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPrice>
 */
class AiPriceFactory extends Factory
{
    /**
     * Team-wide by default, valid since long before any test's usage.
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'scope' => AiPriceScope::Project,
            'model_pattern' => 'claude-sonnet-4-5',
            'valid_from' => '2020-01-01 00:00:00',
            'input' => 3,
            'output' => 15,
        ];
    }

    /** For every project of the project's team. */
    public function forTeamOf(Project $project): static
    {
        return $this->state(fn () => ['team_id' => $project->team_id, 'project_id' => null]);
    }

    /** For this project alone. */
    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['team_id' => $project->team_id, 'project_id' => $project->id]);
    }

    /** For one owner's keys in the project. */
    public function forOwner(Project $project, string $type, string|int $id): static
    {
        return $this->forProject($project)->state(fn () => ['scope' => AiPriceScope::Owner, 'scope_type' => $type, 'scope_id' => (string) $id]);
    }

    public function forKey(AiKey $key): static
    {
        return $this->state(fn () => [
            'team_id' => Project::query()->whereKey($key->project_id)->value('team_id'),
            'project_id' => $key->project_id,
            'scope' => AiPriceScope::Key,
            'scope_id' => $key->fingerprint,
        ]);
    }

    /** A model name, or a glob with *. */
    public function model(string $pattern, ?string $provider = null): static
    {
        return $this->state(fn () => ['model_pattern' => $pattern, 'provider' => $provider]);
    }

    public function validFrom(DateTimeInterface|string $at): static
    {
        return $this->state(fn () => ['valid_from' => $at]);
    }

    /** Per million tokens. */
    public function costing(float $input, float $output, ?float $cacheRead = null, ?float $cacheWrite = null, ?float $reasoning = null): static
    {
        return $this->state(fn () => ['input' => $input, 'output' => $output, 'cache_read' => $cacheRead, 'cache_write' => $cacheWrite, 'reasoning' => $reasoning]);
    }
}
