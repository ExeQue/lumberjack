<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class ListRoutes extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_routes';

    protected string $description = 'Routes with request count, p50/p95/p99 duration in microseconds and 5xx error rate. Use search to narrow to one route.';

    public function __construct(private readonly RouteStatisticsQuery $routes)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d']),
            'environment' => $schema->string(),
            'sort' => $schema->string()->enum(['p95', 'count', 'errors', 'total']),
            'search' => $schema->string(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        return Response::json(['routes' => $this->routes->get(
            $project->id,
            Period::tryFrom((string) $request->get('period')) ?? Period::Day,
            $request->get('environment'),
            (string) $request->get('sort', 'p95'),
            $request->get('search'),
            50,
        )]);
    }
}
