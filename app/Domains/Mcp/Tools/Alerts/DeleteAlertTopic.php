<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Actions\DeleteAlertTopicAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAlertRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[RequiresPermission(McpArea::Alerts, McpLevel::Manage)]
class DeleteAlertTopic extends Tool
{
    use UsesAlertRules;

    protected string $name = 'delete_alert_topic';

    protected string $description = 'Deletes a custom alert topic and every project\'s version of it; its sent alerts stay, and an app sending to it again brings it back without channels. The default topic cannot be deleted.';

    public function __construct(
        private readonly DeleteAlertTopicAction $delete,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->description('See list_alert_topics.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $rule = $this->teamRule($request, AlertRuleType::CustomAlert);

        if ($rule instanceof Response) {
            return $rule;
        }

        if (($refused = $this->authorize('administer', $this->context()->token()->team)) !== null) {
            return $refused;
        }

        return $this->delete->execute($rule)
            ? Response::json(['deleted' => $rule->topic])
            : Response::error($this->delete->reasons[0] ?? 'The topic could not be deleted.');
    }
}
