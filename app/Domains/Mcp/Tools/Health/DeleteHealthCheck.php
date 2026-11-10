<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Health;

use App\Domains\Health\Actions\DeleteHealthCheckAction;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesHealthChecks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[RequiresPermission(McpArea::Health, McpLevel::Manage)]
class DeleteHealthCheck extends Tool
{
    use UsesHealthChecks;

    protected string $name = 'delete_health_check';

    protected string $description = 'Deletes a health check with its results and outages; this cannot be undone. A check the app still reports comes back as a new one, so prefer archive_health_check for those.';

    public function __construct(
        private readonly DeleteHealthCheckAction $delete,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'check' => $this->checkArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $check = $this->healthCheck($request, 'update');

        if ($check instanceof Response) {
            return $check;
        }

        $this->delete->execute($check);

        return Response::json(['deleted' => $check->uuid]);
    }
}
