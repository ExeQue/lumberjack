<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Maintenance;

use App\Domains\Maintenance\Actions\EndMaintenanceWindowAction;
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
class EndMaintenanceWindow extends Tool
{
    use UsesMaintenanceWindows;

    protected string $name = 'end_maintenance_window';

    protected string $description = 'Ends a maintenance window now; a planned one that has not started ends where it would have started. Checks still down send their alerts again, and an overrun window that was alerted on sends a recovery message. Returns the window.';

    public function __construct(
        private readonly EndMaintenanceWindowAction $end,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'window' => $this->windowArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $window = $this->maintenanceWindow($request, 'update');

        if ($window instanceof Response) {
            return $window;
        }

        $this->end->execute($window);

        return Response::json(['window' => $window->toSummary()]);
    }
}
