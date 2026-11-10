<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Schedule;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Schedule\Actions\DeleteScheduledTaskAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[RequiresPermission(McpArea::Schedule, McpLevel::Manage)]
class DeleteScheduledTask extends Tool
{
    use UsesMcpContext;

    protected string $name = 'delete_scheduled_task';

    protected string $description = 'Forgets a scheduled task taken off the app\'s schedule, with its runs; this cannot be undone. If the app still runs it, it comes back with its next run.';

    public function __construct(
        private readonly DeleteScheduledTaskAction $delete,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'name' => $schema->string()->description('The task as list_scheduled_tasks names it.')->required(),
            'environment' => $schema->string()->description('Required when the task runs in more than one environment.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $denied = $this->authorize('update', $project);

        if ($denied !== null) {
            return $denied;
        }

        $environment = (string) $request->get('environment', '');
        $tasks = $project->scheduledTasks()
            ->where('name', (string) $request->get('name'))
            ->when($request->get('environment') !== null)->where('environment', $environment)
            ->get();

        if ($tasks->isEmpty()) {
            return Response::error('No scheduled task with that name; see list_scheduled_tasks.');
        }

        if ($tasks->count() > 1) {
            return Response::error('That task runs in several environments; pass "environment" with one of: '.$tasks->pluck('environment')->implode(', '));
        }

        $task = $tasks->first();
        $this->delete->execute($task);

        return Response::json(['deleted' => ['name' => $task->name, 'environment' => $task->environment !== '' ? $task->environment : null]]);
    }
}
