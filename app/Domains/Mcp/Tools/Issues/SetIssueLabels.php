<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Issues;

use App\Domains\Issues\Actions\SetIssueLabelsAction;
use App\Domains\Issues\Models\Label;
use App\Domains\Issues\Presenters\IssuePresenter;
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
class SetIssueLabels extends Tool
{
    use UsesMcpContext;

    protected string $name = 'set_issue_labels';

    protected string $description = 'Gives an issue exactly these labels, by name; an empty list removes them all. A name the project has no label for yet creates one. Up to 10 labels of at most 32 characters.';

    public function __construct(
        private readonly SetIssueLabelsAction $action,
        private readonly IssuePresenter $presenter,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->description('The issue\'s number as people write it, e.g. DEMO-42, or its id.')->required(),
            'labels' => $schema->array()->items($schema->string())->description('Label names, e.g. ["payments", "p1"].')->required(),
        ];
    }

    public function handle(Request $request): Response
    {

        $request->validate(['labels' => ['present', 'array', 'max:'.Label::MAX_PER_ISSUE], 'labels.*' => ['string', 'max:'.Label::MAX_NAME]]);
        $issue = $this->issue($request, 'update');

        if ($issue instanceof Response) {
            return $issue;
        }

        $this->action->execute($issue, array_values(array_map('strval', (array) $request->get('labels'))), token: $this->context()->token());

        return Response::json($this->presenter->summary($issue->load('labels')));
    }
}
