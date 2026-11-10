<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Schedule\Models\ScheduledTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Schedule, McpLevel::Read)]
class ListScheduledTasks extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_scheduled_tasks';

    protected string $description = 'The app\'s scheduled tasks per environment: cron expression, last run, its status (finished, failed, skipped, missed) and duration in microseconds, and the next expected run. A missed task is one the scheduler did not run within 5 minutes of its time.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'environment' => $schema->string(),
            'attention' => $schema->boolean()->description('Only tasks whose last run failed or was missed.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $environment = (string) $request->get('environment', '');

        $tasks = ScheduledTask::query()
            ->where('project_id', $project->id)
            ->when($environment !== '')->where('environment', $environment)
            ->when((bool) $request->get('attention', false))->whereNeedsAttention()
            ->orderBy('name')
            ->get();

        return Response::json(['tasks' => $tasks->map(fn (ScheduledTask $task): array => [
            'name' => $task->name,
            'command' => $task->command,
            'description' => $task->description,
            'environment' => $task->environment !== '' ? $task->environment : null,
            'expression' => $task->expression,
            'timezone' => $task->timezone,
            'last_status' => $task->last_status?->value,
            'last_run_at' => $task->last_run_at?->toIso8601String(),
            'last_duration_us' => $task->last_duration_us,
            'next_run_at' => $task->next_run_at?->toIso8601String(),
        ])->all()]);
    }
}
