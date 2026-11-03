<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools;

use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Overview\Queries\ProjectOverviewQuery;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetProjectOverview extends Tool
{
    use UsesMcpContext;

    protected string $name = 'get_project_overview';

    protected string $description = 'Project overview: requests, error rate, p50/p95/p99 (microseconds), job failures, health check counts (ok, degraded, down, stale), newest issues, slowest routes and failed jobs for a period.';

    public function __construct(private readonly ProjectOverviewQuery $overview)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d'])->description('Defaults to 24h.'),
            'environment' => $schema->string()->description('Only this environment, e.g. production.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        return Response::json($this->overview->get($project, Period::tryFrom((string) $request->get('period')) ?? Period::Day, $request->get('environment')));
    }
}
