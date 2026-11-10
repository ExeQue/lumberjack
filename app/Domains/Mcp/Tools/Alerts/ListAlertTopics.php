<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Presenters\CustomAlertPresenter;
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
class ListAlertTopics extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_alert_topics';

    protected string $description = 'The team\'s custom alert topics, default first: on or off, channel ids, environments, how many alerts came in over the last day and when the latest did. Apps name a topic when they send an alert.';

    public function __construct(
        private readonly CustomAlertPresenter $customAlerts,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::json(['topics' => $this->customAlerts->topics($this->context()->token()->team)]);
    }
}
