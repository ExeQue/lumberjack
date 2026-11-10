<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Issues;

use App\Domains\Issues\Models\Label;
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
class ListLabels extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_labels';

    protected string $description = 'The project\'s issue labels with their id, name, color and how many issues carry each.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        return Response::json(['labels' => $project->labels()->withCount('issues')->orderBy('name')->get()
            ->map(fn (Label $label): array => [...$label->toSummary(), 'issues' => (int) $label->issues_count])->all()]);
    }
}
