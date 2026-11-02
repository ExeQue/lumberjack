<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools;

use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Projects\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListProjects extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_projects';

    protected string $description = 'Lists the projects this token can see, with the slug to pass as "project" to other tools.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::json([
            'scope' => $this->context()->token()->scope->value,
            'projects' => $this->context()->projects()->map(fn (Project $p): array => ['slug' => $p->slug, 'name' => $p->name, 'team' => $p->team->name])->values()->all(),
        ]);
    }
}
