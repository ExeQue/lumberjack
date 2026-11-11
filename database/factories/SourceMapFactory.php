<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<SourceMap>
 */
class SourceMapFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'path' => '/build/assets/app-'.fake()->unique()->bothify('????????').'.js',
            'release' => null,
            'size' => 809,
        ];
    }

    /** The map file on the default disk too, with the given content. */
    public function withFile(string $content): static
    {
        return $this->state(['size' => strlen($content)])
            ->afterCreating(fn (SourceMap $map) => Storage::put($map->filePath(), $content));
    }
}
