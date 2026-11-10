<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Projects;

use App\Domains\Logs\Actions\CreateLogChannelAction;
use App\Domains\Logs\Http\Requests\StoreLogChannelRequest;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Project, McpLevel::Write)]
class CreateLogChannel extends Tool
{
    use UsesMcpContext;

    protected string $name = 'create_log_channel';

    protected string $description = 'Adds a log channel to the project, so log lines the app sends to that channel name are kept apart from default from now on. Earlier lines stay in default.';

    public function __construct(
        private readonly CreateLogChannelAction $action,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'name' => $schema->string()->description('Kebab-case, e.g. payments; unique in the project and not "default".')->required(),
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

        $input = $request->validate(StoreLogChannelRequest::rulesFor($project), ['name.regex' => __('logs.validation.kebab')]);
        $channel = $this->action->execute($project, (string) $input['name']);

        return Response::json(['id' => $channel->id, 'name' => $channel->name]);
    }
}
