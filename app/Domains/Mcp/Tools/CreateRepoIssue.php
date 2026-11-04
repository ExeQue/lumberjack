<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools;

use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Repositories\Actions\CreateRepoIssueAction;
use App\Domains\Repositories\Exceptions\RepositoryExceptionInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class CreateRepoIssue extends Tool
{
    use UsesMcpContext;

    protected string $name = 'create_repo_issue';

    protected string $description = 'Creates an issue in the project\'s linked repository (GitHub) with your title and body; Lumberjack adds the stacktrace, environment, occurrence count and a link back. Idempotent: an issue that already has one returns its URL. Needs the write scope.';

    public function __construct(private readonly CreateRepoIssueAction $action)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->description('The issue\'s number as people write it, e.g. DEMO-42, or its id.')->required(),
            'title' => $schema->string()->description('Defaults to the exception and message.'),
            'body' => $schema->string()->description('Markdown: your analysis and suggested fix.'),
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

        try {
            $url = $this->action->execute($issue, $request->get('title'), $request->get('body'), token: $this->context()->token());
        } catch (RepositoryExceptionInterface $e) {
            return Response::error($e->getMessage());
        }

        return Response::json(['url' => $url]);
    }
}
