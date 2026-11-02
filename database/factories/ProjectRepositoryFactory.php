<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Projects\Models\Project;
use App\Domains\Repositories\Enums\RepositoryProvider;
use App\Domains\Repositories\Models\ProjectRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectRepository>
 */
class ProjectRepositoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'provider' => RepositoryProvider::GitHub,
            'repository' => 'acme/example',
            'token' => 'github_pat_test',
            'labels' => ['lumberjack'],
            'allow_public' => false,
        ];
    }
}
