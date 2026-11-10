<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Search\Http\Requests\SearchRequest;
use App\Domains\Search\Queries\ProjectSearchQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class SearchProject extends Tool
{
    use UsesMcpContext;

    protected string $name = 'search_project';

    protected string $description = 'Searches a project as its command palette does: issues, routes, jobs, commands and scheduled tasks by name (up to 5 each), and a trace when the term is a trace id. Each result has its group, label, detail and page link. For log lines use search_logs.';

    public function __construct(private readonly ProjectSearchQuery $search)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'q' => $schema->string()->required()->description('At least 2 characters.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $validated = $request->validate((new SearchRequest())->rules());

        $token = $this->context()->token();
        // Issues and tasks belong to their own areas: only listed where the token may read those.
        $hidden = array_keys(array_filter([
            'issues' => ! $token->permits('get_issue', $project),
            'schedule' => ! $token->permits('list_scheduled_tasks', $project),
        ]));
        $results = array_values(array_filter(
            $this->search->get($project, trim((string) $validated['q'])),
            fn (array $result): bool => ! in_array($result['group'], $hidden, true),
        ));

        return Response::json(['results' => $results]);
    }
}
