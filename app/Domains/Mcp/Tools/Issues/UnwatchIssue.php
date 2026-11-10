<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Issues;

use App\Domains\Issues\Actions\UnwatchIssueAction;
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
class UnwatchIssue extends Tool
{
    use UsesMcpContext;

    protected string $name = 'unwatch_issue';

    protected string $description = 'Stops the person this MCP token belongs to watching an issue. It sticks: acting on the issue later does not make them watch it again.';

    public function __construct(
        private readonly UnwatchIssueAction $unwatch,
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

        $this->unwatch->execute($issue, $user);

        return Response::json(['issue' => $issue->number !== null ? $issue->reference() : $issue->uuid, 'watching' => $issue->watchers()->whereKey($user->id)->exists()]);
    }
}
