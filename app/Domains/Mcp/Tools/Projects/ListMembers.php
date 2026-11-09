<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Projects;

use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListMembers extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_members';

    protected string $description = 'Members of the team behind a project, with the user id to pass to assign_issue.';

    public function schema(JsonSchema $schema): array
    {
        return ['project' => $this->projectArgument($schema)];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        return Response::json(['members' => $project->team->members()->orderBy('name')->get()
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'role' => $u->pivot->role->value])->all()]);
    }
}
