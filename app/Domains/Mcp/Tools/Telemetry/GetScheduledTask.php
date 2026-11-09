<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetScheduledTask extends Tool
{
    use UsesMcpContext;

    protected string $name = 'get_scheduled_task';

    protected string $description = 'Recent runs of one scheduled task: status, start, duration in microseconds, exit code, host, trace id and the issue a failed or missed run became.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'name' => $schema->string()->description('The task as list_scheduled_tasks names it.')->required(),
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

        $task = ScheduledTask::query()
            ->where('project_id', $project->id)
            ->where('name', (string) $request->get('name'))
            ->when($environment !== '')->where('environment', $environment)
            ->first();

        if ($task === null) {
            return Response::error('No scheduled task with that name.');
        }

        $runs = $task->runs()->with('occurrence.issue')->latest('started_at')->limit(50)->get();

        return Response::json([
            'task' => ['name' => $task->name, 'environment' => $task->environment !== '' ? $task->environment : null, 'expression' => $task->expression],
            'runs' => $runs->map(fn (ScheduledTaskRun $run): array => [
                'status' => $run->status->value,
                'started_at' => $run->started_at?->toIso8601String(),
                'duration_us' => $run->duration_us,
                'exit_code' => $run->exit_code,
                'host' => $run->host,
                'trace_id' => $run->trace_id,
                'issue' => $run->occurrence?->issue?->uuid,
            ])->all(),
        ]);
    }
}
