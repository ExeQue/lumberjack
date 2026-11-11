<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Maintenance;

use App\Domains\Maintenance\Models\MaintenanceWindow;
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
#[RequiresPermission(McpArea::Health, McpLevel::Read)]
class ListMaintenanceWindows extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_maintenance_windows';

    protected string $description = 'The project\'s maintenance windows, planned or reported by the app, newest first. Each has its state: planned (not started), open, overrun (open past its expected end) or ended. Health checks and queues are muted while one is open. By default the windows not ended yet and the last 5 that ended.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'environment' => $schema->string()->description('Only windows covering it, including those for every environment.'),
            'state' => $schema->string()->enum(['open', 'planned', 'ended', 'all'])->description('open includes overrun. ended and all return the last 50.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $query = fn () => MaintenanceWindow::query()->where('project_id', $project->id)->whereCovers((string) $request->get('environment', ''))->latest('starts_at');

        $windows = match ($request->get('state')) {
            'open' => $query()->whereOpen()->get(),
            'planned' => $query()->whereNotEnded()->where('starts_at', '>', now())->get(),
            'ended' => $query()->whereNotNull('ended_at')->limit(50)->get(),
            'all' => $query()->limit(50)->get(),
            default => $query()->whereNotEnded()->get()->concat($query()->whereNotNull('ended_at')->limit(5)->get()),
        };

        return Response::json(['windows' => $windows->map(fn (MaintenanceWindow $window): array => $window->toSummary())->values()->all()]);
    }
}
