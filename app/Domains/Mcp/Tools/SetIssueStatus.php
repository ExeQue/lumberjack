<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools;

use App\Domains\Issues\Actions\ChangeIssueStatusAction;
use App\Domains\Issues\Data\IgnoreCondition;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class SetIssueStatus extends Tool
{
    use UsesMcpContext;

    protected string $name = 'set_issue_status';

    protected string $description = 'Resolves, ignores, re-opens or marks an issue in progress (someone is working on it; it still counts as open). Needs the write scope.';

    public function __construct(
        private readonly ChangeIssueStatusAction $action,
        private readonly IssuePresenter $presenter,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->required(),
            'status' => $schema->string()->enum(['open', 'in_progress', 'resolved', 'ignored'])->required(),
            'note' => $schema->string()->description('Optional comment, e.g. why it is resolved or ignored; added as a note.'),
            'until' => $schema->string()->enum(IgnoreCondition::CHOICES)->description('For ignored: when it comes back on its own. Defaults to escalation (a sudden spike); or 1h/24h/7d, count:N more occurrences, or forever.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessWrite()) {
            return $denied;
        }

        $request->validate(['status' => ['required', 'in:open,in_progress,resolved,ignored'], 'note' => ['nullable', 'string', 'max:20000'], 'until' => ['nullable', 'in:'.implode(',', IgnoreCondition::CHOICES)]]);
        $issue = $this->issue($request);

        if ($issue instanceof Response) {
            return $issue;
        }

        $note = $request->get('note');
        $issue = $this->action->execute($issue, IssueStatus::from((string) $request->get('status')), comment: is_string($note) ? $note : null, token: $this->context()->token(), until: IgnoreCondition::fromChoice(is_string($u = $request->get('until')) ? $u : null));

        return Response::json($this->presenter->summary($issue));
    }
}
