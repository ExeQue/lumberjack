<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Actions\SendTestAlertAction;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[RequiresPermission(McpArea::Alerts, McpLevel::Write)]
class SendTestAlert extends Tool
{
    use UsesMcpContext;

    protected string $name = 'send_test_alert';

    protected string $description = 'Sends a test message to one alert channel (see list_notification_channels) and says whether it was delivered, or why not.';

    public function __construct(
        private readonly SendTestAlertAction $test,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->integer()->description('The channel id.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $token = $this->context()->token();
        // A project's own channel only within the token's projects.
        $channel = NotificationChannel::query()->where('team_id', $token->team_id)
            ->where(fn ($q) => $q->whereTeamWide()->orWhereIn('project_id', $this->projects()->modelKeys()))
            ->find((int) $request->get('channel'));

        if ($channel === null) {
            return Response::error('Unknown channel; see list_notification_channels.');
        }

        if (($refused = $this->authorize('administer', $channel->team)) !== null) {
            return $refused;
        }

        $failure = $this->test->execute($channel);

        return $failure === null
            ? Response::json(['channel' => $channel->id, 'delivered' => true])
            : Response::error('The test was not delivered: '.$failure);
    }
}
