<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Issues;

use App\Domains\Issues\Actions\DeleteLabelAction;
use App\Domains\Issues\Models\Label;
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
#[RequiresPermission(McpArea::Issues, McpLevel::Manage)]
class DeleteLabel extends Tool
{
    use UsesMcpContext;

    protected string $name = 'delete_label';

    protected string $description = 'Deletes one of the project\'s labels (see list_labels) and takes it off every issue that had it. The issues themselves stay.';

    public function __construct(
        private readonly DeleteLabelAction $action,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'label_id' => $schema->integer()->description('The label\'s id from list_labels.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $input = $request->validate(['label_id' => ['required', 'integer']]);
        $label = $project->labels()->find((int) $input['label_id']);

        if (! $label instanceof Label) {
            return Response::error('Unknown label in this project; see list_labels.');
        }

        if (($denied = $this->authorize('update', $project)) !== null) {
            return $denied;
        }

        $this->action->execute($label);

        return Response::json(['deleted' => true, 'id' => $label->id, 'name' => $label->name]);
    }
}
