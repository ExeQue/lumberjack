<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Actions\SetAlertRuleAddOnsAction;
use App\Domains\Alerts\Http\Requests\UpdateAlertRuleAddOnsRequest;
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
class SetAlertRuleAddOns extends Tool
{
    use UsesAlertRules;

    protected string $name = 'set_alert_rule_add_ons';

    protected string $description = 'Sets the channels one project adds to a team rule it uses, without customizing the rule; they follow the team\'s rule being on or off. Pass every add-on channel id (see list_notification_channels); an empty list removes them. Returns the add-ons.';

    public function __construct(
        private readonly SetAlertRuleAddOnsAction $addOns,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            ...$this->ruleArguments($schema),
            'channels' => $schema->array()->items($schema->integer())->description('Channel ids: the team\'s shared ones and the project\'s own. Others are dropped.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $rule = $this->teamRule($request);

        if ($rule instanceof Response) {
            return $rule;
        }

        if ($project->alertRules()->whereType($rule->type)->where('topic', $rule->topic)->exists()) {
            return Response::error('The project has its own rule for this; change its channels with update_alert_rule, or reset_alert_rule first.');
        }

        if (($refused = $this->authorize('update', $project)) !== null) {
            return $refused;
        }

        $input = $request->validate(UpdateAlertRuleAddOnsRequest::fieldRules());
        $this->addOns->execute($project, $rule, array_values(array_map(intval(...), $input['channels'])));

        return Response::json(['project' => $project->slug, 'type' => $rule->type->value, 'topic' => $rule->topic !== '' ? $rule->topic : null, 'add_ons' => $rule->addOnsFor($project)->pluck('notification_channels.id')->all()]);
    }
}
