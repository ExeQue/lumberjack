<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectKeys;

class RotatePublicKeyAction
{
    public function execute(Project $project): string
    {
        $project->update(['public_key' => ProjectKeys::newPublicKey()]);

        return $project->public_key;
    }
}
