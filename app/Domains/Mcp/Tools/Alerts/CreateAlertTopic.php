<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Actions\CreateAlertTopicAction;
use App\Domains\Alerts\Http\Requests\StoreAlertTopicRequest;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAlertRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Alerts, McpLevel::Write)]
class CreateAlertTopic extends Tool
{
    use UsesAlertRules;

    protected string $name = 'create_alert_topic';

    protected string $description = 'Creates a custom alert topic ahead of the first alert an app sends to it, on and without channels; pick them with update_team_alert_rule. The name is made a slug. Returns the topic.';

    public function __construct(
        private readonly CreateAlertTopicAction $create,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('E.g. payments or deploys.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = $this->context()->token()->team;

        if (($refused = $this->authorize('administer', $team)) !== null) {
            return $refused;
        }

        $input = $request->validate(StoreAlertTopicRequest::fieldRules());
        $rule = $this->create->execute($team, $input['name']);

        return $rule === null ? Response::error($this->create->reasons[0] ?? 'The topic could not be created.') : Response::json(['topic' => $this->presentRule($rule)]);
    }
}
