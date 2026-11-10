<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Health;

use App\Domains\Health\Actions\ArchiveHealthCheckAction;
use App\Domains\Health\Http\Requests\ArchiveHealthCheckRequest;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesHealthChecks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::Health, McpLevel::Write)]
class ArchiveHealthCheck extends Tool
{
    use UsesHealthChecks;

    protected string $name = 'archive_health_check';

    protected string $description = 'Archives a health check (hidden from list_health_checks, no alerts, history kept) or, with archived false, brings it back. A reported check that reports again comes back by itself.';

    public function __construct(
        private readonly ArchiveHealthCheckAction $archive,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'check' => $this->checkArgument($schema),
            'archived' => $schema->boolean()->description('False to unarchive.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $check = $this->healthCheck($request, 'update');

        if ($check instanceof Response) {
            return $check;
        }

        $input = $request->validate(new ArchiveHealthCheckRequest()->rules());
        $this->archive->execute($check, filter_var($input['archived'], FILTER_VALIDATE_BOOLEAN));

        return Response::json(['check' => $check->uuid, 'archived' => $check->archived_at !== null]);
    }
}
