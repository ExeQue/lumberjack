<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Health\Queries\HealthChecksQuery;
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
#[RequiresPermission(McpArea::Health, McpLevel::Read)]
class ListHealthChecks extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_health_checks';

    protected string $description = 'The project\'s health checks: reported by the app (by slug, per host) and run by Lumberjack (http, ssl, tcp, dns, heartbeat). Each has its state (ok, recovering, degraded, down, stale, pending), latest message, metrics, duration in microseconds and uptime (0 to 1) over the period. Archived checks are left out.';

    public function __construct(private readonly HealthChecksQuery $health)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d'])->description('For uptime. Defaults to 24h.'),
            'environment' => $schema->string(),
            'attention' => $schema->boolean()->description('Only checks that are degraded, down or stale.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $environment = (string) $request->get('environment', '');
        $rows = collect($this->health->rows($project, Period::tryFrom((string) $request->get('period')) ?? Period::Day, $environment !== '' ? $environment : null))
            ->reject(fn (array $row): bool => $row['archived'])
            ->when((bool) $request->get('attention', false))->filter(fn (array $row): bool => in_array($row['state'], ['degraded', 'down', 'stale'], true));

        return Response::json(['checks' => $rows->map(fn (array $row): array => [
            'id' => $row['uuid'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'source' => $row['source'],
            'type' => $row['type'],
            'environment' => $row['environment'],
            'state' => $row['state'],
            'muted' => $row['muted'],
            'last_result_at' => $row['last_result_at'],
            'last_status' => $row['last_status'],
            'last_message' => $row['last_message'],
            'duration_us' => $row['duration_us'],
            'metrics' => $row['metrics'],
            'uptime' => $row['uptime'],
            'hosts' => $row['hosts'],
        ])->values()->all()]);
    }
}
