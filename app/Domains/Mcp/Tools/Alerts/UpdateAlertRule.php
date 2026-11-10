<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Actions\CustomizeAlertRuleAction;
use App\Domains\Alerts\Actions\UpdateAlertRuleAction;
use App\Domains\Alerts\Http\Requests\UpdateAlertRuleRequest;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAlertRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::Alerts, McpLevel::Write)]
class UpdateAlertRule extends Tool
{
    use UsesAlertRules;

    protected string $name = 'update_alert_rule';

    protected string $description = 'Sets one project\'s own rule for an alert type or custom topic, customizing it from the team\'s rule first when the project has none; the team\'s rule and other projects are unchanged. Pass the whole state: enabled, channel ids (see list_notification_channels) and environments. Returns the project\'s rule.';

    public function __construct(
        private readonly CustomizeAlertRuleAction $customize,
        private readonly UpdateAlertRuleAction $update,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            ...$this->ruleArguments($schema),
            'enabled' => $schema->boolean()->required(),
            'channels' => $schema->array()->items($schema->integer())->description('Channel ids: the team\'s shared ones and the project\'s own. Others are dropped.')->required(),
            'environments' => $schema->array()->items($schema->string())->description('Empty or omitted: every environment.'),
            'settings' => $schema->object()->description('Only for queue_backlog: {size, age_minutes, sustain_minutes}. Omit to keep them.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $team = $this->teamRule($request);

        if ($team instanceof Response) {
            return $team;
        }

        if (($refused = $this->authorize('update', $project)) !== null) {
            return $refused;
        }

        $input = $request->validate(UpdateAlertRuleRequest::fieldRules());
        $rule = $this->customize->execute($project, $team->type, $team->topic);
        $rule = $this->update->execute($rule, (bool) $input['enabled'], array_values(array_map(intval(...), $input['channels'])), $input['environments'] ?? null, $input['settings'] ?? null);

        return Response::json(['project' => $project->slug, 'rule' => $this->presentRule($rule)]);
    }
}
