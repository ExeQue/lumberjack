<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Projects;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Projects\Actions\UpdateProjectAction;
use App\Domains\Projects\Http\Requests\UpdateProjectRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::Project, McpLevel::Write)]
class UpdateProject extends Tool
{
    use UsesMcpContext;

    protected string $name = 'update_project';

    protected string $description = 'Changes the project\'s name, slug, issue prefix or maintenance settings, as the settings form does; pass only what changes. Allowed browser origins and scrubbed keys stay on the settings page, since they decide what data is kept. Returns the settings it saved.';

    public function __construct(
        private readonly UpdateProjectAction $action,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'name' => $schema->string(),
            'slug' => $schema->string()->description('Changing it changes every link to the project.'),
            'issue_prefix' => $schema->string()->description('2 to 8 letters or digits, starting with a letter, e.g. SHOP. Changes every issue reference such as SHOP-42.'),
            'maintenance_expected_minutes' => $schema->integer()->description('How long a maintenance window the app reports without an expected end is expected to take, 1 to 10080. Defaults to 30.'),
            'maintenance_repeat_minutes' => $schema->integer()->description('How often the maintenance overrun alert repeats while a window stays open past its expected end, 5 to 1440. Defaults to 30.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        if (($denied = $this->authorize('update', $project)) !== null) {
            return $denied;
        }

        $input = [
            'name' => $project->name,
            'slug' => $project->slug,
            'issue_prefix' => $project->issuePrefix(),
            'allowed_origins' => $project->allowed_origins ?? [],
            'scrub_keys' => $project->scrub_keys ?? [],
            'maintenance_expected_minutes' => $project->maintenance_expected_minutes,
            'maintenance_repeat_minutes' => $project->maintenance_repeat_minutes,
            ...array_filter($request->only(['name', 'slug', 'issue_prefix', 'maintenance_expected_minutes', 'maintenance_repeat_minutes']), fn (mixed $value): bool => $value !== null),
        ];
        $input['issue_prefix'] = strtoupper(trim((string) $input['issue_prefix']));

        $input = $request->merge($input)->validate(UpdateProjectRequest::rulesFor($project), ['issue_prefix.regex' => __('projects.issue_prefix_invalid')]);

        $project = $this->action->execute(
            $project,
            (string) $input['name'],
            (string) $input['slug'],
            array_values(array_map(strval(...), (array) $input['allowed_origins'])),
            array_values(array_map(strval(...), (array) $input['scrub_keys'])),
            (string) $input['issue_prefix'],
            (int) $input['maintenance_expected_minutes'],
            (int) $input['maintenance_repeat_minutes'],
        );

        return Response::json([
            'id' => $project->uuid,
            'name' => $project->name,
            'slug' => $project->slug,
            'issue_prefix' => $project->issuePrefix(),
            'allowed_origins' => $project->allowed_origins ?? [],
            'scrub_keys' => $project->scrub_keys ?? [],
            'maintenance_expected_minutes' => $project->maintenance_expected_minutes,
            'maintenance_repeat_minutes' => $project->maintenance_repeat_minutes,
        ]);
    }
}
