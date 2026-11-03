<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools;

use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetIssue extends Tool
{
    use UsesMcpContext;

    protected string $name = 'get_issue';

    protected string $description = 'One issue with its latest occurrence (stacktrace with code lines, request and user context) and its notes.';

    public function __construct(private readonly IssuePresenter $presenter)
    {
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

        $issue->loadMissing('labels');
        $occurrence = $issue->occurrences()->latest('occurred_at')->first();

        return Response::json([
            ...$this->presenter->summary($issue),
            'latest_occurrence' => $occurrence !== null ? $this->presenter->occurrence($occurrence) : null,
            'history' => $this->presenter->history($issue, 50),
            'notes' => $issue->notes()->with(['user', 'mcpToken'])->latest()->limit(20)->get()->map(fn (IssueNote $n): array => $this->presenter->note($n))->all(),
        ]);
    }
}
