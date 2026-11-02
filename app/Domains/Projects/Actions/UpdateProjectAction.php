<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Projects\Models\Project;

class UpdateProjectAction
{
    /**
     * @param  list<string>  $allowedOrigins
     * @param  list<string>  $scrubKeys  extra secret fields on top of the defaults
     */
    public function execute(Project $project, string $name, string $slug, array $allowedOrigins, array $scrubKeys = []): Project
    {
        $project->update([
            'name' => $name,
            'slug' => $slug,
            'allowed_origins' => array_values(array_unique(array_map(fn (string $o): string => rtrim($o, '/'), $allowedOrigins))),
            'scrub_keys' => array_values(array_unique(array_filter(array_map(fn (string $k): string => strtolower(trim($k)), $scrubKeys)))),
        ]);

        return $project;
    }
}
