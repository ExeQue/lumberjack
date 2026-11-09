<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectKeys;

class CreateDataSourceConnectionAction
{
    /**
     * @return string the pull token, shown once
     */
    public function execute(Project $project, string $environment, string $url): string
    {
        $token = ProjectKeys::newPullToken();
        DataSourceConnection::query()->create(['project_id' => $project->id, 'environment' => $environment, 'url' => $url, 'pull_token' => $token]);

        return $token;
    }
}
