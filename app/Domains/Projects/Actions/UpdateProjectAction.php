<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Projects\Models\Project;

class UpdateProjectAction
{
    /**
     * @param  list<string>  $allowedOrigins
     * @param  list<string>  $scrubKeys  extra secret fields on top of the defaults
     * @param  int|null  $maintenanceExpectedMinutes  null keeps it, as does $maintenanceRepeatMinutes
     */
    public function execute(Project $project, string $name, string $slug, array $allowedOrigins, array $scrubKeys = [], ?string $issuePrefix = null, ?int $maintenanceExpectedMinutes = null, ?int $maintenanceRepeatMinutes = null): Project
    {
        $project->update([
            'name' => $name,
            'slug' => $slug,
            'issue_prefix' => $issuePrefix ?? $project->issuePrefix(),
            'allowed_origins' => array_values(array_unique(array_map(fn (string $o): string => rtrim($o, '/'), $allowedOrigins))),
            'scrub_keys' => array_values(array_unique(array_filter(array_map(fn (string $k): string => strtolower(trim($k)), $scrubKeys)))),
            'maintenance_expected_minutes' => $maintenanceExpectedMinutes ?? $project->maintenance_expected_minutes,
            'maintenance_repeat_minutes' => $maintenanceRepeatMinutes ?? $project->maintenance_repeat_minutes,
        ]);

        return $project;
    }
}
