<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Jobs\Queries\QueueStatisticsQuery;
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
class ListQueues extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_queues';

    protected string $description = 'Queues with their latest depth (size), age in seconds of the oldest waiting job, workers and the p95 wait in microseconds of the period\'s jobs. For depth over time use get_metric_series with queue_size.';

    public function __construct(private readonly QueueStatisticsQuery $queues)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d']),
            'environment' => $schema->string(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $environment = (string) $request->get('environment', '');
        $rows = $this->queues->get($project->id, Period::tryFrom((string) $request->get('period')) ?? Period::Day, $environment !== '' ? $environment : null);

        return Response::json(['queues' => array_map(fn (array $q): array => [
            'connection' => $q['connection'],
            'queue' => $q['queue'],
            'size' => $q['size'],
            'oldest_age_s' => $q['oldest_age_s'],
            'workers' => $q['workers'],
            'p95_wait_us' => $q['p95_wait'],
            'sampled_at' => $q['sampled_at'],
        ], $rows)]);
    }
}
