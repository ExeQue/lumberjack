<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Projects\Data\AgentClient;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;

class RecordAgentHeartbeatAction
{
    public function execute(Project $project, string $host, AgentClient $client, ?string $name = null): ProjectAgent
    {
        return $project->agents()->updateOrCreate(
            ['host' => mb_substr($host, 0, 255), 'name' => mb_substr(trim((string) $name), 0, 64)],
            [...$client->toColumns(), 'last_seen_at' => now()],
        );
    }
}
