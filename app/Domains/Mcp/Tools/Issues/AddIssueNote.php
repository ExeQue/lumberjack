<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Issues;

use App\Domains\Issues\Actions\AddIssueNoteAction;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddIssueNote extends Tool
{
    use UsesMcpContext;

    protected string $name = 'add_issue_note';

    protected string $description = 'Adds a note to an issue, for example a triage result: severity, probable cause, affected files. The first line is used as summary in alerts. Tag a team member with <@id> (ids from list_members); they get a mail. <@mcp:id> tags an agent by its MCP token; it sees the note in triage_inbox. Needs the write scope.';

    public function __construct(
        private readonly AddIssueNoteAction $action,
        private readonly IssuePresenter $presenter,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->description('The issue\'s number as people write it, e.g. DEMO-42, or its id.')->required(),
            'body' => $schema->string()->description('Markdown. Keep the first line a one-sentence summary. <@id> tags a team member.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessWrite()) {
            return $denied;
        }

        $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $issue = $this->issue($request, 'update');

        if ($issue instanceof Response) {
            return $issue;
        }

        $note = $this->action->execute($issue, (string) $request->get('body'), token: $this->context()->token());

        return Response::json($this->presenter->note($note->load(['user', 'mcpToken'])));
    }
}
