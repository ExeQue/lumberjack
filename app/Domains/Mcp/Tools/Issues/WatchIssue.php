<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Issues;

use App\Domains\Issues\Actions\WatchIssueAction;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::Issues, McpLevel::Write)]
class WatchIssue extends Tool
{
    use UsesMcpContext;

    protected string $name = 'watch_issue';

    protected string $description = 'Makes the person this MCP token belongs to watch an issue: they hear of its notes, status and assignee changes, regressions and escalations by mail and toast, as their profile allows.';

    public function __construct(
        private readonly WatchIssueAction $watch,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->description('The issue\'s number as people write it, e.g. DEMO-42, or its id.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {

        $issue = $this->issue($request);

        if ($issue instanceof Response) {
            return $issue;
        }

        $user = $this->context()->token()->user;

        if ($user === null) {
            return Response::error('This token belongs to no person, so nobody can watch through it.');
        }

        $this->watch->execute($issue, $user);

        return Response::json(['issue' => $issue->number !== null ? $issue->reference() : $issue->uuid, 'watching' => $issue->watchers()->whereKey($user->id)->exists()]);
    }
}
