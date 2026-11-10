<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Projects\Data\AgentClient;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Projects\Queries\AgentMetricsQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class ListHosts extends Tool
{
    use UsesMcpContext;

    /** Agents per project an answer lists; hosts unseen for a day are forgotten anyway. */
    private const int LIMIT = 100;

    protected string $name = 'list_hosts';

    protected string $description = 'Hosts running the SDK or agent: whether they are online, when last seen, the client and their latest sample (CPU load and cores, memory and disk used and total in bytes).';

    public function __construct(private readonly AgentMetricsQuery $metrics)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $agents = $project->agents()->orderBy('host')->limit(self::LIMIT)->get();
        $latest = $this->metrics->latest($agents);

        return Response::json(['hosts' => $agents->map(fn (ProjectAgent $agent): array => [
            'host' => $agent->host,
            'name' => $agent->name !== '' ? $agent->name : null,
            'client' => AgentClient::fromAgent($agent)->label(),
            'online' => $agent->isOnline(),
            'last_seen_at' => $agent->last_seen_at->toIso8601String(),
            'sample' => isset($latest[$agent->id]) ? [
                'sampled_at' => $latest[$agent->id]['sampled_at'],
                'cpu_load_1m' => $latest[$agent->id]['cpu_load_1m'],
                'cpu_cores' => $latest[$agent->id]['cpu_cores'],
                'memory_used_bytes' => $latest[$agent->id]['memory_used_bytes'],
                'memory_total_bytes' => $latest[$agent->id]['memory_total_bytes'],
                'disk_used_bytes' => $latest[$agent->id]['disk_used_bytes'],
                'disk_total_bytes' => $latest[$agent->id]['disk_total_bytes'],
            ] : null,
        ])->values()->all()]);
    }
}
