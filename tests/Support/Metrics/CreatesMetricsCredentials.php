<?php

declare(strict_types=1);

namespace Tests\Support\Metrics;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectKeys;
use Illuminate\Support\Str;

trait CreatesMetricsCredentials
{
    /**
     * A plain team token (an MCP token, read scope) covering the given projects of one team.
     */
    public function createMetricsToken(Project ...$projects): string
    {
        $plain = 'lj_mcp_'.Str::random(40);
        $token = McpToken::factory()->readOnly()->withToken($plain)->create(['team_id' => $projects[0]->team_id]);
        $token->projects()->sync(array_map(fn (Project $p): int => $p->id, $projects));

        return $plain;
    }

    /**
     * Turns public stats on for the project and returns its key.
     */
    public function enablePublicStats(Project $project, bool $includeTitles = false): string
    {
        $project->update(['stats_key' => ProjectKeys::newStatsKey(), 'stats_include_titles' => $includeTitles]);

        return (string) $project->stats_key;
    }
}
