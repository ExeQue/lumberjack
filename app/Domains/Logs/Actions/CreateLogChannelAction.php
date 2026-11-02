<?php

declare(strict_types=1);

namespace App\Domains\Logs\Actions;

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Models\Project;

class CreateLogChannelAction
{
    /**
     * Name is validated by the caller's request: kebab-case, unique, not "default".
     */
    public function execute(Project $project, string $name): LogChannel
    {
        $channel = $project->logChannels()->create(['name' => $name]);

        // Lines that arrived for this name before it existed were kept in default;
        // they stay there, the channel only collects from now on.
        return $channel;
    }
}
