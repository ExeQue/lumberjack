<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Actions\ResetAlertRuleAction;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAlertRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Alerts, McpLevel::Write)]
class ResetAlertRule extends Tool
{
    use UsesAlertRules;

    protected string $name = 'reset_alert_rule';

    protected string $description = 'Drops a project\'s own rule for an alert type or custom topic, so the project follows the team\'s rule again. Its channels the team\'s rule does not use stay on as add-ons. Returns the team\'s rule.';

    public function __construct(
        private readonly ResetAlertRuleAction $reset,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            ...$this->ruleArguments($schema),
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

        $own = $project->alertRules()->whereType($team->type)->where('topic', $team->topic)->first();

        if ($own === null) {
            return Response::error('The project uses the team\'s rule for this already; nothing to reset.');
        }

        if (($refused = $this->authorize('update', $project)) !== null) {
            return $refused;
        }

        $this->reset->execute($own);

        return Response::json(['project' => $project->slug, 'team_rule' => $this->presentRule($team), 'add_ons' => $team->addOnsFor($project)->pluck('notification_channels.id')->all()]);
    }
}
