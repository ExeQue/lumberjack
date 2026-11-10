<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\DataSources;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesDataSources;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[RequiresPermission(McpArea::DataSources, McpLevel::Manage)]
class DeleteDataSourceConnection extends Tool
{
    use UsesDataSources;

    protected string $name = 'delete_data_source_connection';

    protected string $description = 'Disconnects an app environment and removes the sources it offers. Widgets that show them go empty.';

    public function schema(JsonSchema $schema): array
    {
        return $this->connectionArguments($schema);
    }

    public function handle(Request $request): Response
    {
        $connection = $this->connection($request);

        if ($connection instanceof Response) {
            return $connection;
        }

        $connection->delete();

        return Response::json(['uuid' => $connection->uuid, 'deleted' => true]);
    }
}
