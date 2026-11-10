<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Jobs\Queries\JobStatisticsQuery;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class ListJobs extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_jobs';

    protected string $description = 'Job classes with runs, failure rate, stuck and retried counts, p50/p95 duration and p95 wait time in microseconds.';

    public function __construct(private readonly JobStatisticsQuery $jobs)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d']),
            'environment' => $schema->string(),
            'queue' => $schema->string(),
            'sort' => $schema->string()->enum(['errors', 'count', 'duration', 'wait']),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        return Response::json(['jobs' => $this->jobs->get(
            $project->id,
            Period::tryFrom((string) $request->get('period')) ?? Period::Day,
            $request->get('environment'),
            (string) $request->get('sort', 'errors'),
            $request->get('queue'),
            50,
        )]);
    }
}
