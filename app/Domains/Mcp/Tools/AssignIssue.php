<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools;

use App\Domains\Issues\Actions\AssignIssueAction;
use App\Domains\Issues\Exceptions\IssueExceptionInterface;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class AssignIssue extends Tool
{
    use UsesMcpContext;

    protected string $name = 'assign_issue';

    protected string $description = 'Assigns an issue to a team member (see list_members), or clears the assignee when user_id is omitted. Needs the write scope.';

    public function __construct(
        private readonly AssignIssueAction $action,
        private readonly IssuePresenter $presenter,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->description('The issue\'s number as people write it, e.g. DEMO-42, or its id.')->required(),
            'user_id' => $schema->integer()->description('Omit to unassign.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessWrite()) {
            return $denied;
        }

        $issue = $this->issue($request, 'update');

        if ($issue instanceof Response) {
            return $issue;
        }

        $userId = $request->get('user_id');

        try {
            $issue = $this->action->execute($issue, $userId !== null ? User::query()->find((int) $userId) : null, token: $this->context()->token());
        } catch (IssueExceptionInterface $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($this->presenter->summary($issue->load('assignee')));
    }
}
