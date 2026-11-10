<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Issues;

use App\Domains\Issues\Actions\UpdateLabelAction;
use App\Domains\Issues\Enums\LabelColor;
use App\Domains\Issues\Exceptions\IssueExceptionInterface;
use App\Domains\Issues\Http\Requests\UpdateLabelRequest;
use App\Domains\Issues\Models\Label;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::Issues, McpLevel::Manage)]
class UpdateLabel extends Tool
{
    use UsesMcpContext;

    protected string $name = 'update_label';

    protected string $description = 'Renames or recolors one of the project\'s labels (see list_labels); every issue carrying it shows the change.';

    public function __construct(
        private readonly UpdateLabelAction $action,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'label_id' => $schema->integer()->description('The label\'s id from list_labels.')->required(),
            'name' => $schema->string()->description('The new name; pass the current one to only recolor.')->required(),
            'color' => $schema->string()->enum(array_map(fn (LabelColor $color): string => $color->value, LabelColor::cases()))->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $input = $request->validate([...(new UpdateLabelRequest())->rules(), 'label_id' => ['required', 'integer']]);
        $label = $project->labels()->find((int) $input['label_id']);

        if (! $label instanceof Label) {
            return Response::error('Unknown label in this project; see list_labels.');
        }

        if (($denied = $this->authorize('update', $project)) !== null) {
            return $denied;
        }

        try {
            $label = $this->action->execute($label, (string) $input['name'], LabelColor::from((string) $input['color']));
        } catch (IssueExceptionInterface $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($label->toSummary());
    }
}
