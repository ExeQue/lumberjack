<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::DataSources, McpLevel::Read)]
class ListDataSources extends Tool
{
    use UsesScreens;

    protected string $name = 'list_data_sources';

    protected string $description = 'The data sources of the projects this token covers and where each comes from: the project, whether a connected app offers it or Lumberjack fetches an endpoint, the host, and when it last answered. Pick one for a widget by its uuid as the data_source option, with the source external_json (or calendar for an iCalendar feed). Never shows full URLs or headers.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $projects = $this->projects();
        $sources = DataSource::query()->whereIn('project_id', $projects->modelKeys())->whereNotArchived()->with('connection')->orderBy('name')->get()
            ->map(fn (DataSource $source): array => [
                'uuid' => $source->uuid,
                'name' => $source->name,
                'project' => $projects->find($source->project_id)?->slug,
                'format' => $source->format->value,
                'shape' => $source->shape?->value,
                'environment' => $source->environment !== '' ? $source->environment : null,
                'key' => $source->key,
                'origin' => $source->connection !== null ? 'connected_app' : 'endpoint',
                'host' => parse_url($source->connection->url ?? $source->url, PHP_URL_HOST) ?: null,
                'interval_minutes' => $source->interval_minutes,
                'last_ok_at' => $source->last_ok_at?->toIso8601String(),
                'failing' => $source->last_error !== null,
            ]);

        return Response::json(['data_sources' => $sources->all()]);
    }
}
