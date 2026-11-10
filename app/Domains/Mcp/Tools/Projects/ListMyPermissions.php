<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Projects;

use App\Domains\Mcp\Attributes\AlwaysAvailable;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Support\McpToolCatalog;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Projects\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AlwaysAvailable]
class ListMyPermissions extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_my_permissions';

    protected string $description = 'What this token may do: its level per area, the tools it may use in each of its projects, and the team-wide tools (screens, alerts, team). A tool missing here is refused; ask a team admin to change the token. Its owner\'s own permissions still apply on top.';

    public function __construct(private readonly McpToolCatalog $catalog)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $token = $this->context()->token();
        $tools = collect($this->catalog->all())->filter(fn (array $tool): bool => $tool['area'] !== null);
        [$teamWide, $perProject] = $tools->partition(fn (array $tool): bool => $tool['area']->isTeamWide());

        return Response::json([
            'all_projects' => $token->all_projects,
            'levels' => collect(McpArea::cases())->mapWithKeys(fn (McpArea $area): array => [$area->value => $token->levelFor($area)->value])->all(),
            'team_tools' => $teamWide->keys()->filter(fn (string $name): bool => $token->permits($name))->values()->all(),
            'projects' => $this->context()->projects()->map(fn (Project $project): array => [
                'slug' => $project->slug,
                'tools' => $perProject->keys()->filter(fn (string $name): bool => $token->permits($name, $project))->values()->all(),
            ])->values()->all(),
        ]);
    }
}
