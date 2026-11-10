<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Projects;

use App\Domains\Logs\Actions\DeleteLogChannelAction;
use App\Domains\Logs\Exceptions\LogChannelExceptionInterface;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[RequiresPermission(McpArea::Project, McpLevel::Manage)]
class DeleteLogChannel extends Tool
{
    use UsesMcpContext;

    protected string $name = 'delete_log_channel';

    protected string $description = 'Deletes a log channel and every log line in it; this cannot be undone. The default channel cannot be deleted. See get_project_settings for channel ids.';

    public function __construct(
        private readonly DeleteLogChannelAction $action,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'channel_id' => $schema->integer()->description('The channel\'s id from get_project_settings.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $input = $request->validate(['channel_id' => ['required', 'integer']]);
        $channel = $project->logChannels()->find((int) $input['channel_id']);

        if (! $channel instanceof LogChannel) {
            return Response::error('Unknown log channel in this project; see get_project_settings.');
        }

        if (($denied = $this->authorize('update', $project)) !== null) {
            return $denied;
        }

        try {
            $this->action->execute($channel);
        } catch (LogChannelExceptionInterface $e) {
            return Response::error($e->getMessage());
        }

        return Response::json(['deleted' => true, 'id' => $channel->id, 'name' => $channel->name]);
    }
}
