<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Maintenance;

use App\Domains\Maintenance\Actions\SaveMaintenanceWindowAction;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMaintenanceWindows;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Health, McpLevel::Write)]
class CreateMaintenanceWindow extends Tool
{
    use UsesMaintenanceWindows;

    protected string $name = 'create_maintenance_window';

    protected string $description = 'Plans a maintenance window for one environment, or every environment when none is given. While it is open, health checks and queues are muted and errors are flagged as under maintenance; it only ends through end_maintenance_window or the app\'s end event. Returns the window.';

    public function __construct(
        private readonly SaveMaintenanceWindowAction $save,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = $this->windowSchema($schema);

        return [
            'project' => $this->projectArgument($schema),
            ...$fields,
            'starts_at' => $fields['starts_at']->required(),
            'ends_at' => $fields['ends_at']->required(),
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

        $window = $this->saveWindow($this->save, $project, null, $request->all());

        return Response::json(['window' => $window->toSummary()]);
    }
}
