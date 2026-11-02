<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->domainWord();
        $token = 'lj_srv_'.Str::random(40);

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'token_hash' => hash('sha256', $token),
            'token_last_four' => substr($token, -4),
            'public_key' => 'pk_'.Str::lower(Str::random(24)),
            'allowed_origins' => ['https://'.$name.'.test'],
        ];
    }

    /**
     * Every project has its default log channel, as CreateProjectAction makes it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            $project->logChannels()->firstOrCreate(['name' => LogChannel::DEFAULT]);
        });
    }

    /**
     * A project whose server token is known to the test.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => hash('sha256', $token), 'token_last_four' => substr($token, -4)]);
    }
}
