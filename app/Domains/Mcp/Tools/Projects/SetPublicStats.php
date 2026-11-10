<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Projects;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Projects\Actions\SetPublicStatsAction;
use App\Domains\Projects\Http\Requests\UpdatePublicStatsRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * Public stats are a link anyone can open, so the tool makes data public. It never
 * returns the link or its key; a person copies it from the settings page.
 */
#[IsIdempotent]
#[RequiresPermission(McpArea::Project, McpLevel::Manage, makesPublic: true)]
class SetPublicStats extends Tool
{
    use UsesMcpContext;

    protected string $name = 'set_public_stats';

    protected string $description = 'Turns the project\'s public stats link on or off, and whether it includes issue titles. Turning it off stops the link at once. The link itself is only shown on the settings page.';

    public function __construct(
        private readonly SetPublicStatsAction $action,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'enabled' => $schema->boolean()->required(),
            'include_titles' => $schema->boolean()->description('Show issue titles in the public stats. Defaults to false.'),
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

        $input = $request->validate((new UpdatePublicStatsRequest())->rules());
        /** @var User $user */
        $user = $this->context()->token()->user;
        $project = $this->action->execute($project, $user, (bool) $input['enabled'], (bool) ($input['include_titles'] ?? false));

        return Response::json(['enabled' => $project->stats_key !== null, 'include_titles' => $project->stats_include_titles]);
    }
}
