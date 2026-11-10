<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Commands\Models\CommandRun;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class GetCommand extends Tool
{
    use UsesMcpContext;

    protected string $name = 'get_command';

    protected string $description = 'Recent runs of one artisan command: the arguments and options it was given, status, exit code, start, duration in microseconds, host, trace id and the issue a failed run became.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'name' => $schema->string()->description('The command name, e.g. reports:send.')->required(),
            'status' => $schema->string()->enum(['running', 'finished', 'failed']),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $status = (string) $request->get('status', '');

        $runs = CommandRun::query()
            ->where('project_id', $project->id)
            ->where('name', (string) $request->get('name'))
            ->when($status !== '')->where('status', $status)
            ->with('occurrence.issue')
            ->latest('id')
            ->limit(50)
            ->get();

        return Response::json(['runs' => $runs->map(fn (CommandRun $run): array => [
            'status' => $run->status->value,
            'arguments' => $run->arguments,
            'options' => $run->options,
            'exit_code' => $run->exit_code,
            'started_at' => $run->started_at?->toIso8601String(),
            'duration_us' => $run->duration_us,
            'host' => $run->host,
            'trace_id' => $run->trace_id,
            'issue' => $run->occurrence?->issue?->uuid,
        ])->all()]);
    }
}
