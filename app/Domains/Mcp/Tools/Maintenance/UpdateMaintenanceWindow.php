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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::Health, McpLevel::Write)]
class UpdateMaintenanceWindow extends Tool
{
    use UsesMaintenanceWindows;

    protected string $name = 'update_maintenance_window';

    protected string $description = 'Changes a maintenance window, planned or reported by the app; pass only what changes (null clears the message). Moving ends_at past now starts its overrun alerts over. Returns the window.';

    public function __construct(
        private readonly SaveMaintenanceWindowAction $save,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'window' => $this->windowArgument($schema),
            ...$this->windowSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $window = $this->maintenanceWindow($request, 'update');

        if ($window instanceof Response) {
            return $window;
        }

        $saved = $this->saveWindow($this->save, $window->project, $window, [
            'environment' => $window->environment,
            'starts_at' => $window->starts_at->toIso8601String(),
            'ends_at' => $window->expected_end_at->toIso8601String(),
            'message' => $window->message,
            ...$request->all(),
        ]);

        return Response::json(['window' => $saved->toSummary()]);
    }
}
