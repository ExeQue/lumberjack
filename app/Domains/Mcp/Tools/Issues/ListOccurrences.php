<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Issues;

use App\Domains\Issues\Models\Occurrence;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Issues, McpLevel::Read)]
class ListOccurrences extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_occurrences';

    protected string $description = 'Recent occurrences of an issue with environment, release, host and trace id. Stacktraces are left out; use get_issue for the latest one.';

    public function __construct(private readonly IssuePresenter $presenter)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->string()->description('The issue\'s number as people write it, e.g. DEMO-42, or its id.')->required(),
            'limit' => $schema->integer()->min(1)->max(100)->description('Defaults to 20.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $issue = $this->issue($request);

        if ($issue instanceof Response) {
            return $issue;
        }

        $occurrences = $issue->occurrences()->latest('occurred_at')->limit(min(100, max(1, (int) $request->get('limit', 20))))->get();

        return Response::json(['occurrences' => $occurrences->map(fn (Occurrence $o): array => array_diff_key($this->presenter->occurrence($o), ['stacktrace' => true]))->all()]);
    }
}
