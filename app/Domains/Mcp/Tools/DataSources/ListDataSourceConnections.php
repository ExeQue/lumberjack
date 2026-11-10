<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\DataSources;

use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesDataSources;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::DataSources, McpLevel::Read)]
class ListDataSourceConnections extends Tool
{
    use UsesDataSources;

    protected string $name = 'list_data_source_connections';

    protected string $description = 'The apps a project pulls data sources from, one per environment: host, time zone, status (waiting, failing or connected), when it last answered and why it fails. Never shows URLs or pull tokens.';

    public function schema(JsonSchema $schema): array
    {
        return ['project' => $this->projectArgument($schema)];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        return Response::json(['connections' => $project->dataSourceConnections()->orderBy('environment')->get()
            ->map(fn (DataSourceConnection $connection): array => $this->connectionSummary($connection))
            ->all()]);
    }
}
