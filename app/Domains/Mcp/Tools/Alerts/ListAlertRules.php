<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Presenters\AlertRulePresenter;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Alerts, McpLevel::Read)]
class ListAlertRules extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_alert_rules';

    protected string $description = 'The team\'s alert rules (on or off, channel ids, environments, queue backlog thresholds). Pass a project to get, per type and custom topic, the team\'s rule, the channels the project adds to it (add_ons) and the project\'s own rule when it customized one, which then counts instead; see list_notification_channels for the channel ids.';

    public function __construct(
        private readonly AlertRulePresenter $rules,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug. Omit for the team\'s rules only.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ((string) $request->get('project', '') === '') {
            return Response::json(['rules' => $this->rules->forTeam($this->context()->token()->team)]);
        }

        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        // The project's alert settings are shown to whoever may change the project.
        return $this->authorize('update', $project) ?? Response::json(['project' => $project->slug, 'rules' => $this->rules->forProject($project)]);
    }
}
