<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Performance\Queries\TraceQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetTrace extends Tool
{
    use UsesMcpContext;

    protected string $name = 'get_trace';

    protected string $description = 'Everything recorded under one trace id: the request or job, then queries, spans (cache reads and writes, outgoing HTTP calls, mail, notifications, Redis commands), log lines and exceptions in time order, each with offset_us from the start of the trace.';

    public function __construct(private readonly TraceQuery $trace)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'trace_id' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        return Response::json($this->trace->get($project->id, (string) $request->get('trace_id')));
    }
}
