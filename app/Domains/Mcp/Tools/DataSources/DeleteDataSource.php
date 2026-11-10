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
class DeleteDataSource extends Tool
{
    use UsesDataSources;

    protected string $name = 'delete_data_source';

    protected string $description = 'Deletes a fetched data source, or a connected one its app no longer offers, with its history. Widgets that show it go empty.';

    public function schema(JsonSchema $schema): array
    {
        return $this->dataSourceArguments($schema);
    }

    public function handle(Request $request): Response
    {
        $source = $this->dataSource($request, 'update');

        if ($source instanceof Response) {
            return $source;
        }

        if (! $source->isDeletable()) {
            return Response::error('A connected source comes and goes with its app; only one the app no longer offers can be deleted.');
        }

        $source->delete();

        return Response::json(['uuid' => $source->uuid, 'deleted' => true]);
    }
}
