<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\DataSources;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Jobs\FetchDataSource as FetchDataSourceJob;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesDataSources;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::DataSources, McpLevel::Write)]
class FetchDataSource extends Tool
{
    use UsesDataSources;

    protected string $name = 'fetch_data_source';

    protected string $description = 'Queues a fetch of a data source now, instead of waiting for its interval. Read the result shortly after with preview_data_source or list_data_sources.';

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

        if ($source->format === DataSourceFormat::Connected) {
            return Response::error('A connected source is pulled while a screen shows it; it cannot be fetched by hand.');
        }

        FetchDataSourceJob::dispatch($source);

        return Response::json(['uuid' => $source->uuid, 'queued' => true]);
    }
}
