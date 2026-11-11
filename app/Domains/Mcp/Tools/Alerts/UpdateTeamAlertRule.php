<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

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
#[RequiresPermission(McpArea::Alerts, McpLevel::Manage)]
class UpdateTeamAlertRule extends Tool
{
    use UsesAlertRules;

    protected string $name = 'update_team_alert_rule';

    protected string $description = 'Changes the team\'s rule for an alert type or custom topic, for every project that has not customized it. Pass the whole state: enabled, shared channel ids (see list_notification_channels) and environments. Returns the rule.';

    public function __construct(
        private readonly UpdateAlertRuleAction $update,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->ruleArguments($schema),
            'enabled' => $schema->boolean()->required(),
            'channels' => $schema->array()->items($schema->integer())->description('The team\'s shared channel ids. Others are dropped.')->required(),
            'environments' => $schema->array()->items($schema->string())->description('Empty or omitted: every environment.'),
            'settings' => $schema->object()->description('Only for queue_backlog: {size, age_minutes, sustain_minutes}; for weekly_summary: {day (1 is Monday, 7 Sunday), time (HH:MM, in the app time zone)}. Omit to keep them.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $rule = $this->teamRule($request);

        if ($rule instanceof Response) {
            return $rule;
        }

        if (($refused = $this->authorize('administer', $this->context()->token()->team)) !== null) {
            return $refused;
        }

        $input = $request->validate(UpdateAlertRuleRequest::fieldRules());
        $rule = $this->update->execute($rule, (bool) $input['enabled'], array_values(array_map(intval(...), $input['channels'])), $input['environments'] ?? null, $input['settings'] ?? null);

        return Response::json(['rule' => $this->presentRule($rule)]);
    }
}
