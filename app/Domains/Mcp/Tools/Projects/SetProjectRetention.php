<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Projects;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Retention\Actions\SaveProjectRetentionAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Http\Requests\UpdateRetentionRequest;
use App\Domains\Retention\Models\RetentionPolicy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * The project's retention table, saved as PUT projects/{project}/settings/retention saves
 * it, with the same caps. Only the data types and channels named change; the tool fills
 * in the rest from what the project stores, because the page always sends the whole table.
 */
#[IsDestructive]
#[RequiresPermission(McpArea::Project, McpLevel::Manage)]
class SetProjectRetention extends Tool
{
    use UsesMcpContext;

    protected string $name = 'set_project_retention';

    protected string $description = 'Sets how many days the project keeps each data type (see get_project_settings for types, current and capped days); shorter retention deletes older data at the next cleanup. Only the types and channels you pass change; null days inherits from the team again.';

    public function __construct(
        private readonly SaveProjectRetentionAction $action,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'days' => $schema->object()->description('Days by data type, e.g. {"requests": 14, "logs": null}. Null inherits.'),
            'forever' => $schema->array()->items($schema->string()->enum(array_map(fn (RetentionCategory $c): string => $c->value, RetentionCategory::forTeams())))->description('Data types to keep forever, where the team allows it.'),
            'channels' => $schema->object()->description('Log retention days by log channel id, e.g. {"12": 30}. Null inherits.'),
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

        $request->validate(['days' => ['sometimes', 'array'], 'forever' => ['sometimes', 'array'], 'channels' => ['sometimes', 'array']]);
        $changedDays = (array) $request->get('days', []);
        $changedForever = array_values(array_filter((array) $request->get('forever', []), is_string(...)));

        $days = [];
        $forever = [];

        foreach (RetentionPolicy::query()->whereProject($project->id)->whereNull('job_class')->whereNull('log_channel_id')->get() as $policy) {
            if ($policy->days === null) {
                $forever[] = $policy->category->value;
            } else {
                $days[$policy->category->value] = $policy->days;
            }
        }

        $days = [...$days, ...$changedDays];
        $forever = [...array_diff($forever, array_keys($changedDays)), ...$changedForever];
        $input = array_filter([
            'days' => $days,
            'forever' => array_values(array_unique($forever)),
            'channels' => $request->get('channels'),
        ], fn (mixed $value): bool => $value !== null);

        UpdateRetentionRequest::forProject($project, $input)->validateResolved();

        $this->action->execute($project, $days, $input['forever'], channels: isset($input['channels']) ? (array) $input['channels'] : null);

        return Response::json(['saved' => true]);
    }
}
