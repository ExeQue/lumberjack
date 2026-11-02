<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectKeys;

class RotateServerTokenAction
{
    /**
     * Returns the new plain token; it is shown once and never stored.
     */
    public function execute(Project $project): string
    {
        $token = ProjectKeys::newServerToken();

        $project->update([
            'token_hash' => ProjectKeys::hash($token),
            'token_last_four' => substr($token, -4),
        ]);

        return $token;
    }
}
